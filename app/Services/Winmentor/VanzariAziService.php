<?php

namespace App\Services\Winmentor;

use App\Models\DispecerizareVanzare;
use App\Models\IntegrationConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Vânzările zilei (facturi + avize + bonuri) luate LIVE din WinMentor și împerecheate cu
 * stratul de control local. Model „alocări pe cantitate": o linie poate fi împărțită pe mai
 * multe surse (magazin/depozit/livrare), fiecare cu cantitatea ei + predare parțială.
 * NU dublăm datele de vânzare — doar le cache-uim scurt pentru viteză.
 */
class VanzariAziService
{
    private const SURSE = ['magazin', 'depozit', 'livrare'];

    /** Datele paginii de dispecerizare (documente grupate + sumare + filtre). */
    public function pagina(?string $zi = null, string $tip = '', string $filtru = '', string $search = ''): array
    {
        $zi = $zi ?: Carbon::now('Europe/Bucharest')->toDateString();
        $linii = collect($this->liniiAzi($zi));

        $sumar = [
            'total'     => $linii->count(),
            'neallocat' => $linii->filter(fn ($l) => $l['rest'] > 0.0001)->count(),
            'magazin'   => $linii->filter(fn ($l) => isset($l['alocari']['magazin']))->count(),
            'depozit'   => $linii->filter(fn ($l) => isset($l['alocari']['depozit']))->count(),
            'livrare'   => $linii->filter(fn ($l) => isset($l['alocari']['livrare']))->count(),
        ];

        $docPerTip = $linii->groupBy('tip_doc')->map(fn ($g) => $g->pluck('doc_id')->unique()->count());
        $sumarTip = [
            'total' => $linii->groupBy(fn ($l) => $l['tip_doc'] . '|' . $l['doc_id'])->count(),
            'F'     => $docPerTip['F'] ?? 0,
            'AE'    => $docPerTip['AE'] ?? 0,
            'BON'   => $docPerTip['BON'] ?? 0,
        ];

        if (in_array($tip, ['F', 'AE', 'BON'], true)) {
            $linii = $linii->where('tip_doc', $tip);
        }
        if ($filtru === 'neallocat') {
            $linii = $linii->filter(fn ($l) => $l['rest'] > 0.0001);
        } elseif (in_array($filtru, self::SURSE, true)) {
            $linii = $linii->filter(fn ($l) => isset($l['alocari'][$filtru]));
        }
        if ($q = mb_strtolower(trim($search))) {
            $linii = $linii->filter(fn ($l) =>
                str_contains(mb_strtolower($l['client']), $q) ||
                str_contains(mb_strtolower($l['produs']), $q) ||
                str_contains((string) $l['doc_id'], $q) ||
                str_contains((string) $l['nr_doc'], $q) ||
                str_contains((string) $l['art_id'], $q)
            );
        }

        $tipOrder = ['F' => 1, 'AE' => 2, 'BON' => 3];

        $documente = $linii->groupBy(fn ($l) => $l['tip_doc'] . '|' . $l['doc_id'])
            ->map(function ($lines) {
                $first = $lines->first();
                $lines = $lines->sortBy(fn ($l) => is_numeric($l['pozitie']) ? (int) $l['pozitie'] : $l['pozitie'])->values();
                return [
                    'key'       => $first['tip_doc'] . '|' . $first['doc_id'],
                    'tip_doc'   => $first['tip_doc'],
                    'doc_id'    => $first['doc_id'],
                    'nr_doc'    => $first['nr_doc'] ?? $first['doc_id'],
                    'serie'     => $first['serie'],
                    'client'    => $first['client'],
                    'nr_linii'  => $lines->count(),
                    'total'     => $lines->sum('valoare'),
                    'rest_total' => $lines->sum('rest'),
                    'confirmat' => $lines->contains(fn ($l) => collect($l['alocari'])->contains(fn ($a) => in_array($a['status'], ['de_predat', 'predat'], true))),
                    'lines'     => $lines->toArray(),
                ];
            })
            ->sortByDesc(fn ($d) => sprintf('%d-%012d', $tipOrder[$d['tip_doc']] ?? 9, (int) $d['nr_doc']))
            ->values();

        return ['documente' => $documente, 'sumar' => $sumar, 'sumarTip' => $sumarTip, 'zi' => $zi];
    }

