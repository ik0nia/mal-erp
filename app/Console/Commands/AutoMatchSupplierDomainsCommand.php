<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\EmailMessage;
use App\Models\Supplier;
use App\Models\SupplierContact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Potrivește automat domeniile de email ale expeditorilor cu furnizorii din sistem.
 *
 * Pași:
 *  1. Extrage toate domeniile unice din email_messages (cu număr emailuri)
 *  2. Elimină domeniile cunoscute (non-furnizori: bănci, curierat, utilități, etc.)
 *  3. Contact-domain match: domeniu = domeniu email contact existent → high confidence
 *  4. Name match: domainKey substring în supplierNameKey → high confidence
 *  5. Domeniile rămase → Claude AI → matching JSON
 *  6. Aplică potrivirile: setează suppliers.email + bulk update email_messages.supplier_id
 *
 * Opțiuni:
 *  --dry-run    → afișează matching-ul propus fără a salva
 *  --min-emails → ignoră domeniile cu mai puțin de N emailuri (default 3)
 */
class AutoMatchSupplierDomainsCommand extends Command
{
    protected $signature = 'supplier:auto-match-domains
                            {--dry-run : Afișează matching-ul fără a salva}
                            {--min-emails=3 : Ignoră domeniile cu mai puțin de N emailuri}';

    protected $description = 'Potrivește automat domeniile de email cu furnizorii, folosind contact-match + name-match + Claude AI';

    // Domenii care nu sunt furnizori de produse/servicii directe
    private const EXCLUDED_DOMAINS = [
        // Email provideri generali
        'gmail.com', 'yahoo.com', 'yahoo.ro', 'hotmail.com', 'hotmail.ro',
        'outlook.com', 'outlook.ro', 'live.com', 'icloud.com',

        // Curierat & logistică
        'sameday.ro', 'fancourier.ro', 'dpd.ro', 'gls-romania.ro', 'dhl.com',
        'fedex.com', 'ups.com', 'cargus.ro', 'urgent-cargus.ro', 'tnt.com',
        'alsendo.com', 'nec-transport.com', 'actransport.ro',

        // Bănci & financiar
        'btrl.ro', 'bt.ro', 'firstbank.ro', 'bcr.ro', 'brd.ro', 'ing.ro',
        'raiffeisen.ro', 'raiffeisen-leasing.ro', 'btleasing.ro', 'btbroker.ro',
        'unicredit.ro', 'unicreditleasing.ro', 'eximbank.ro', 'exim.ro',
        'garanti.ro', 'otp.ro', 'cec.ro', 'libra-bank.ro',

        // Utilități & servicii publice
        'eon-romania.ro', 'enel.com', 'enel.ro', 'electrica.ro', 'e-on.ro',
        'apaoradea.ro', 'aquatim.ro', 'aquaserv.ro', 'rca.ro',
        'romgaz.ro', 'petrom.ro', 'omv.ro',

        // Stat & autorități
        'anaf.ro', 'gov.ro', 'reges.inspectiamuncii.ro', 'inspectiamuncii.ro',
        'mfinante.ro', 'onrc.ro', 'e-licitatie.ro', 'sicap.e-licitatie.ro',
        'mail.sicap.e-licitatie.ro', 'licitatiipublice.ro',

        // Servicii info/SaaS
        'termene.ro', 'smartbill.ro', 'facturielectronice.ro', 'ibcfocus.ro',
        'trackgps.ro', 'e-rovinieta.ro', 'roviniete.ro', 'iasig.ro',

        // Asigurări
        'signal-iduna.ro', 'allianz.ro', 'omniasig.ro', 'groupama.ro',
        'uniqa.ro', 'asirom.ro',

        // Auto & leasing
        'mercedes-benz.com', 'mercedes-benz.ro', 'fordcarbenta.ro',
        'auto-schunn.ro', 'autovit.ro',

        // Resurse umane & recrutare
        'multijobs.ro', 'bestjobs.ro', 'ejobs.ro', 'hipo.ro',
        'studentii-muncitori.ro', 'muncaazi.ro',

        // Newslettere, marketing, evenimente
        'newsletter-enecesar.com', 'news.cursuri-functionari.ro',
        'atumag.com.mktrmail.com', 'ibcfocus.ro', 'salveazaoinima.ro',
        'upfr.ro', 'credidam.ro', 'ahkrumaenien.ro',

        // Altele frecvente non-furnizor
        'superbon.ro', 'noi.ro', 'malinco.ro', // propriul domeniu
    ];

