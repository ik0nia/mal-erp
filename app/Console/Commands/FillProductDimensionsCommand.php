<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Completează greutatea și dimensiunile lipsă la produsele publicate.
 *
 * Strat 1 (implicit): extracție deterministă din titlu + descriere
 *   - kg/g explicite, litri×densitate (după tipul produsului), ml→L
 *   - dimensiuni „AxB(xC) mm|cm", lungimi simple „90 cm"
 *   → dims_source = 'extracted'
 * Strat 2 (--ai): estimare Claude Haiku pentru produsele rămase fără greutate
 *   → dims_source = 'estimated'
 *
 * Nu suprascrie NICIODATĂ valori existente — completează doar câmpurile goale/0.
 */
class FillProductDimensionsCommand extends Command
{
    protected $signature = 'products:fill-dimensions
        {--dry-run : Doar raport, fără scriere}
        {--ai : Rulează și stratul 2 (estimare Claude) pentru produsele rămase}
        {--re-estimate : Re-estimează produsele cu dims_source=estimated (prompt îmbunătățit)}
        {--limit=0 : Limitează numărul de produse procesate (0 = toate)}';

    protected $description = 'Completează greutate/dimensiuni lipsă din titlu+descriere (și opțional AI), cu proveniență';

    /** Densități aproximative kg/L după cuvinte-cheie din denumire (ordinea contează). */
    private const DENSITY_MAP = [
        'diluant' => 0.85, 'solvent' => 0.85, 'petrosin' => 0.8, 'white spirit' => 0.8,
        'lavabil' => 1.5,  'var '    => 1.4,
        'adeziv'  => 1.3,  'aracet'  => 1.1, 'gel' => 1.1,
        'amorsa'  => 1.3,  'amorsă'  => 1.3, 'grund' => 1.25,
        'vopsea'  => 1.25, 'email '  => 1.2, 'tencuiala' => 1.6, 'tencuială' => 1.6,
        'bitum'   => 1.0,  'membrana lichida' => 1.3,
        'lac '    => 1.0,  'ulei'    => 0.9,
        'silicon' => 1.0,  'spuma'   => 0.9, 'spumă' => 0.9,
    ];
    private const DENSITY_DEFAULT = 1.1;

