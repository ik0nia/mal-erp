<?php

namespace App\Filament\App\Pages;

use App\Models\IntegrationConnection;
use App\Models\WinmentorLivrare;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Url;

class WinmentorLivrariPage extends Page
{
    protected string $view = 'filament.app.pages.winmentor-livrari';

    protected static ?string $navigationLabel = 'Livrări CM';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';
    protected static ?int    $navigationSort  = 90;
    protected static ?string $title = 'Comenzi Livrare — Case de Marcat Mobile';

    #[Url]
    public string $search = '';

    #[Url]
    public string $tab = 'active';

    #[Url]
    public string $perioada = 'azi';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) return null;

        $count = Cache::remember('wm_livrari_comenzi_count', 120, function () {
            try {
                $raw = static::fetchComenziNefacturate();
                $cm = collect($raw)->filter(fn($r) => ($r[14] ?? '') === 'CM');
                return $cm->pluck(2)->unique()->count();
            } catch (\Throwable) {
                return 0;
            }
        });

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getViewData(): array
    {
        if ($this->tab === 'istoric') {
            return $this->getIstoricData();
        }

        return $this->getActiveData();
    }

    private function getActiveData(): array
    {
        try {
            $allRaw = static::fetchComenziNefacturate();
            $raw = collect($allRaw)->filter(fn($r) => ($r[14] ?? '') === 'CM')->values()->toArray();
        } catch (\Throwable $e) {
            return ['error' => 'Nu mă pot conecta la WinMentor Bridge: ' . $e->getMessage(), 'comenzi' => collect(), 'totalLinii' => 0, 'totalComenzi' => 0, 'totalValoare' => '0', 'tab' => $this->tab, 'countIstoric' => $this->countIstoric()];
        }

        // Actualizez cache-ul
        Cache::put('wm_livrari_comenzi_count', collect($raw)->pluck(2)->unique()->count(), 120);

        $parteneriMap = $this->getParteneriMap();
        $artIds = collect($raw)->pluck(3)->unique()->toArray();
        $produseMap = $this->getProduseMap($artIds);

        $linii = collect();
        foreach ($raw as $row) {
            $partId = $row[0] ?? '';
            $isSediu = ! isset($parteneriMap[$partId]);
            $clientName = $parteneriMap[$partId] ?? $this->lookupPartener($partId) ?? $partId;
            $linii->push([
                'nr_comanda'  => $row[2] ?? '',
                'data'        => $row[1] ?? '',
                'part_id'     => $partId,
                'client'      => $clientName,
                'is_sediu'    => $isSediu && $clientName !== $partId,
                'art_id'      => $row[3] ?? '',
                'produs'      => $produseMap[$row[3] ?? ''] ?? ($row[3] ?? ''),
                'cantitate'   => $row[4] ?? 0,
                'pret'        => $row[5] ?? '0',
                'cant_fact'   => $row[6] ?? '0',
                'gestiune'    => $row[8] ?? '',
                'observatii'  => $row[9] ?? '',
                'id_doc'      => $row[10] ?? '',
                'pozitie'     => $row[12] ?? '',
                'tip_doc'     => $row[14] ?? '',
            ]);
        }

        // Grupare pe nr_comanda
        $comenzi = $linii->groupBy('nr_comanda')->map(function ($lines, $nr) {
            $first = $lines->first();
            $total = $lines->sum(fn($l) =>
                (float) str_replace(',', '.', $l['pret']) * (float) str_replace(',', '.', $l['cantitate'])
            );
            $obs = mb_strtoupper($first['observatii'] ?? '');
            $metodaPlata = str_contains($obs, 'CARD') ? 'card'
                : (str_contains($obs, 'CASH') ? 'cash' : null);

            return [
                'nr_comanda'   => $nr,
                'data'         => $first['data'],
                'client'       => $first['client'],
                'part_id'      => $first['part_id'],
                'is_sediu'     => $first['is_sediu'] ?? false,
                'observatii'   => $first['observatii'],
                'metoda_plata' => $metodaPlata,
                'tip_doc'      => $first['tip_doc'],
                'nr_linii'     => $lines->count(),
                'total'        => $total,
                'total_fmt'    => number_format($total, 2, ',', '.'),
                'linii'        => $lines->toArray(),
            ];
        });

        // Search
        if ($this->search) {
            $s = mb_strtolower($this->search);
            $comenzi = $comenzi->filter(fn($cmd) =>
                str_contains(mb_strtolower($cmd['client']), $s) ||
                str_contains($cmd['nr_comanda'], $s) ||
                str_contains(mb_strtolower($cmd['observatii']), $s) ||
                collect($cmd['linii'])->contains(fn($l) => str_contains(mb_strtolower($l['produs']), $s))
            );
        }

        $comenzi = $comenzi->sortByDesc('nr_comanda')->values();

        $totalValoare = $comenzi->sum('total');

        return [
            'error'        => null,
            'comenzi'      => $comenzi,
            'totalLinii'   => $linii->count(),
            'totalComenzi' => $comenzi->count(),
            'totalValoare' => number_format($totalValoare, 2, ',', '.'),
            'tab'          => $this->tab,
            'countLive'    => $comenzi->count(),
            'countIstoric' => $this->countIstoric(),
        ];
    }

    private function getIstoricData(): array
    {
        $query = WinmentorLivrare::query();

        // Filtru perioadă
        $tz = 'Europe/Bucharest';
        match ($this->perioada) {
            'azi'       => $query->where('first_seen_at', '>=', now($tz)->startOfDay()),
            'saptamana' => $query->where('first_seen_at', '>=', now($tz)->startOfWeek()),
            'luna'      => $query->where('first_seen_at', '>=', now($tz)->startOfMonth()),
            default     => null, // 'toate' — fără filtru
        };

        if ($this->search) {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('client_name', 'like', "%{$s}%")
                  ->orWhere('nr_comanda', 'like', "%{$s}%")
                  ->orWhere('observatii', 'like', "%{$s}%")
                  ->orWhere('produs_name', 'like', "%{$s}%")
                  ->orWhere('art_id', 'like', "%{$s}%");
            });
        }

        $livrari = $query->orderByDesc('first_seen_at')->get();

        $parteneriWmIds = \Illuminate\Support\Facades\DB::table('winmentor_parteneri')
            ->pluck('wm_id')
            ->flip()
            ->toArray();

        // Grupăm pe nr_comanda
        $comenzi = $livrari->groupBy('nr_comanda')->map(function ($lines, $nr) use ($parteneriWmIds) {
            $first = $lines->first();
            $total = $lines->sum(fn($l) => (float) $l->cantitate * (float) $l->pret);

            $allDisappeared = $lines->every(fn($l) => $l->disappeared_at !== null);
            $firstSeen = $lines->min('first_seen_at');
            $lastSeen = $lines->max('last_seen_at');
            $disappearedAt = $allDisappeared ? $lines->max('disappeared_at') : null;

            $isSediu = ! isset($parteneriWmIds[$first->part_id]);

            // Durata livrare (first_seen → disappeared)
            $durataMins = ($allDisappeared && $firstSeen && $disappearedAt)
                ? $firstSeen->diffInMinutes($disappearedAt)
                : null;

            // Detectare cash/card din observații
            $obs = mb_strtoupper($first->observatii ?? '');
            $metodaPlata = str_contains($obs, 'CARD') ? 'card'
                : (str_contains($obs, 'CASH') ? 'cash' : null);

            return [
                'nr_comanda'     => $nr,
                'data'           => $first->data_comanda,
                'client'         => $first->client_name ?? $first->part_id,
                'part_id'        => $first->part_id,
                'is_sediu'       => $isSediu && $first->client_name !== null,
                'observatii'     => $first->observatii ?? '',
                'metoda_plata'   => $metodaPlata,
                'tip_doc'        => $first->tip_doc,
                'nr_linii'       => $lines->count(),
                'total'          => $total,
                'total_fmt'      => number_format($total, 2, ',', '.'),
                'first_seen_at'  => $firstSeen?->format('d.m.Y H:i'),
                'last_seen_at'   => $lastSeen?->format('d.m.Y H:i'),
                'disappeared_at' => $disappearedAt?->format('d.m.Y H:i'),
                'durata_mins'    => $durataMins,
                'durata_fmt'     => $durataMins !== null ? ($durataMins >= 60 ? round($durataMins / 60, 1) . 'h' : $durataMins . 'min') : null,
                'is_active'      => ! $allDisappeared,
                'linii'          => $lines->map(fn($l) => [
                    'nr_comanda'     => $l->nr_comanda,
                    'data'           => $l->data_comanda,
                    'part_id'        => $l->part_id,
                    'client'         => $l->client_name ?? $l->part_id,
                    'art_id'         => $l->art_id,
                    'produs'         => $l->produs_name ?? $l->art_id,
                    'cantitate'      => $l->cantitate,
                    'pret'           => $l->pret,
                    'cant_fact'      => $l->cant_facturata,
                    'gestiune'       => $l->gestiune,
                    'observatii'     => $l->observatii,
                    'id_doc'         => $l->id_doc,
                    'pozitie'        => $l->pozitie,
                    'tip_doc'        => $l->tip_doc,
                    'first_seen_at'  => $l->first_seen_at?->format('d.m.Y H:i'),
                    'last_seen_at'   => $l->last_seen_at?->format('d.m.Y H:i'),
                    'disappeared_at' => $l->disappeared_at?->format('d.m.Y H:i'),
                ])->toArray(),
            ];
        })->sortByDesc(fn($cmd) => $cmd['is_active'] ? '9999' : ($cmd['disappeared_at'] ?? ''))->values();

        $totalValoare = $comenzi->sum('total');
        $totalActive = $comenzi->where('is_active', true)->count();
        $totalDisappeared = $comenzi->where('is_active', false)->count();

        // Statistici livrare
        $durate = $comenzi->pluck('durata_mins')->filter();
        $avgDurataMins = $durate->isNotEmpty() ? round($durate->avg()) : null;
        $avgDurataFmt = $avgDurataMins !== null
            ? ($avgDurataMins >= 60 ? round($avgDurataMins / 60, 1) . 'h' : $avgDurataMins . 'min')
            : '—';

        $totalCash = $comenzi->where('metoda_plata', 'cash')->count();
        $totalCard = $comenzi->where('metoda_plata', 'card')->count();
        $valoareCash = $comenzi->where('metoda_plata', 'cash')->sum('total');
        $valoareCard = $comenzi->where('metoda_plata', 'card')->sum('total');

        $countLive = Cache::get('wm_livrari_comenzi_count', '?');

        return [
            'error'            => null,
            'comenzi'          => $comenzi,
            'totalLinii'       => $livrari->count(),
            'totalComenzi'     => $comenzi->count(),
            'totalValoare'     => number_format($totalValoare, 2, ',', '.'),
            'tab'              => $this->tab,
            'countLive'        => $countLive,
            'countIstoric'     => $this->countIstoric(),
            'totalActive'      => $totalActive,
            'totalDisappeared' => $totalDisappeared,
            'avgDurataFmt'     => $avgDurataFmt,
            'totalCash'        => $totalCash,
            'totalCard'        => $totalCard,
            'valoareCash'      => number_format($valoareCash, 2, ',', '.'),
            'valoareCard'      => number_format($valoareCard, 2, ',', '.'),
        ];
    }

    private function countIstoric(): int
    {
        return WinmentorLivrare::distinct('nr_comanda')->count('nr_comanda');
    }

    // ── Data fetching ──

    private static function fetchComenziNefacturate(): array
    {
        $conn = IntegrationConnection::find(5);
        if (! $conn) return [];

        $baseUrl = rtrim($conn->bridgeUrl(), '/');
        $apiKey  = $conn->bridgeApiKey();

        $r = Http::timeout(30)
            ->withHeaders(['X-API-Key' => $apiKey])
            ->get($baseUrl . '/api/comenzi/nefacturate');

        $items = $r->json()['data'] ?? [];

        // MentorAPI returnează named fields; consumatorul așteaptă array pozițional
        return array_map(fn (array $row) => [
            /* [0]  */ $row['idPartener'] ?? '',
            /* [1]  */ $row['dataComanda'] ?? '',
            /* [2]  */ $row['nrComanda'] ?? '',
            /* [3]  */ $row['codArticol'] ?? '',
            /* [4]  */ $row['cantitate'] ?? '',
            /* [5]  */ $row['pret'] ?? '',
            /* [6]  */ $row['cantComanda'] ?? '',
            /* [7]  */ $row['denUM'] ?? '',
            /* [8]  */ $row['marcaAgent'] ?? '',
            /* [9]  */ $row['observatii'] ?? '',
            /* [10] */ $row['nrDocument'] ?? '',
            /* [11] */ $row['codExternAlt'] ?? '',
            /* [12] */ $row['pozitie'] ?? '',
            /* [13] */ $row['dataLivrare'] ?? '',
            /* [14] */ $row['serieDocument'] ?? '',
            /* [15] */ $row['sediuPartener'] ?? '',
            /* [16] */ $row['numePartener'] ?? '',
            /* [17] */ $row['moneda'] ?? '',
        ], $items);
    }

    private function getParteneriMap(): array
    {
        return \Illuminate\Support\Facades\DB::table('winmentor_parteneri')
            ->pluck('denumire', 'wm_id')
            ->toArray();
    }

    private function lookupPartener(string $id): ?string
    {
        $conn = IntegrationConnection::find(5);
        $baseUrl = rtrim($conn->bridgeUrl(), '/');
        $apiKey  = $conn->bridgeApiKey();

        // 1. Lookup direct ca partener
        try {
            $r = Http::timeout(10)->withoutVerifying()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->get($baseUrl . '/api/parteneri/' . $id . '/info');
            $name = $r->json()['data'] ?? null;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        } catch (\Throwable) {
            // continue
        }

        // 2. Fallback: poate fi un ID de sediu — căutăm partenerul care deține acest sediu
        return Cache::remember("wm_sediu_lookup_{$id}", 3600, function () use ($baseUrl, $apiKey, $id) {
            try {
                for ($page = 1; $page <= 50; $page++) {
                    $r = Http::timeout(15)->withoutVerifying()
                        ->withHeaders(['X-API-Key' => $apiKey])
                        ->get($baseUrl . '/api/parteneri', ['page' => $page, 'pageSize' => 500]);

                    $data  = $r->json()['data'] ?? [];
                    $items = $data['items'] ?? [];

                    foreach ($items as $item) {
                        if (in_array($id, $item['denumiriSedii'] ?? [], true)) {
                            return $item['denumire'] ?? null;
                        }
                    }

                    if (! ($data['hasNextPage'] ?? false)) break;
                }
            } catch (\Throwable) {
                // ignore
            }

            return null;
        });
    }

    private function getProduseMap(array $artIds): array
    {
        if (empty($artIds)) return [];
        return \App\Models\WooProduct::whereIn('sku', $artIds)
            ->pluck('name', 'sku')
            ->toArray();
    }
}