    /**
     * Salvează alocările unei linii pe surse. $map = ['magazin'=>10,'depozit'=>20,'livrare'=>0].
     * Sursa cu 0 (sau lipsă) se șterge. Restul se upsertează (cant_alocata).
     */
    public function salveazaAlocari(string $tipDoc, string $docId, string $pozitie, array $map, ?string $zi, ?int $userId): void
    {
        $zi = $zi ?: Carbon::now('Europe/Bucharest')->toDateString();

        foreach (self::SURSE as $sursa) {
            $cant = (float) ($map[$sursa] ?? 0);
            $where = ['tip_doc' => $tipDoc, 'doc_id' => $docId, 'pozitie' => (string) $pozitie, 'sursa' => $sursa];

            if ($cant <= 0) {
                DispecerizareVanzare::where($where)->delete();
                continue;
            }

            $row = DispecerizareVanzare::firstOrNew($where);
            $row->cant_alocata = $cant;
            $row->zi = $zi;
            $row->decis_de = $userId;
            $row->decis_at = Carbon::now();
            if (! $row->exists) {
                $row->status = DispecerizareVanzare::STATUS_NOU;
                $row->cant_predata = 0;
            }
            // Dacă s-a micșorat sub cât e deja predat, ajustăm
            if ($row->cant_predata > $cant) {
                $row->cant_predata = $cant;
            }
            $row->save();
        }
    }

    /** Confirmă documentul: magazin → gata direct; depozit/livrare → de_predat (sarcini independente). */
    public function confirmaDocument(string $tipDoc, string $docId, ?string $zi, ?int $userId): int
    {
        $baza = fn () => DispecerizareVanzare::where('tip_doc', $tipDoc)
            ->where('doc_id', $docId)
            ->where('status', '!=', DispecerizareVanzare::STATUS_PREDAT);

        $magazin = $baza()->where('sursa', DispecerizareVanzare::SURSA_MAGAZIN)->update([
            'status'       => DispecerizareVanzare::STATUS_PREDAT,
            'cant_predata' => DB::raw('cant_alocata'),
            'decis_de'     => $userId,
            'decis_at'     => Carbon::now(),
        ]);

        $predare = $baza()->whereIn('sursa', [DispecerizareVanzare::SURSA_DEPOZIT, DispecerizareVanzare::SURSA_LIVRARE])->update([
            'status'   => DispecerizareVanzare::STATUS_DE_PREDAT,
            'decis_de' => $userId,
            'decis_at' => Carbon::now(),
        ]);

        if ($predare > 0) {
            $this->notificaPredare($tipDoc, $docId, $zi);
        }

        return $magazin + $predare;
    }

    /** Predare PARȚIALĂ pe cantitate, pe o alocare (produs × sursă). */
    public function predaCantitate(string $tipDoc, string $docId, string $pozitie, string $sursa, float $cant, ?int $userId): int
    {
        $row = DispecerizareVanzare::where([
            'tip_doc' => $tipDoc, 'doc_id' => $docId, 'pozitie' => (string) $pozitie, 'sursa' => $sursa,
        ])->where('status', DispecerizareVanzare::STATUS_DE_PREDAT)->first();

        if (! $row) return 0;

        $nou = min((float) $row->cant_alocata, (float) $row->cant_predata + max(0, $cant));
        $row->cant_predata = $nou;
        if ($nou >= (float) $row->cant_alocata - 0.0001) {
            $row->status = DispecerizareVanzare::STATUS_PREDAT;
        }
        $row->decis_de = $userId;
        $row->decis_at = Carbon::now();
        $row->save();

        return 1;
    }

    /** Predă tot restul unei alocări (produs × sursă). */
    public function marcheazaLiniePredat(string $tipDoc, string $docId, string $pozitie, string $sursa, ?int $userId): int
    {
        return DispecerizareVanzare::where([
            'tip_doc' => $tipDoc, 'doc_id' => $docId, 'pozitie' => (string) $pozitie, 'sursa' => $sursa,
        ])->where('status', DispecerizareVanzare::STATUS_DE_PREDAT)->update([
            'status'       => DispecerizareVanzare::STATUS_PREDAT,
            'cant_predata' => DB::raw('cant_alocata'),
            'decis_de'     => $userId,
            'decis_at'     => Carbon::now(),
        ]);
    }

    /** Predă tot restul unei surse dintr-un document (toate produsele acelei surse). */
    public function marcheazaPredat(string $tipDoc, string $docId, string $sursa, ?int $userId): int
    {
        return DispecerizareVanzare::where([
            'tip_doc' => $tipDoc, 'doc_id' => $docId, 'sursa' => $sursa,
        ])->where('status', DispecerizareVanzare::STATUS_DE_PREDAT)->update([
            'status'       => DispecerizareVanzare::STATUS_PREDAT,
            'cant_predata' => DB::raw('cant_alocata'),
            'decis_de'     => $userId,
            'decis_at'     => Carbon::now(),
        ]);
    }

