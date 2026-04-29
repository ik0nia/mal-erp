<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\EmailMessage;
use App\Models\ProductSupplier;
use App\Models\WooProduct;
use App\Services\Ai\ClaudeAiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;

class ParseCemaconOrdersCommand extends Command
{
    protected $signature = 'cemacon:parse-orders
                            {--limit=50 : Număr maxim de emailuri de procesat}
                            {--dry-run  : Afișează mapping-ul fără a salva}
                            {--force    : Reprocesează emailuri deja extrase}';

    protected $description = 'Extrage coduri produse Cemacon din PDF-urile "Comanda confirmata" și le asociază cu produsele noastre';

    private const CEMACON_SUPPLIER_ID = 11;

    // Coduri deja cunoscute (din sesiunea anterioară)
    private array $knownMappings = [
        '13115V4'   => '9007912540274',
        '13129SN4'  => '9007912540281',
        'EP612NF-L' => '9007912545316',
    ];

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $force  = $this->option('force');

        $this->info("Cemacon order parser — limit={$limit}" . ($dryRun ? ' [DRY RUN]' : ''));

        // Preîncărcăm mapping-urile existente din product_suppliers
        $this->loadExistingMappings();

        $emails = EmailMessage::where('from_email', 'noreply@cemacon.ro')
            ->where('subject', 'like', '%Comanda confirmata%')
            ->when(! $force, function ($q) {
                // Sărim peste cele care au deja toate produsele mapate
                // (nu avem un flag per email, procesăm toate)
            })
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get(['id', 'imap_uid', 'imap_folder', 'sent_at', 'attachments']);

        $this->info("Emailuri de procesat: {$emails->count()}");

        $allProducts   = collect(); // cod_cemacon => {cod, denumire, sku_nostru, confidence}
        $totalCost     = 0.0;
        $processed     = 0;
        $errors        = 0;