    public function handle(): int
    {
        $dry   = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $reEstimate = (bool) $this->option('re-estimate');
        $missing = DB::table('woo_products')
            ->where('status', 'publish')
            ->when($reEstimate,
                fn ($q) => $q->where('dims_source', 'estimated'),
                fn ($q) => $q->where(function ($w) {
                    foreach (['weight', 'dim_length', 'dim_width', 'dim_height'] as $c) {
                        $w->orWhereNull($c)->orWhere($c, '')->orWhere($c, 0);
                    }
                })
            )
            ->orderBy('id')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->get(['id', 'name', 'description', 'regular_price', 'weight', 'dim_length', 'dim_width', 'dim_height']);

        $this->info('Produse cu date lipsă: ' . $missing->count());

        $stats = ['weight_extracted' => 0, 'dims_extracted' => 0, 'untouched' => 0, 'ai_weight' => 0, 'ai_dims' => 0];
        $samples = [];
        $needAi = [];

        foreach ($missing as $p) {
            if ($reEstimate) { $needAi[] = $p; continue; }
            $upd = $this->extract($p);

            if ($upd) {
                if (isset($upd['weight'])) $stats['weight_extracted']++;
                if (isset($upd['dim_length']) || isset($upd['dim_width']) || isset($upd['dim_height'])) $stats['dims_extracted']++;
                if (count($samples) < 8) $samples[] = [$p->name, $upd];
                if (! $dry) {
                    $upd['dims_source'] = 'extracted';
                    DB::table('woo_products')->where('id', $p->id)->update($upd);
                }
                // dacă tot n-are greutate după extracție, rămâne candidat AI
                if (! isset($upd['weight']) && ! ((float) $p->weight > 0)) {
                    $needAi[] = $p;
                }
            } else {
                $stats['untouched']++;
                $needAi[] = $p;
            }
        }

        $this->table(['metric', 'nr'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        foreach ($samples as [$name, $upd]) {
            $this->line('  ' . mb_substr($name, 0, 45) . ' → ' . json_encode($upd, JSON_UNESCAPED_UNICODE));
        }

        if ($this->option('ai')) {
            $this->info(PHP_EOL . 'Strat 2 — estimare AI pentru ' . count($needAi) . ' produse...');
            $this->runAiLayer($needAi, $dry, $stats);
            $this->table(['metric', 'nr'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        }

        $this->info($dry ? 'DRY-RUN — nimic scris.' : 'Gata.');

        return self::SUCCESS;
    }

    /** Extracția deterministă. Returnează doar câmpurile pe care le poate completa. */
    private function extract(object $p): array
    {
        $name = $p->name ?? '';
        $desc = strip_tags(html_entity_decode($p->description ?? ''));
        $nameL = mb_strtolower($name);
        $upd = [];

        $hasWeight = (float) $p->weight > 0;
        $hasL = (float) $p->dim_length > 0;
        $hasW = (float) $p->dim_width > 0;
        $hasH = (float) $p->dim_height > 0;

        // ── GREUTATE ────────────────────────────────────────────────────────
        if (! $hasWeight) {
            // 1. kg explicit în titlu sau descriere („Greutate: 5.1kg")
            if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*kg\b/iu', $name, $m)
                || preg_match('/greutate[^0-9]{0,20}(\d+(?:[.,]\d+)?)\s*kg/iu', $desc, $m)) {
                $upd['weight'] = $this->num($m[1]);
            }
            // 2. grame în titlu
            elseif (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(?:g|gr)\b/iu', $name, $m)) {
                $upd['weight'] = round($this->num($m[1]) / 1000, 3);
            }
            // 3. litri × densitate
            elseif (preg_match('/\b(\d+(?:[.,]\d+)?)\s*l(?:itri)?\b/iu', $name, $m)) {
                $upd['weight'] = round($this->num($m[1]) * $this->density($nameL), 2);
            }
            // 4. ml × densitate
            elseif (preg_match('/\b(\d+(?:[.,]\d+)?)\s*ml\b/iu', $name, $m)) {
                $upd['weight'] = round($this->num($m[1]) / 1000 * $this->density($nameL), 3);
            }
            if (isset($upd['weight']) && ($upd['weight'] <= 0 || $upd['weight'] > 2000)) {
                unset($upd['weight']); // plauzibilitate
            }
        }

        // ── DIMENSIUNI ──────────────────────────────────────────────────────
        // La aceste tipuri, „AxB mm" din titlu e specificația PIESEI (diametru țeavă,
        // șurub 4x40, cablu 3x1.5), nu dimensiunea coletului — nu extragem din titlu.
        $itemSpec = (bool) preg_match(
            '/\b(teava|țeavă|tub|surub|șurub|holzsurub|autoforant|dibluri?|cablu|conductor|'
            . 'colier|cositor|electrod|sarma|sârmă|nit|niplu|ancora|burghiu|cheie|bit|'
            . 'furtun|banda|bandă|folie|plasa|plasă|rola|rolă|sfoara|sfoară|snur|șnur|cordon|lant|lanț)\b/iu',
            $nameL
        );

        if (! $hasL || ! $hasW || ! $hasH) {
            $dims = (! $itemSpec ? $this->parseDims($name) : null) ?? $this->parseDims($desc);
            // plauzibilitate colet: cel puțin o latură ≥ 10 cm
            if ($dims && max(array_filter($dims, fn ($v) => $v !== null)) < 10) {
                $dims = null;
            }
            if ($dims) {
                [$l, $w, $h] = $dims;
                if (! $hasL && $l) $upd['dim_length'] = $l;
                if (! $hasW && $w) $upd['dim_width']  = $w;
                if (! $hasH && $h) $upd['dim_height'] = $h;
            } elseif (! $hasL && ! $itemSpec
                && preg_match('/\b(\d+(?:[.,]\d+)?)\s*(mm|cm|m)\b/iu', $name, $m)
                && ! preg_match('/\bm[lp]\b/iu', $name)) {
                // lungime simplă („Coadă 90 cm", „Dreptar 2m") — minim 20 cm ca să fie colet
                $len = $this->toCm($this->num($m[1]), strtolower($m[2]));
                if ($len >= 20) $upd['dim_length'] = $len;
            }
        }

        return $upd;
    }

    /** „AxB" sau „AxBxC" cu unitate explicită → [L, W, H] în cm (H poate fi null). */
    private function parseDims(string $text): ?array
    {
        if (! preg_match(
            '/\b(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)(?:\s*[x×]\s*(\d+(?:[.,]\d+)?))?\s*(mm|cm|m)\b/iu',
            $text, $m
        )) {
            return null;
        }
        $unit = strtolower($m[4]);
        $l = $this->toCm($this->num($m[1]), $unit);
        $w = $this->toCm($this->num($m[2]), $unit);
        $h = isset($m[3]) && $m[3] !== '' ? $this->toCm($this->num($m[3]), $unit) : null;

        // plauzibilitate: max 10 m pe oricare axă
        foreach ([$l, $w, $h] as $v) {
            if ($v !== null && ($v <= 0 || $v > 1000)) return null;
        }

        return [$l, $w, $h];
    }

    private function density(string $nameL): float
    {
        foreach (self::DENSITY_MAP as $kw => $d) {
            if (str_contains($nameL, $kw)) return $d;
        }
        return self::DENSITY_DEFAULT;
    }

    private function num(string $s): float
    {
        return (float) str_replace(',', '.', $s);
    }

    private function toCm(float $v, string $unit): float
    {
        return round(match ($unit) { 'mm' => $v / 10, 'm' => $v * 100, default => $v }, 1);
    }

    /** Strat 2: estimare Claude Haiku în loturi, doar produse fără greutate. */
    private function runAiLayer(array $products, bool $dry, array &$stats): void
    {
        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY) ?? env('ANTHROPIC_API_KEY');
        if (! $apiKey) {
            $this->error('Lipsește cheia Anthropic.');
            return;
        }

        // categoriile ajută estimarea
        $catByProduct = DB::table('woo_product_category as pc')
            ->join('woo_categories as c', 'c.id', '=', 'pc.woo_category_id')
            ->whereIn('pc.woo_product_id', array_column($products, 'id'))
            ->pluck('c.name', 'pc.woo_product_id');

        $bar = $this->output->createProgressBar((int) ceil(count($products) / 30));
        foreach (array_chunk($products, 30) as $chunk) {
            $lines = [];
            foreach ($chunk as $p) {
                $d = mb_substr(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($p->description ?? ''))), 0, 90);
                $lines[] = $p->id . ' | ' . mb_substr($p->name, 0, 70) . ' | ' . ($catByProduct[$p->id] ?? '-')
                    . ' | pret: ' . ($p->regular_price ?? '?') . ' lei | ' . $d;
            }
            $prompt = "Ești expert în materiale de construcții și logistică. Pentru fiecare produs (format: id | denumire | categorie | preț vânzare | început descriere) estimează greutatea REALĂ DE EXPEDIERE în kg (atenție la unitatea de vânzare: bucată vs cutie vs set — prețul e un indiciu bun) și dimensiunile coletului în cm.\n\nRăspunde DOAR cu JSON array: [{\"id\":123,\"kg\":2.5,\"l\":30,\"w\":20,\"h\":10}, ...]\n\nProduse:\n" . implode("\n", $lines);

            try {
                $resp = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 4000,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
                $text = $resp->json('content.0.text') ?? '';
                if (! preg_match('/\[.*\]/s', $text, $mm)) { $bar->advance(); continue; }
                $rows = json_decode($mm[0], true) ?: [];
            } catch (\Throwable $e) {
                $this->warn('AI chunk eșuat: ' . $e->getMessage());
                $bar->advance();
                continue;
            }

            foreach ($rows as $r) {
                $id = (int) ($r['id'] ?? 0);
                $kg = (float) ($r['kg'] ?? 0);
                if (! $id || $kg <= 0 || $kg > 2000) continue;
                $upd = ['dims_source' => 'estimated'];
                $cur = DB::table('woo_products')->where('id', $id)->first(['weight', 'dim_length', 'dim_width', 'dim_height', 'dims_source']);
                if (! $cur) continue;
                $overwrite = $this->option('re-estimate') && $cur->dims_source === 'estimated';
                if ($overwrite || ! ((float) $cur->weight > 0)) { $upd['weight'] = $kg; $stats['ai_weight']++; }
                foreach (['dim_length' => 'l', 'dim_width' => 'w', 'dim_height' => 'h'] as $col => $key) {
                    $v = (float) ($r[$key] ?? 0);
                    if ($v > 0 && $v <= 1000 && ($overwrite || ! ((float) $cur->$col > 0))) { $upd[$col] = $v; $stats['ai_dims']++; }
                }
                // nu degradăm proveniența 'extracted' la 'estimated' dacă doar completăm restul
                if ($cur->dims_source === 'extracted') $upd['dims_source'] = 'extracted';
                if (count($upd) > 1 && ! $dry) {
                    DB::table('woo_products')->where('id', $id)->update($upd);
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }
}
