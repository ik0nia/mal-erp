<?php

namespace App\Services\Winmentor;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rezolvă ID-uri de sediu WinMentor → partenerul-mamă (id + denumire).
 *
 * Comenzile/livrările/vânzările din MentorAPI pot referi un ID de SEDIU în loc
 * de ID-ul partenerului. Maparea se ține persistent în winmentor_sediu_partener_map
 * (write-through): doar ID-urile nemaiîntâlnite declanșează scanarea paginată a
 * nomenclatorului de parteneri — înainte, fiecare rulare (la 5-10 min) scana tot
 * nomenclatorul (~24 pagini × 500), adică ~3.000 de apeluri MentorAPI pe zi.
 *
 * ID-urile care nu se găsesc nici în nomenclator primesc un negative-cache de 6h,
 * ca să nu redeclanșeze scanarea la fiecare rulare.
 */
class SediuPartenerResolver
{
    private const NEGATIVE_CACHE_TTL = 3600 * 6;

    /**
     * @param  iterable<string>  $sediuIds  ID-uri (posibil de sediu) fără corespondent direct în parteneri
     * @return array<string, string>  sediu_id => denumire partener
     */
    public function resolveNames(iterable $sediuIds): array
    {
        $ids = collect($sediuIds)->map(fn ($id) => (string) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $map = DB::table('winmentor_sediu_partener_map')
            ->whereIn('sediu_id', $ids)
            ->pluck('denumire_partener', 'sediu_id')
            ->all();

        $unknown = $ids->reject(fn ($id) => isset($map[$id]))
            ->reject(fn ($id) => Cache::has("wm_sediu_negcache_{$id}"))
            ->values();

        if ($unknown->isEmpty()) {
            return $map;
        }

        $found = $this->scanNomenclator($unknown);

        foreach ($found as $sediuId => $info) {
            DB::table('winmentor_sediu_partener_map')->updateOrInsert(
                ['sediu_id' => $sediuId],
                [
                    'partener_wm_id'     => $info['partener_wm_id'],
                    'denumire_partener'  => mb_substr($info['denumire'], 0, 255),
                    'updated_at'         => now(),
                    'created_at'         => now(),
                ],
            );
            $map[$sediuId] = $info['denumire'];
        }

        foreach ($unknown as $id) {
            if (! isset($found[$id])) {
                Cache::put("wm_sediu_negcache_{$id}", 1, self::NEGATIVE_CACHE_TTL);
            }
        }

        return $map;
    }

    /**
     * Scanează nomenclatorul paginat /api/parteneri (config implicit — denumiriSedii
     * conține ID-urile sediilor) căutând ID-urile date. Se oprește când le-a găsit
     * pe toate sau când s-au terminat paginile.
     *
     * @return array<string, array{partener_wm_id: string, denumire: string}>
     */
    private function scanNomenclator(\Illuminate\Support\Collection $wanted): array
    {
        $found = [];

        try {
            $bridge = app(WinmentorBridgeClient::class);
            $bridge->selectFirma();
            $ref = new \ReflectionClass($bridge);
            $getMethod = $ref->getMethod('get');
            $getMethod->setAccessible(true);

            for ($page = 1; $page <= 50; $page++) {
                $r = $getMethod->invoke($bridge, '/api/parteneri', ['page' => $page, 'pageSize' => 500]);
                $items = $r['data']['items'] ?? [];

                foreach ($items as $item) {
                    foreach ($item['denumiriSedii'] ?? [] as $sediuId) {
                        $sediuId = (string) $sediuId;
                        if ($wanted->contains($sediuId) && ! isset($found[$sediuId])) {
                            $found[$sediuId] = [
                                'partener_wm_id' => (string) ($item['idPartener'] ?? ''),
                                'denumire'       => (string) ($item['denumire'] ?? ''),
                            ];
                        }
                    }
                }

                if (! ($r['data']['hasNextPage'] ?? false)) break;
                if (count($found) === $wanted->count()) break;
            }
        } catch (\Throwable $e) {
            Log::warning('[SediuResolver] Scanare nomenclator eșuată: '.$e->getMessage());
        }

        return $found;
    }
}