        foreach ($emails as $email) {
            $att = is_array($email->attachments)
                ? $email->attachments
                : (json_decode($email->attachments, true) ?? []);

            $pdfAtt = collect($att)->first(fn ($a) =>
                str_ends_with(strtolower($a['name'] ?? ''), '.pdf')
            );

            if (! $pdfAtt) {
                continue;
            }

            $this->line("  → Email #{$email->id} ({$email->sent_at})");

            try {
                $pdfContent = $this->downloadAttachment($email, 0);
                if (! $pdfContent) {
                    $this->warn("    Download eșuat");
                    $errors++;
                    continue;
                }

                $extracted = $this->extractWithClaude($pdfContent);
                $totalCost += $extracted['cost_usd'];
                $processed++;

                $order = $extracted['order'];
                if (! $order) {
                    $this->warn("    Nu e comandă Cemacon valabilă");
                    continue;
                }

                // Salvăm comanda completă
                if (! $dryRun && $order['numar_comanda']) {
                    DB::table('cemacon_orders')->updateOrInsert(
                        ['numar_comanda' => $order['numar_comanda']],
                        [
                            'email_message_id' => $email->id,
                            'data_comanda'     => $order['data'],
                            'gestiune'         => $order['gestiune'] ?? null,
                            'produse'          => json_encode($order['produse']),
                            'total'            => $order['total'] ?? null,
                            'updated_at'       => now(),
                            'created_at'       => now(),
                        ]
                    );
                }

                // Colectăm codurile unice (fără paleți)
                foreach ($order['produse'] as $prod) {
                    $cod = $prod['cod'] ?? null;
                    if (! $cod || strtolower($cod) === 'n/a') continue;
                    if (str_contains(strtolower($prod['denumire'] ?? ''), 'palet')) continue;

                    if (! $allProducts->has($cod)) {
                        $allProducts->put($cod, [
                            'cod'        => $cod,
                            'denumire'   => $prod['denumire'],
                            'sku'        => null,
                            'ps_id'      => null,
                            'confidence' => 'none',
                            'count'      => 0,
                        ]);
                    }

                    $entry = $allProducts->get($cod);
                    $entry['count']++;
                    $allProducts->put($cod, $entry);
                }

                $this->line("    #{$order['numar_comanda']} | " . count($order['produse']) . " produse | cost $" . number_format($extracted['cost_usd'], 4));

            } catch (\Throwable $e) {
                $this->warn("    Eroare: " . $e->getMessage());
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Extragere completă: {$processed} emailuri, cost total $" . number_format($totalCost, 4));
        $this->newLine();

        // Matching
        $this->info("=== Matching produse ===");
        $this->matchProducts($allProducts);

        // Afișare rezultate
        $confirmed = $allProducts->where('confidence', 'high');
        $uncertain = $allProducts->where('confidence', 'none');

        $this->newLine();
        $this->info("✅ Mapate automat ({$confirmed->count()}):");
        foreach ($confirmed as $prod) {
            $this->line("  [{$prod['cod']}] {$prod['denumire']} → {$prod['sku']}");
        }

        if ($uncertain->isNotEmpty()) {
            $this->newLine();
            $this->warn("⚠️  Nemapate ({$uncertain->count()}) — necesită confirmare manuală:");
            foreach ($uncertain as $prod) {
                $this->line("  [{$prod['cod']}] {$prod['denumire']} (apare de {$prod['count']}x)");
            }
        }

        // Salvare
        if (! $dryRun) {
            $saved = 0;
            foreach ($allProducts as $prod) {
                // Salvăm în cemacon_product_codes (toate, inclusiv nemapate)
                DB::table('cemacon_product_codes')->updateOrInsert(
                    ['cod_cemacon' => $prod['cod']],
                    [
                        'denumire_cemacon'   => $prod['denumire'],
                        'woo_product_id'     => $prod['sku'] ? WooProduct::where('sku', $prod['sku'])->value('id') : null,
                        'product_supplier_id'=> $prod['ps_id'],
                        'confidence'         => $prod['confidence'],
                        'updated_at'         => now(),
                        'created_at'         => now(),
                    ]
                );

                // Actualizăm product_suppliers pentru cele confirmate
                if ($prod['confidence'] === 'high' && $prod['ps_id']) {
                    $existing = ProductSupplier::find($prod['ps_id']);
                    if (! $existing?->supplier_sku) {
                        $existing->update([
                            'supplier_sku'          => $prod['cod'],
                            'supplier_product_name' => $prod['denumire'],
                        ]);
                        $saved++;
                    }
                }
            }
            $this->newLine();
            $this->info("Salvate în cemacon_product_codes: " . $allProducts->count() . " coduri | {$saved} asocieri noi pe product_suppliers.");
        }

        return self::SUCCESS;
    }

    private function loadExistingMappings(): void
    {
        // Preîncărcăm și ce avem deja în DB
        $existing = ProductSupplier::where('supplier_id', self::CEMACON_SUPPLIER_ID)
            ->whereNotNull('supplier_sku')
            ->with('product')
            ->get(['id', 'woo_product_id', 'supplier_sku', 'supplier_product_name']);

        foreach ($existing as $ps) {
            if ($ps->supplier_sku && $ps->product?->sku) {
                $this->knownMappings[$ps->supplier_sku] = $ps->product->sku;
            }
        }

        $this->line("Mappings existente încărcate: " . count($this->knownMappings));
    }

    private function extractWithClaude(string $pdfContent): array
    {
        $ai     = new ClaudeAiClient();
        $client = $ai->rawClient();

        $response = $client->messages->create(
            model:     ClaudeAiClient::MODEL_HAIKU,
            maxTokens: 512,
            messages:  [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => 'document',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => 'application/pdf',
                            'data'       => base64_encode($pdfContent),
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Din acest PDF "Comanda de livrare" Cemacon extrage toate datele. Returnează JSON cu structura exactă: {"numar_comanda":"...","data":"YYYY-MM-DD","gestiune":"...","produse":[{"cod":"...","denumire":"...","cantitate_bucati":0,"nr_paleti":0,"pret_unitar":0.00,"valoare":0.00}]}. Ignoră liniile cu "PALET" în denumire. Dacă nu e comandă Cemacon returnează null.',
                    ],
                ],
            ]],
        );

        $text = '';
        foreach ($response->content as $block) {
            if (isset($block->text)) $text .= $block->text;
        }

        $inputTokens  = $response->usage->inputTokens ?? 0;
        $outputTokens = $response->usage->outputTokens ?? 0;
        $costUsd      = ClaudeAiClient::calculateCost(ClaudeAiClient::MODEL_HAIKU, $inputTokens, $outputTokens);

        // Parsăm JSON din răspuns
        $order = null;
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if ($decoded && isset($decoded['numar_comanda'])) {
                $order = [
                    'numar_comanda' => $decoded['numar_comanda'] ?? null,
                    'data'          => $decoded['data'] ?? null,
                    'gestiune'      => $decoded['gestiune'] ?? null,
                    'produse'       => $decoded['produse'] ?? [],
                    'total'         => $decoded['total'] ?? null,
                ];
            }
        }

        return ['order' => $order, 'cost_usd' => $costUsd];
    }

    private function matchProducts(\Illuminate\Support\Collection &$allProducts): void
    {
        // Preîncărcăm produsele Cemacon din WM intrari (sku → den_articol)
        $wmProducts = DB::table('winmentor_intrari_raw')
            ->where('firma', 'MAL2019')
            ->whereIn('part_id', ['0000000015769', '728018036', 'RO10403965'])
            ->whereNotNull('sku')
            ->whereNotNull('den_articol')
            ->select('sku', 'den_articol')
            ->distinct()
            ->get()
            ->keyBy('sku');

        // Preîncărcăm woo_products pentru Cemacon
        $cemaconProductIds = ProductSupplier::where('supplier_id', self::CEMACON_SUPPLIER_ID)
            ->pluck('woo_product_id');
        $wooProducts = WooProduct::whereIn('id', $cemaconProductIds)
            ->get(['id', 'sku', 'name'])
            ->keyBy('sku');

        foreach ($allProducts as $cod => $prod) {
            // 1. Mapping cunoscut (din DB sau hardcodat)
            if (isset($this->knownMappings[$cod])) {
                $sku = $this->knownMappings[$cod];
                $ps  = ProductSupplier::where('supplier_id', self::CEMACON_SUPPLIER_ID)
                    ->whereHas('product', fn ($q) => $q->where('sku', $sku))
                    ->first();

                $prod['sku']        = $sku;
                $prod['ps_id']      = $ps?->id;
                $prod['confidence'] = 'high';
                $allProducts->put($cod, $prod);
                continue;
            }

            // 2. Match pe denumire vs WM intrari (cuvinte cheie din cod Cemacon)
            $bestSku   = null;
            $bestScore = 0;

            // Extragem dimensiunile din denumirea Cemacon (ex: 495X120X238)
            preg_match_all('/\d{2,3}[Xx\/]\d{2,3}/', $prod['denumire'], $dims);
            $dimsFlat = strtolower(implode(' ', $dims[0] ?? []));

            // Extragem cuvinte cheie
            $keywords = collect(explode(' ', $prod['denumire']))
                ->map(fn ($w) => strtolower(trim($w)))
                ->filter(fn ($w) => strlen($w) > 3)
                ->values();

            foreach ($wmProducts as $sku => $wm) {
                $wmName = strtolower($wm->den_articol);
                $score  = 0;

                // Dimensiuni exacte = scor mare
                if ($dimsFlat && str_contains($wmName, str_replace(['x', '/'], 'x', $dimsFlat))) {
                    $score += 10;
                }

                // Cuvinte cheie comune
                foreach ($keywords as $kw) {
                    if (str_contains($wmName, $kw)) $score++;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestSku   = $sku;
                }
            }

            if ($bestSku && $bestScore >= 5) {
                $ps = ProductSupplier::where('supplier_id', self::CEMACON_SUPPLIER_ID)
                    ->whereHas('product', fn ($q) => $q->where('sku', $bestSku))
                    ->first();

                $prod['sku']        = $bestSku;
                $prod['ps_id']      = $ps?->id;
                $prod['confidence'] = $bestScore >= 8 ? 'high' : 'medium';
                $this->line("  [{$cod}] {$prod['denumire']} → {$bestSku} (score={$bestScore})");
            }

            $allProducts->put($cod, $prod);
        }
    }

    private function downloadAttachment(EmailMessage $email, int $index): ?string
    {
        $client = (new ClientManager())->make([
            'host'          => AppSetting::get(AppSetting::KEY_IMAP_HOST),
            'port'          => (int) AppSetting::get(AppSetting::KEY_IMAP_PORT, '993'),
            'encryption'    => AppSetting::get(AppSetting::KEY_IMAP_ENCRYPTION, 'ssl'),
            'validate_cert' => ! app()->isLocal(),
            'username'      => AppSetting::get(AppSetting::KEY_IMAP_USERNAME),
            'password'      => AppSetting::getEncrypted(AppSetting::KEY_IMAP_PASSWORD),
            'protocol'      => 'imap',
        ]);
        $client->connect();

        try {
            $folder = $client->getFolder($email->imap_folder);
            if (! $folder) return null;

            $msg = $folder->messages()
                ->whereUid((int) $email->imap_uid)
                ->leaveUnread()
                ->get()
                ->first();

            if (! $msg) return null;

            $att = $msg->getAttachments()->values()->get($index);
            return $att?->getContent() ?: null;

        } finally {
            try { $client->disconnect(); } catch (\Throwable) {}
        }
    }
}