    public function handle(): int
    {
        $apiKey  = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY);
        $minEmails = (int) $this->option('min-emails');
        $dryRun    = $this->option('dry-run');

        // ── STEP 1: Domeniile din inbox fără supplier_id ──────────────────────
        $domains = EmailMessage::whereNull('supplier_id')
            ->whereNotNull('from_email')
            ->where('imap_folder', 'INBOX')
            ->selectRaw('SUBSTRING_INDEX(from_email, "@", -1) as domain, COUNT(*) as cnt')
            ->groupBy('domain')
            ->orderByDesc('cnt')
            ->get()
            ->filter(fn ($r) => $r->cnt >= $minEmails)
            ->filter(fn ($r) => ! in_array(strtolower($r->domain), self::EXCLUDED_DOMAINS))
            ->values();

        if ($domains->isEmpty()) {
            $this->info('Nu există domenii de potrivit (toate sunt deja asociate sau excluse).');
            return self::SUCCESS;
        }

        // ── STEP 2: Furnizorii existenți ─────────────────────────────────────
        $suppliers = Supplier::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // ── STEP 3: Index domenii → furnizor din supplier_contacts existente ─
        // Ex: contact cu email "dan.mesteru@cemacon.ro" → domeniu "cemacon.ro" → Cemacon
        $contactDomainIndex = SupplierContact::whereNotNull('email')
            ->whereNotNull('supplier_id')
            ->get(['email', 'supplier_id'])
            ->mapWithKeys(function ($c) {
                $domain = strtolower(substr(strrchr($c->email, '@'), 1));
                return [$domain => $c->supplier_id];
            });

        $this->info("Domenii de analizat: {$domains->count()}");
        $this->info("Furnizori activi: {$suppliers->count()}");
        $this->info("Domenii din contacte existente: {$contactDomainIndex->count()}");
        $this->newLine();

        $matches       = collect();
        $remainDomains = collect();