    /**
     * Sarcinile de predare — câte una per (document × sursă), INDEPENDENTE.
     * Fiecare linie = o alocare cu rest > 0. Magazinul nu apare (gata la tejghea).
     */
    public function dePredat(?string $zi = null, ?string $sursa = null): array
    {
        $zi = $zi ?: Carbon::now('Europe/Bucharest')->toDateString();
        $surseValide = in_array($sursa, self::SURSE, true) ? [$sursa] : ['depozit', 'livrare'];

        $items = [];
        foreach ($this->liniiAzi($zi) as $l) {
            foreach ($l['alocari'] as $s => $a) {
                if ($a['status'] !== DispecerizareVanzare::STATUS_DE_PREDAT) continue;
                if (! in_array($s, $surseValide, true)) continue;
                $rest = $a['cant'] - $a['predata'];
                if ($rest <= 0.0001) continue;

                $items[] = [
                    'tip_doc' => $l['tip_doc'], 'doc_id' => $l['doc_id'], 'nr_doc' => $l['nr_doc'],
                    'client' => $l['client'], 'sursa' => $s, 'pozitie' => $l['pozitie'],
                    'produs' => $l['produs'], 'art_id' => $l['art_id'],
                    'cant_alocata' => $a['cant'], 'cant_predata' => $a['predata'], 'rest' => $rest,
                ];
            }
        }

        return collect($items)
            ->groupBy(fn ($i) => $i['tip_doc'] . '|' . $i['doc_id'] . '|' . $i['sursa'])
            ->map(function ($lines) {
                $first = $lines->first();
                return [
                    'tip_doc'  => $first['tip_doc'], 'doc_id' => $first['doc_id'], 'nr_doc' => $first['nr_doc'],
                    'client'   => $first['client'], 'sursa' => $first['sursa'],
                    'nr_linii' => $lines->count(), 'rest_total' => $lines->sum('rest'),
                    'lines'    => $lines->values()->toArray(),
                ];
            })
            ->sortBy(fn ($d) => $d['sursa'] . '-' . sprintf('%012d', 999999999999 - (int) $d['nr_doc']))
            ->values()
            ->toArray();
    }

    private function notificaPredare(string $tipDoc, string $docId, ?string $zi): void
    {
        $linii = collect($this->liniiAzi($zi))->where('tip_doc', $tipDoc)->where('doc_id', $docId);
        if ($linii->isEmpty()) return;

        $surse = collect();
        foreach ($linii as $l) {
            foreach ($l['alocari'] as $s => $a) {
                if (in_array($s, ['depozit', 'livrare'], true) && $a['status'] === DispecerizareVanzare::STATUS_DE_PREDAT) {
                    $surse->push($s);
                }
            }
        }
        $surse = $surse->unique()->values();
        if ($surse->isEmpty()) return;

        $first = $linii->first();
        $tipLabel = ['BON' => 'Bon', 'F' => 'Factură', 'AE' => 'Aviz'][$tipDoc] ?? $tipDoc;

        \App\Jobs\SendWhPushNotificationJob::dispatch(
            '📦 De predat: ' . $tipLabel . ' #' . $first['nr_doc'],
            $first['client'] . ' · ' . $surse->implode(', '),
            '/app/de-predat',
        );
    }

    /** Liniile zilei + alocările lor pe surse. */
    public function liniiAzi(?string $zi = null): array
    {
        $zi = $zi ?: Carbon::now('Europe/Bucharest')->toDateString();
        $linii = $this->vanzariLive($zi);

        $rows = DispecerizareVanzare::whereIn(
            DB::raw("concat(tip_doc,'|',doc_id,'|',pozitie)"),
            array_map(fn ($l) => $l['cheie'], $linii)
        )->get()->groupBy(fn ($r) => $r->tip_doc . '|' . $r->doc_id . '|' . $r->pozitie);

        foreach ($linii as &$l) {
            $alocari = [];
            foreach (($rows->get($l['cheie']) ?? collect()) as $r) {
                if (! $r->sursa) continue;
                $alocari[$r->sursa] = [
                    'cant'    => (float) $r->cant_alocata,
                    'predata' => (float) $r->cant_predata,
                    'status'  => $r->status,
                ];
            }
            // Bonurile fără alocare explicită → magazin pe toată cantitatea (ridicat de la tejghea).
            if (empty($alocari) && $l['tip_doc'] === 'BON') {
                $alocari['magazin'] = ['cant' => (float) $l['cantitate'], 'predata' => 0, 'status' => DispecerizareVanzare::STATUS_NOU];
            }
            $l['alocari'] = $alocari;
            $l['alocat']  = array_sum(array_column($alocari, 'cant'));
            $l['rest']    = max(0, (float) $l['cantitate'] - $l['alocat']);
        }

        return $linii;
    }

