<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\EmailMessage;
use App\Models\EmailParsedDocument;
use App\Models\Supplier;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Webklex\PHPIMAP\ClientManager;

class ParseEmailAttachmentJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 2;

    public function __construct(
        private readonly int $emailMessageId
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $email = EmailMessage::find($this->emailMessageId);
        if (! $email) {
            return;
        }

        $attachments = is_array($email->attachments)
            ? $email->attachments
            : json_decode($email->attachments, true) ?? [];

        // ── Procesare atașamente PDF + XLSX ─────────────────────────────────
        foreach ($attachments as $index => $att) {
            $mime = strtolower($att['mime_type'] ?? '');
            $name = $att['name'] ?? '';

            $isPdf  = str_ends_with(strtolower($name), '.pdf') || str_contains($mime, 'pdf');
            $isXlsx = preg_match('/\.(xlsx|xls|ods)$/i', $name) || str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel');

            if (! $isPdf && ! $isXlsx) {
                continue;
            }

            // Skip dacă deja procesat
            $exists = EmailParsedDocument::where('email_message_id', $email->id)
                ->where('attachment_index', $index)
                ->where('source_type', $isPdf ? 'pdf' : 'xlsx')
                ->whereIn('processing_status', ['done', 'processing'])
                ->exists();
            if ($exists) {
                continue;
            }

            $record = EmailParsedDocument::create([
                'email_message_id'  => $email->id,
                'supplier_id'       => $email->supplier_id,
                'attachment_name'   => $name,
                'attachment_index'  => $index,
                'source_type'       => $isPdf ? 'pdf' : 'xlsx',
                'processing_status' => 'processing',
            ]);

            try {
                $content = $this->downloadAttachment($email, $index);
                if (! $content) {
                    $record->update(['processing_status' => 'error', 'error_message' => 'Download eșuat']);
                    continue;
                }

                // Sanitizăm numele fișierului pentru stocarea locală
                $safeName  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
                $localKey  = "email-attachments/{$email->id}_{$index}_{$safeName}";
                $localPath = storage_path("app/{$localKey}");
                Storage::makeDirectory('email-attachments');
                file_put_contents($localPath, $content);

                $text = $isPdf
                    ? $this->extractPdfText($localPath)
                    : $this->extractXlsxText($localPath);

                // Fallback: dacă PDF-ul e image-based (scanat), notăm dar nu blocăm
                if (blank($text) && $isPdf) {
                    // Încercăm să extragem metadate din PDF ca fallback
                    $text = $this->extractPdfMetadata($localPath, $name, $email->subject);
                }

                if (blank($text)) {
                    $record->update([
                        'processing_status' => 'error',
                        'error_message'     => 'PDF image-based (scanat) sau text neextractabil — necesită OCR',
                    ]);
                    continue;
                }

                $parsed   = $this->parseWithClaude($text, $email, $name);
                $products = $parsed['produse'] ?? $parsed['products'] ?? [];
                $docType  = $this->detectDocType($parsed, $email->subject);
                $docDate  = $this->parseDate($parsed['data'] ?? $parsed['date'] ?? null);

                $record->update([
                    'doc_type'    => $docType,
                    'doc_number'  => $parsed['numar_document'] ?? $parsed['numar_aviz'] ?? $parsed['numar_factura'] ?? $parsed['doc_number'] ?? null,
                    'doc_date'    => $docDate,
                    'parsed_data' => $parsed,
                    'products'    => $products,
                ]);

                // ── Matching cu WinMentor (SKU + cantitate + AI denumiri) ────
                if (in_array($docType, ['aviz', 'factura', 'comanda']) && ! empty($products) && $docDate) {
                    app(\App\Services\SupplierDocMatcherService::class)->match($record);
                }

                $record->update(['processing_status' => 'done']);

            } catch (\Throwable $e) {
                Log::warning("ParseEmailAttachmentJob: email #{$email->id} att#{$index}: " . $e->getMessage());
                $record->update([
                    'processing_status' => 'error',
                    'error_message'     => substr($e->getMessage(), 0, 500),
                ]);
            } finally {
                // Curățăm fișierul temporar
                $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
                Storage::delete("email-attachments/{$email->id}_{$index}_{$safeName}");
            }
        }

        // ── Procesare body text dacă nu are atașamente relevante ────────────
        $hasProcessed = EmailParsedDocument::where('email_message_id', $email->id)
            ->where('processing_status', 'done')
            ->exists();

        if (! $hasProcessed) {
            $body = strip_tags($email->body_html ?: $email->body_text ?: '');
            if (strlen($body) > 200 && $this->bodyLooksRelevant($email->subject, $body)) {
                $this->processBodyText($email, $body);
            }
        }
    }

    // ── Download din IMAP ──────────────────────────────────────────────────

    private function downloadAttachment(EmailMessage $email, int $index): ?string
    {
        $host       = AppSetting::get(AppSetting::KEY_IMAP_HOST);
        $port       = (int) AppSetting::get(AppSetting::KEY_IMAP_PORT, '993');
        $encryption = AppSetting::get(AppSetting::KEY_IMAP_ENCRYPTION, 'ssl');
        $username   = AppSetting::get(AppSetting::KEY_IMAP_USERNAME);
        $password   = AppSetting::getEncrypted(AppSetting::KEY_IMAP_PASSWORD);

        $client = (new ClientManager())->make([
            'host'          => $host,
            'port'          => $port,
            'encryption'    => $encryption,
            'validate_cert' => ! app()->isLocal(),
            'username'      => $username,
            'password'      => $password,
            'protocol'      => 'imap',
        ]);
        $client->connect();

        try {
            $folder = $client->getFolder($email->imap_folder);
            if (! $folder) {
                return null;
            }

            $msg = $folder->messages()
                ->whereUid((int) $email->imap_uid)
                ->leaveUnread()
                ->get()
                ->first();

            if (! $msg) {
                return null;
            }

            // Webklex returnează o colecție — folosim values() pentru index numeric sigur
            $atts = $msg->getAttachments()->values();
            $att  = $atts->get($index);

            if (! $att) {
                Log::warning("ParseEmailAttachmentJob: att index {$index} not found, total atts: " . $atts->count());
                return null;
            }

            $content = $att->getContent();
            return $content ?: null;

        } finally {
            try { $client->disconnect(); } catch (\Throwable) {}
        }
    }

    // ── Extragere text ─────────────────────────────────────────────────────

    private function extractPdfText(string $path): string
    {
        try {
            $parser = new PdfParser();
            $pdf    = $parser->parseFile($path);
            $text   = trim($pdf->getText());

            // Dacă textul e prea scurt, poate fi un PDF cu text embedded prost
            // Încercăm pagina cu pagina
            if (strlen($text) < 50) {
                $pages = $pdf->getPages();
                $parts = [];
                foreach ($pages as $page) {
                    $parts[] = trim($page->getText());
                }
                $text = implode("\n", array_filter($parts));
            }

            return $text;
        } catch (\Throwable $e) {
            Log::warning("ParseEmailAttachmentJob: PDF parse error: " . $e->getMessage());
            return '';
        }
    }

    private function extractPdfMetadata(string $path, string $filename, string $subject): string
    {
        // Extragem ce putem din numele fișierului și subiect
        // pentru PDF-uri scanate care nu au text
        $info = "Fișier: {$filename}\nSubiect email: {$subject}\n";

        // Încercăm să citim metadatele PDF
        try {
            $parser = new PdfParser();
            $pdf    = $parser->parseFile($path);
            $details = $pdf->getDetails();
            if (! empty($details)) {
                foreach ($details as $key => $val) {
                    if (is_string($val)) {
                        $info .= "{$key}: {$val}\n";
                    }
                }
            }
        } catch (\Throwable) {}

        return strlen($info) > 30 ? $info : '';
    }

    private function extractXlsxText(string $path): string
    {
        try {
            $spreadsheet = IOFactory::load($path);
            $lines       = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $lines[] = '=== Sheet: ' . $sheet->getTitle() . ' ===';
                foreach ($sheet->toArray() as $row) {
                    $cells = array_filter(array_map('trim', $row), fn($v) => $v !== '');
                    if (! empty($cells)) {
                        $lines[] = implode(' | ', $cells);
                    }
                }
            }

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            Log::warning("ParseEmailAttachmentJob: XLSX parse error: " . $e->getMessage());
            return '';
        }
    }

    // ── Parsare cu Claude ──────────────────────────────────────────────────

    private function parseWithClaude(string $text, EmailMessage $email, string $filename): array
    {
        $supplier = $email->supplier_id
            ? Supplier::find($email->supplier_id)?->name ?? 'necunoscut'
            : 'necunoscut';

        // Limităm textul la 8000 caractere pentru eficiență
        $text = mb_substr($text, 0, 8000);

        $prompt = <<<PROMPT
Ești un asistent ERP. Analizează documentul furnizorului "{$supplier}" și extrage datele în JSON.

Câmpuri obligatorii:
- tip_document: "aviz" | "factura" | "comanda" | "lista_preturi" | "other"
- numar_document: numărul documentului (aviz/factură/comandă)
- data: data documentului în format DD.MM.YYYY
- furnizor: numele furnizorului
- client: numele clientului/destinatarului
- produse: array de obiecte cu:
  - cod_furnizor: codul intern al furnizorului
  - gtin: codul de bare EAN/GTIN (dacă există)
  - denumire: denumirea produsului
  - cantitate: numărul (float)
  - unitate: unitatea de măsură
  - pret_unitar: prețul unitar (null dacă lipsește)
  - moneda: RON/EUR/USD (null dacă lipsește)

Returnează DOAR JSON valid, fără explicații.

DOCUMENT (fișier: {$filename}):
{$text}
PROMPT;

        $apiKey = AppSetting::get('anthropic_api_key') ?: env('ANTHROPIC_API_KEY');

        $response = Http::timeout(60)->withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 2048,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $raw = $response->json('content.0.text', '{}');

        // Curățăm markdown code blocks dacă există
        $raw = preg_replace('/^```json\s*/i', '', trim($raw));
        $raw = preg_replace('/\s*```$/', '', $raw);

        return json_decode($raw, true) ?? [];
    }

    // ── Matching WinMentor ─────────────────────────────────────────────────

    private function matchWithWinmentor(EmailParsedDocument $record, ?int $supplierId): void
    {
        if (! $supplierId) {
            $record->update(['match_status' => 'unmatched']);
            return;
        }

        $supplier = Supplier::find($supplierId);
        if (! $supplier?->winmentor_id) {
            $record->update(['match_status' => 'no_entry']);
            return;
        }

        $docDate = $record->doc_date;

        // Căutăm intrări WinMentor ±3 zile față de data documentului
        $wmEntries = \DB::table('winmentor_intrari_raw')
            ->where('part_id', $supplier->winmentor_id)
            ->whereBetween('data_intrare', [
                $docDate->copy()->subDays(3)->toDateString(),
                $docDate->copy()->addDays(3)->toDateString(),
            ])
            ->get(['nr_doc', 'sku', 'cantitate', 'uom', 'data_intrare']);

        if ($wmEntries->isEmpty()) {
            $record->update(['match_status' => 'no_entry']);
            return;
        }

        $wmByDoc  = $wmEntries->groupBy('nr_doc');
        $products = $record->products ?? [];

        if (empty($products)) {
            $record->update(['match_status' => 'no_entry']);
            return;
        }

        // Index SKU → produs ERP (pentru denumiri și prețuri)
        $productBySkuRaw = \DB::table('woo_products')
            ->whereNotNull('sku')
            ->get(['id', 'sku', 'name', 'winmentor_name'])
            ->keyBy(fn($p) => ltrim($p->sku, '0'));

        // ── PASS 1: match prin SKU/GTIN exact ─────────────────────────────
        $bestDocSku   = null;
        $bestScoreSku = 0;

        foreach ($wmByDoc as $docNr => $wmRows) {
            $wmSkus = $wmRows->keyBy(fn($r) => ltrim($r->sku, '0'));
            $score  = 0;
            foreach ($products as $prod) {
                $gtin = ltrim($prod['gtin'] ?? '', '0');
                $cod  = ltrim($prod['cod_furnizor'] ?? '', '0');
                if (($gtin && $wmSkus->has($gtin)) || ($cod && $wmSkus->has($cod))) {
                    $score++;
                }
            }
            if ($score > $bestScoreSku) {
                $bestScoreSku = $score;
                $bestDocSku   = $docNr;
            }
        }

        // ── PASS 2: fallback pe cantități când SKU-urile diferă ───────────
        // (ex: Baumit folosește GTIN RO în aviz, GTIN internațional în WM)
        $bestDocQty   = null;
        $bestScoreQty = 0.0;

        if ($bestScoreSku === 0) {
            $docQtys = collect($products)
                ->map(fn($p) => round((float)($p['cantitate'] ?? 0), 3))
                ->filter(fn($q) => $q > 0)
                ->sort()->values();

            foreach ($wmByDoc as $docNr => $wmRows) {
                $wmQtysArr = $wmRows
                    ->map(fn($r) => round((float)$r->cantitate, 3))
                    ->filter(fn($q) => $q > 0)
                    ->sort()->values()->all();

                $matched = 0;
                foreach ($docQtys->all() as $qty) {
                    $idx = array_search($qty, $wmQtysArr);
                    if ($idx !== false) {
                        $matched++;
                        array_splice($wmQtysArr, $idx, 1);
                    }
                }

                $score = $docQtys->count() > 0 ? $matched / $docQtys->count() : 0;
                if ($score > $bestScoreQty) {
                    $bestScoreQty = $score;
                    $bestDocQty   = $docNr;
                }
            }
        }

        $bestDoc      = $bestDocSku ?? ($bestScoreQty >= 0.6 ? $bestDocQty : null);
        $matchedBySku = $bestDocSku !== null;

        if (! $bestDoc) {
            $record->update(['match_status' => 'unmatched']);
            return;
        }

        // ── Calculăm discrepanțele față de documentul ales ────────────────
        $wmRows     = $wmByDoc[$bestDoc];
        $wmSkuIdx   = $wmRows->keyBy(fn($r) => ltrim($r->sku, '0'));
        $wmQtyIdx   = $wmRows->keyBy(fn($r) => round((float)$r->cantitate, 3));

        $discrepancies = [];
        $matchedCount  = 0;

        foreach ($products as $prod) {
            $gtin     = ltrim($prod['gtin'] ?? '', '0');
            $cod      = ltrim($prod['cod_furnizor'] ?? '', '0');
            $cantFurn = round((float)($prod['cantitate'] ?? 0), 3);

            // Încearcă match SKU, apoi fallback cantitate
            $wmRow = null;
            if ($gtin && $wmSkuIdx->has($gtin))       { $wmRow = $wmSkuIdx[$gtin]; }
            elseif ($cod && $wmSkuIdx->has($cod))      { $wmRow = $wmSkuIdx[$cod]; }
            elseif ($cantFurn > 0 && $wmQtyIdx->has($cantFurn)) { $wmRow = $wmQtyIdx[$cantFurn]; }

            if (! $wmRow) {
                $discrepancies[] = [
                    'tip'           => 'sku_negasit_in_wm',
                    'sku_furnizor'  => $gtin ?: $cod,
                    'den_furnizor'  => $prod['denumire'] ?? '',
                    'sku_nostru'    => null,
                    'den_nostru'    => null,
                    'pret_furnizor' => $prod['pret_unitar'] ?? null,
                    'pret_nostru'   => null,
                    'cant_furnizor' => $cantFurn,
                    'cant_nostru'   => null,
                ];
                continue;
            }

            $cantWm     = round((float)$wmRow->cantitate, 3);
            $skuWm      = $wmRow->sku;
            $erpProd    = $productBySkuRaw->get(ltrim($skuWm, '0'));
            $pretNostru = $this->getPurchasePrice($erpProd?->id);
            $skuDifera  = ! $matchedBySku && ($gtin !== ltrim($skuWm, '0')) && ($cod !== ltrim($skuWm, '0'));

            if (abs($cantFurn - $cantWm) > 0.001) {
                // Cantitate diferită — discrepanță reală
                $discrepancies[] = [
                    'tip'           => 'cantitate_diferita',
                    'sku_furnizor'  => $gtin ?: $cod,
                    'den_furnizor'  => $prod['denumire'] ?? '',
                    'sku_nostru'    => $skuWm,
                    'den_nostru'    => $erpProd?->winmentor_name ?: $erpProd?->name,
                    'pret_furnizor' => $prod['pret_unitar'] ?? null,
                    'pret_nostru'   => $pretNostru,
                    'cant_furnizor' => $cantFurn,
                    'cant_nostru'   => $cantWm,
                ];
            } elseif ($skuDifera) {
                // Cantitate OK dar SKU diferit — notăm pentru mapare viitoare, nu e eroare
                $discrepancies[] = [
                    'tip'           => 'sku_diferit_cantitate_ok',
                    'sku_furnizor'  => $gtin ?: $cod,
                    'den_furnizor'  => $prod['denumire'] ?? '',
                    'sku_nostru'    => $skuWm,
                    'den_nostru'    => $erpProd?->winmentor_name ?: $erpProd?->name,
                    'pret_furnizor' => $prod['pret_unitar'] ?? null,
                    'pret_nostru'   => $pretNostru,
                    'cant_furnizor' => $cantFurn,
                    'cant_nostru'   => $cantWm,
                ];
                $matchedCount++; // cantitatea e corectă
            } else {
                $matchedCount++;
            }
        }

        $total      = count($products);
        $matchRatio = $total > 0 ? $matchedCount / $total : 0;

        $status = match(true) {
            $matchRatio >= 0.85 => 'matched',
            $matchRatio >= 0.4  => 'partial',
            default             => 'unmatched',
        };

        $record->update([
            'winmentor_doc_nr' => $bestDoc,
            'match_status'     => $status,
            'discrepancies'    => $discrepancies,
        ]);
    }

    private function getPurchasePrice(?int $productId): ?float
    {
        if (! $productId) {
            return null;
        }

        $log = \DB::table('product_purchase_price_logs')
            ->where('woo_product_id', $productId)
            ->orderByDesc('acquired_at')
            ->value('unit_price');

        return $log ? (float) $log : null;
    }

    // ── Procesare body text ────────────────────────────────────────────────

    private function processBodyText(EmailMessage $email, string $body): void
    {
        $record = EmailParsedDocument::create([
            'email_message_id'  => $email->id,
            'supplier_id'       => $email->supplier_id,
            'attachment_name'   => null,
            'attachment_index'  => 0,
            'source_type'       => 'body_text',
            'processing_status' => 'processing',
        ]);

        try {
            $parsed   = $this->parseWithClaude($body, $email, 'email_body');
            $products = $parsed['produse'] ?? $parsed['products'] ?? [];
            $docType  = $this->detectDocType($parsed, $email->subject);
            $docDate  = $this->parseDate($parsed['data'] ?? null) ?? $email->sent_at?->toDate();

            $record->update([
                'doc_type'          => $docType,
                'doc_number'        => $parsed['numar_document'] ?? null,
                'doc_date'          => $docDate,
                'parsed_data'       => $parsed,
                'products'          => $products,
                'processing_status' => 'done',
            ]);

            if (in_array($docType, ['aviz', 'factura', 'comanda']) && ! empty($products) && $docDate) {
                app(\App\Services\SupplierDocMatcherService::class)->match($record);
            }

        } catch (\Throwable $e) {
            $record->update(['processing_status' => 'error', 'error_message' => substr($e->getMessage(), 0, 500)]);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function detectDocType(array $parsed, string $subject): string
    {
        $tip = strtolower($parsed['tip_document'] ?? '');

        if (in_array($tip, ['aviz', 'factura', 'comanda', 'lista_preturi', 'other'])) {
            return $tip;
        }

        $subject = strtolower($subject);
        if (str_contains($subject, 'aviz') || str_contains($subject, 'expedit')) {
            return 'aviz';
        }
        if (str_contains($subject, 'factur')) {
            return 'factura';
        }
        if (str_contains($subject, 'coman')) {
            return 'comanda';
        }
        if (str_contains($subject, 'pret') || str_contains($subject, 'lista')) {
            return 'lista_preturi';
        }

        return 'unknown';
    }

    private function parseDate(?string $raw): ?Carbon
    {
        if (blank($raw)) {
            return null;
        }

        foreach (['d.m.Y', 'd/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $raw);
            } catch (\Throwable) {}
        }

        return null;
    }

    private function bodyLooksRelevant(string $subject, string $body): bool
    {
        $lower = strtolower($subject . ' ' . mb_substr($body, 0, 500));
        foreach (['aviz', 'factur', 'comand', 'livrare', 'expedit', 'confirmar'] as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }
        return false;
    }
}