        foreach ($domains as $row) {
            $domain    = strtolower($row->domain);
            $domainKey = strtolower(explode('.', $domain)[0]); // "cemacon" din "cemacon.ro"

            // ── Pass A: Contact-domain match (high confidence) ───────────────
            if ($contactDomainIndex->has($domain)) {
                $supplierId = $contactDomainIndex->get($domain);
                $supplier   = $suppliers->firstWhere('id', $supplierId);
                if ($supplier) {
                    $matches->push([
                        'domain'      => $domain,
                        'supplier_id' => $supplierId,
                        'supplier'    => $supplier->name,
                        'emails'      => $row->cnt,
                        'confidence'  => 'high',
                        'method'      => 'contact_domain',
                    ]);
                    continue;
                }
            }

            // ── Pass B: Name-similarity match (high confidence) ───────────────
            $found = null;
            foreach ($suppliers as $supplier) {
                $nameKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $supplier->name));
                if (
                    strlen($domainKey) >= 4 &&
                    (str_contains($nameKey, $domainKey) || str_contains($domainKey, $nameKey))
                ) {
                    $found = $supplier;
                    break;
                }
            }

            if ($found) {
                $matches->push([
                    'domain'      => $domain,
                    'supplier_id' => $found->id,
                    'supplier'    => $found->name,
                    'emails'      => $row->cnt,
                    'confidence'  => 'high',
                    'method'      => 'name_match',
                ]);
            } else {
                $remainDomains->push($row);
            }
        }

        // ── STEP 4: Domeniile rămase → Claude AI ─────────────────────────────
        if ($remainDomains->isNotEmpty() && filled($apiKey)) {
            $this->line('Trimit ' . $remainDomains->count() . ' domenii necunoscute la Claude...');
            $aiMatches = $this->askClaude($apiKey, $suppliers, $remainDomains);
            $matches   = $matches->merge($aiMatches);
        } elseif ($remainDomains->isNotEmpty()) {
            $this->warn('Cheia Anthropic lipsă — domeniile necunoscute nu pot fi potrivite de AI.');
        }

        if ($matches->isEmpty()) {
            $this->warn('Nu s-au găsit potriviri.');
            return self::SUCCESS;
        }

        // ── STEP 5: Afișăm rezultatele ───────────────────────────────────────
        $this->newLine();
        $this->line('<fg=cyan;options=bold>Potriviri găsite:</> ' . $matches->count());
        $this->newLine();

        $highConf = $matches->where('confidence', 'high');
        $lowConf  = $matches->where('confidence', 'low');

        if ($highConf->isNotEmpty()) {
            $this->line('<fg=green>Potriviri sigure (aplicare automată):</> ' . $highConf->count());
            foreach ($highConf as $m) {
                $this->line("  ✓ @{$m['domain']} ({$m['emails']} emailuri) → {$m['supplier']} [{$m['method']}]");
            }
            $this->newLine();
        }

        if ($lowConf->isNotEmpty()) {
            $this->line('<fg=yellow>Potriviri incerte (confirmare necesară):</> ' . $lowConf->count());
            foreach ($lowConf as $m) {
                $this->line("  ? @{$m['domain']} ({$m['emails']} emailuri) → {$m['supplier']} (AI: " . ($m['ai_reason'] ?? '') . ')');
            }
            $this->newLine();
        }

        if ($dryRun) {
            $this->warn('DRY RUN — nimic nu a fost salvat.');
            return self::SUCCESS;
        }

        // ── STEP 6: Aplicare automată pentru high confidence ─────────────────
        $totalUpdated = 0;

        foreach ($highConf as $m) {
            // Setăm email canonic la furnizor (cel mai frecvent din domeniu)
            $canonicalEmail = EmailMessage::where('from_email', 'like', '%@' . $m['domain'])
                ->selectRaw('from_email, COUNT(*) as cnt')
                ->groupBy('from_email')
                ->orderByDesc('cnt')
                ->value('from_email');

            if ($canonicalEmail) {
                Supplier::where('id', $m['supplier_id'])->update(['email' => $canonicalEmail]);
            }

            // Bulk update email_messages.supplier_id pentru toate emailurile din domeniu
            $updated = EmailMessage::whereNull('supplier_id')
                ->where('from_email', 'like', '%@' . $m['domain'])
                ->update(['supplier_id' => $m['supplier_id']]);

            $totalUpdated += $updated;
            $this->line("  ✓ @{$m['domain']} → {$m['supplier']}: {$updated} emailuri actualizate");
        }

        // Potrivirile incerte — confirmare interactivă
        if ($lowConf->isNotEmpty() && $this->input->isInteractive()) {
            $this->newLine();
            $this->line('<fg=yellow>Confirmă potrivirile incerte:</>');

            foreach ($lowConf as $m) {
                $confirm = $this->confirm(
                    "  @{$m['domain']} ({$m['emails']} emailuri) → {$m['supplier']}?",
                    false
                );

                if ($confirm) {
                    $canonicalEmail = EmailMessage::where('from_email', 'like', '%@' . $m['domain'])
                        ->selectRaw('from_email, COUNT(*) as cnt')
                        ->groupBy('from_email')
                        ->orderByDesc('cnt')
                        ->value('from_email');

                    if ($canonicalEmail) {
                        Supplier::where('id', $m['supplier_id'])->update(['email' => $canonicalEmail]);
                    }

                    $updated = EmailMessage::whereNull('supplier_id')
                        ->where('from_email', 'like', '%@' . $m['domain'])
                        ->update(['supplier_id' => $m['supplier_id']]);

                    $totalUpdated += $updated;
                    $this->line("    → {$updated} emailuri actualizate.");
                }
            }
        }

        $this->newLine();
        $this->info("Total emailuri asociate: <fg=green>{$totalUpdated}</>");

        // ── STEP 7: Redescoperire contacte după asociere ──────────────────────
        if ($totalUpdated > 0) {
            $this->line('Actualizez contactele...');
            $this->call('supplier:discover-contacts');
        }

        return self::SUCCESS;
    }

    private function askClaude($apiKey, $suppliers, $domains): \Illuminate\Support\Collection
    {
        $supplierList = $suppliers->map(fn ($s) => "ID:{$s->id} | {$s->name}")->implode("\n");
        $domainList   = $domains->map(fn ($r) => "@{$r->domain} ({$r->cnt} emailuri)")->implode("\n");

        $prompt = <<<PROMPT
Ești un asistent ERP pentru o firmă de construcții/retail din România care vinde materiale de construcții.

Mai jos ai lista furnizorilor activi din sistemul nostru și lista domeniilor de email din care am primit emailuri în inbox.

Potrivește fiecare domeniu cu un furnizor, DOAR dacă ești sigur că sunt aceeași companie.

FURNIZORI:
{$supplierList}

DOMENII EMAIL:
{$domainList}

Returnează EXCLUSIV JSON valid, fără text înainte sau după:
[
  {
    "domain": "exemplu.ro",
    "supplier_id": 5,
    "confidence": "high|low",
    "reason": "explicație scurtă"
  }
]

Reguli:
- "high" = ești sigur (ex: "soudal.ro" → "Soudal Romania")
- "low" = probabil dar nu sigur
- Nu include domeniile pentru care nu găsești un furnizor potrivit
- Nu inventezi furnizori noi
- Ignora domeniile care sunt clar bănci, curierat, utilități, newslettere
PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model'      => config('app.malinco.ai.models.haiku', 'claude-haiku-4-5-20251001'),
                'max_tokens' => 2048,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]);

            if (! $response->successful()) {
                $this->warn('Claude API error: ' . $response->status() . ' — ' . $response->body());
                return collect();
            }

            $content = trim($response->json('content.0.text', ''));

            if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) {
                $content = $m[1];
            }

            $decoded = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                $this->warn('JSON invalid de la Claude: ' . substr($content, 0, 200));
                return collect();
            }

            $supplierMap = $suppliers->keyBy('id');

            return collect($decoded)
                ->filter(fn ($m) => isset($m['domain'], $m['supplier_id']))
                ->map(function ($m) use ($supplierMap) {
                    $supplier = $supplierMap->get($m['supplier_id']);
                    if (! $supplier) {
                        return null;
                    }

                    // Normalizăm domeniul (Claude uneori returnează "@domain.ro" cu prefix @)
                    $cleanDomain = ltrim($m['domain'], '@');

                    // Recalculăm emailCount cu domeniul curat
                    $emailCount = EmailMessage::whereNull('supplier_id')
                        ->where('from_email', 'like', '%@' . $cleanDomain)
                        ->count();

                    // Ignorăm dacă nu avem emailuri de asociat
                    if ($emailCount === 0) {
                        return null;
                    }

                    return [
                        'domain'      => $cleanDomain,
                        'supplier_id' => $m['supplier_id'],
                        'supplier'    => $supplier->name,
                        'emails'      => $emailCount,
                        'confidence'  => $m['confidence'] ?? 'low',
                        'method'      => 'ai',
                        'ai_reason'   => $m['reason'] ?? '',
                    ];
                })
                ->filter()
                ->values();

        } catch (\Throwable $e) {
            $this->warn('Eroare Claude: ' . $e->getMessage());
            return collect();
        }
    }
}
