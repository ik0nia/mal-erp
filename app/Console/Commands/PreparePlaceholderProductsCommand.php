<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\Supplier;
use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Pregătește placeholder-ele WinMentor cu stoc pentru publicare — DOAR în ERP,
 * fără nicio atingere a site-ului (update-uri prin DB, nu Eloquent → observerul
 * de push NU se declanșează):
 *   1. asociere furnizor + preț de achiziție din intrările WinMentor
 *   2. nume comercial (păstrând codurile de produs) + descriere — Claude
 * Categoriile și imaginile se fac cu comenzile dedicate existente.
 */
class PreparePlaceholderProductsCommand extends Command
{
    protected $signature = 'products:prepare-placeholders
        {--dry-run : Doar raport}
        {--skip-ai : Doar asocierea furnizorilor, fără nume/descrieri}
        {--only-missing : Doar produsele încă neprocesate (fără winmentor_name salvat)}';

    protected $description = 'Pregătește placeholder-ele WinMentor cu stoc (furnizor + nume + descriere), fără push pe site';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $products = WooProduct::where('is_placeholder', true)
            ->whereIn('source', [WooProduct::SOURCE_WINMENTOR_CSV, WooProduct::SOURCE_WINMENTOR_BRIDGE])
            ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))
            ->where('name', 'not like', '%maxcl%')
            ->when($this->option('only-missing'), fn ($q) => $q->whereNull('winmentor_name'))
            ->get(['id', 'sku', 'name', 'winmentor_name', 'description']);

        $this->info('Produse țintă: ' . $products->count());

        // ── 1. FURNIZORI din ultimele intrări ───────────────────────────────
        $norm = fn ($s) => preg_replace('/[^A-Z0-9 ]/', '', str_replace(['S.R.L', 'SRL', 'S.A', ' SA '], '', mb_strtoupper(trim($s ?? ''))));
        $lastIn = DB::table('winmentor_intrari_raw')
            ->whereIn('sku', $products->pluck('sku'))
            ->orderBy('data_intrare')
            ->get(['sku', 'den_furnizor', 'part_id', 'pret', 'data_intrare'])
            ->keyBy('sku'); // ultimul câștigă

        $suppliers = Supplier::get(['id', 'name', 'winmentor_id']);
        $byWmId = $suppliers->whereNotNull('winmentor_id')->keyBy('winmentor_id');
        $byName = $suppliers->keyBy(fn ($s) => $norm($s->name));

        $assoc = 0;
        $noMatch = [];
        foreach ($products as $p) {
            $in = $lastIn[$p->sku] ?? null;
            if (! $in) continue;
            $sup = $byWmId[$in->part_id] ?? $byName[$norm($in->den_furnizor)] ?? null;
            if (! $sup) { $noMatch[$in->den_furnizor] = true; continue; }
            if (! $dry) {
                DB::table('product_suppliers')->updateOrInsert(
                    ['woo_product_id' => $p->id, 'supplier_id' => $sup->id],
                    [
                        'purchase_price'     => $in->pret,
                        'last_purchase_price' => $in->pret,
                        'last_purchase_date' => $in->data_intrare,
                        'updated_at'         => now(),
                        'created_at'         => now(),
                    ]
                );
            }
            $assoc++;
        }
        $this->info("Furnizori asociați: {$assoc} | fără match: " . count($noMatch) . ' (' . implode('; ', array_keys($noMatch)) . ')');

        if ($this->option('skip-ai')) {
            return self::SUCCESS;
        }

        // ── 2. NUME COMERCIAL + DESCRIERE (Claude) ──────────────────────────
        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY) ?? env('ANTHROPIC_API_KEY');
        if (! $apiKey) { $this->error('Lipsește cheia Anthropic.'); return self::FAILURE; }

        $supByProduct = DB::table('product_suppliers as ps')->join('suppliers as s', 's.id', 'ps.supplier_id')
            ->whereIn('ps.woo_product_id', $products->pluck('id'))
            ->pluck('s.name', 'ps.woo_product_id');

        $done = 0;
        $bar = $this->output->createProgressBar((int) ceil($products->count() / 15));
        foreach ($products->chunk(15) as $chunk) {
            $lines = $chunk->map(fn ($p) => $p->id . ' | ' . $p->name . ' | furnizor: ' . ($supByProduct[$p->id] ?? 'necunoscut'))->implode("\n");
            $prompt = <<<PROMPT
Ești specialist în e-commerce de materiale de construcții (magazin românesc). Pentru fiecare produs (format: id | denumire brută WinMentor | furnizor) generează:
- "name": denumire comercială îngrijită, Title Case românesc, diacritice corecte, fără MAJUSCULE integrale. PĂSTREAZĂ OBLIGATORIU codurile de produs/model din denumirea brută (ex. AG012, 915, D3GD). Nu inventa specificații noi.
- "description": descriere HTML scurtă (un paragraf <p> de 2-3 fraze utile cumpărătorului + dacă din denumire reies caracteristici certe, o listă <ul> cu ele). Fără promisiuni inventate, fără prețuri.

Răspunde DOAR cu JSON array valid: [{"id":123,"name":"...","description":"..."}]

Produse:
{$lines}
PROMPT;
            try {
                $resp = Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])
                    ->timeout(180)->post('https://api.anthropic.com/v1/messages', [
                        'model' => 'claude-sonnet-4-6',
                        'max_tokens' => 8000,
                        'messages' => [['role' => 'user', 'content' => $prompt]],
                    ]);
                $text = $resp->json('content.0.text') ?? '';
                $text = preg_replace('/^```(json)?|```$/m', '', $text); // Sonnet împachetează în fences
                if (! preg_match('/\[.*\]/s', $text, $mm)) { $bar->advance(); continue; }
                $rows = json_decode($mm[0], true) ?: [];
            } catch (\Throwable $e) {
                $this->warn(' chunk eșuat: ' . substr($e->getMessage(), 0, 100));
                $bar->advance();
                continue;
            }

            foreach ($rows as $r) {
                $id = (int) ($r['id'] ?? 0);
                $name = trim($r['name'] ?? '');
                $desc = trim($r['description'] ?? '');
                $orig = $products->firstWhere('id', $id);
                if (! $orig || mb_strlen($name) < 5) continue;
                $upd = ['updated_at' => now()];
                // pastram originalul WinMentor înainte de a-l înlocui
                if (blank($orig->winmentor_name)) $upd['winmentor_name'] = $orig->name;
                $upd['name'] = $name;
                if (blank($orig->description) && $desc !== '') $upd['description'] = $desc;
                if (! $dry) {
                    DB::table('woo_products')->where('id', $id)->update($upd); // DB direct — fără observer/push
                }
                $done++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info(($dry ? '[DRY] ' : '') . "Nume+descrieri generate: {$done}");

        return self::SUCCESS;
    }
}