    /** Golește cache-ul listei (reîmprospătare la cerere). Conținutul bonurilor rămâne. */
    public function reimprospateaza(?string $zi = null): void
    {
        $zi = $zi ?: Carbon::now('Europe/Bucharest')->toDateString();
        Cache::forget("vanzari_azi_{$zi}");
        Cache::forget("bonuri_lista_{$zi}");
    }

    /** Vânzările live din WinMentor pentru o zi (cache transient 60s). */
    private function vanzariLive(string $zi): array
    {
        return Cache::remember("vanzari_azi_{$zi}", 60, function () use ($zi) {
            $conn = IntegrationConnection::find(5);
            if (! $conn) return [];

            $aziStr = Carbon::parse($zi)->format('d.m.Y');
            $parteneriMap = DB::table('winmentor_parteneri')->pluck('denumire', 'wm_id')->toArray();

            $out = [];

            // Facturi (F) + Avize (AE)
            foreach ($this->fetch($conn, '/api/vanzari/luna') as $v) {
                if (($v['dataEmitere'] ?? '') !== $aziStr) continue;
                $tip = $v['tipDocument'] ?? '';
                if (! in_array($tip, ['F', 'AE'], true)) continue;

                $art = (string) ($v['codArticol'] ?? '');
                $part = (string) ($v['idPartener'] ?? '');
                $qty = (float) str_replace(',', '.', $v['cant'] ?? '0');
                $pret = (float) str_replace(',', '.', $v['pret'] ?? '0');
                $nr = (string) ($v['nrFactura'] ?? '');

                $out[] = [
                    'tip_doc' => $tip, 'doc_id' => $nr, 'nr_doc' => $nr,
                    'serie' => (string) ($v['serieDocument'] ?? ''), 'pozitie' => $art,
                    'cheie' => DispecerizareVanzare::cheie($tip, $nr, $art),
                    'client' => $parteneriMap[$part] ?? ($v['localitateClient'] ?? null) ?: $part,
                    'art_id' => $art, 'produs' => (string) ($v['denArticol'] ?? ''),
                    'cantitate' => $qty, 'pret' => $pret, 'valoare' => round($qty * $pret, 2),
                    'gestiune' => (string) ($v['marcaAgent'] ?? ''),
                ];
            }

            // Bonuri casă (POS) — numărul REAL via GetInfoBon/GetInfoBonExt; preț/SKU din emulare.
            $emPret = []; $emSku = [];
            foreach ($this->fetch($conn, '/api/vanzari/emulare') as $b) {
                if (($b['data'] ?? '') !== $aziStr) continue;
                $den = (string) ($b['denArticol'] ?? '');
                if ($den === '') continue;
                $emPret[$den] = (float) str_replace(',', '.', $b['pret'] ?? '0');
                $emSku[$den]  = (string) ($b['codArticol'] ?? '');
            }

            $ziNr  = Carbon::parse($zi)->day;
            $lista = Cache::remember("bonuri_lista_{$zi}", 120, fn () => $this->fetch($conn, '/api/bonuri/info', ['zi' => $ziNr]));

            foreach ($lista as $b) {
                $nr = (int) ($b['nrBon'] ?? 0);
                if ($nr <= 0) continue;

                $continut = Cache::remember(
                    "bon_ext_{$zi}_{$nr}", 64800,
                    fn () => $this->fetch($conn, '/api/bonuri/info-ext', ['nrBon' => $nr, 'zi' => $ziNr])
                );

                $docId = $zi . '/' . $nr;
                foreach ($continut as $row) {
                    $poz = (string) ($row[0] ?? '');
                    $den = (string) ($row[1] ?? '');
                    $qty = (float) str_replace(',', '.', $row[2] ?? '0');
                    $pret = $emPret[$den] ?? 0;

                    $out[] = [
                        'tip_doc' => 'BON', 'doc_id' => $docId, 'nr_doc' => (string) $nr,
                        'serie' => '', 'pozitie' => $poz,
                        'cheie' => DispecerizareVanzare::cheie('BON', $docId, $poz),
                        'client' => 'Casă', 'art_id' => $emSku[$den] ?? '', 'produs' => $den,
                        'cantitate' => $qty, 'pret' => $pret, 'valoare' => round($qty * $pret, 2),
                        'gestiune' => 'MP',
                    ];
                }
            }

            return $out;
        });
    }

    private function fetch(IntegrationConnection $conn, string $path, array $query = []): array
    {
        try {
            $r = Http::timeout(90)->withoutVerifying()
                ->withHeaders(['X-API-Key' => $conn->bridgeApiKey()])
                ->get(rtrim($conn->bridgeUrl(), '/') . $path, $query);

            return $r->json()['data'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
