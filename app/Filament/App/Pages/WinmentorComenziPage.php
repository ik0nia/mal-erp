<?php

namespace App\Filament\App\Pages;

use App\Models\IntegrationConnection;
use App\Models\WinmentorComanda;
use App\Models\WooProduct;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Url;

class WinmentorComenziPage extends Page
{
    protected string $view = 'filament.app.pages.winmentor-comenzi';

    protected static ?string $navigationLabel = 'Comenzi clienți';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?int    $navigationSort  = 91;
    protected static ?string $title = 'Comenzi Clienți — deschise & facturate';

    #[Url]
    public string $search = '';

    #[Url]
    public string $tab = 'active';

    #[Url]
    public string $perioada = 'luna';

    #[Url]
    public string $stare = 'toate'; // toate | deschise | facturate

    public static function canAccess(): bool
    {
        return auth()->user()?->email === 'codrut@ikonia.ro';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) return null;

        $count = Cache::remember('wm_comenzi_count', 120, function () {
            try {
                $raw = static::fetchComenziNefacturate();
                $nonCm = collect($raw)->filter(fn($r) => ($r[14] ?? '') !== 'CM');
                return $nonCm->pluck(2)->unique()->count();
            } catch (\Throwable) {
                return 0;
            }
        });

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
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
            $raw = collect($allRaw)->filter(fn($r) => ($r[14] ?? '') !== 'CM')->values()->toArray();
        } catch (\Throwable $e) {
            return ['error' => 'Nu mă pot conecta la WinMentor Bridge: ' . $e->getMessage(), 'comenzi' => collect(), 'totalLinii' => 0, 'totalComenzi' => 0, 'totalValoare' => '0', 'tab' => $this->tab, 'countIstoric' => $this->countIstoric()];
        }

        Cache::put('wm_comenzi_count', collect($raw)->pluck(2)->unique()->count(), 120);

        $parteneriMap = DB::table('winmentor_parteneri')->pluck('denumire', 'wm_id')->toArray();
        $artIds = collect($raw)->pluck(3)->unique()->toArray();
        $produseMap = empty($artIds) ? [] : WooProduct::whereIn('sku', $artIds)->pluck('name', 'sku')->toArray();

        $linii = collect();
        foreach ($raw as $row) {
            $partId = $row[0] ?? '';
            $clientName = $parteneriMap[$partId] ?? $partId;
            $linii->push([
                'nr_comanda' => $row[2] ?? '',
                'data'       => $row[1] ?? '',
                'part_id'    => $partId,
                'client'     => $clientName,
                'art_id'     => $row[3] ?? '',
                'produs'     => $produseMap[$row[3] ?? ''] ?? ($row[3] ?? ''),
                'cantitate'  => $row[4] ?? 0,
                'pret'       => $row[5] ?? '0',
                'gestiune'   => $row[8] ?? '',
                'observatii' => $row[9] ?? '',
                'id_doc'     => $row[10] ?? '',
                'pozitie'    => $row[12] ?? '',
                'serie'      => $row[14] ?? '',
            ]);
        }

        $comenzi = $linii->groupBy('nr_comanda')->map(function ($lines, $nr) {
            $first = $lines->first();
            $total = $lines->sum(fn($l) =>
                (float) str_replace(',', '.', $l['pret']) * (float) str_replace(',', '.', $l['cantitate'])
            );

            return [
                'nr_comanda' => $nr,
                'data'       => $first['data'],
                'client'     => $first['client'],
                'part_id'    => $first['part_id'],
                'observatii' => $first['observatii'],
                'serie'      => $first['serie'],
                'is_deschisa' => true,
                'nr_linii'   => $lines->count(),
                'total'      => $total,
                'total_fmt'  => number_format($total, 2, ',', '.'),
                'linii'      => $lines->toArray(),
            ];
        });

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

        return [
            'error'        => null,
            'comenzi'      => $comenzi,
            'totalLinii'   => $linii->count(),
            'totalComenzi' => $comenzi->count(),
            'totalValoare' => number_format($comenzi->sum('total'), 2, ',', '.'),
            'tab'          => $this->tab,
            'countLive'    => $comenzi->count(),
            'countIstoric' => $this->countIstoric(),
            'totalDeschise' => $comenzi->count(),
            'totalFacturate' => 0,
        ];
    }

    private function getIstoricData(): array
    {
        $query = WinmentorComanda::query();

        $tz = 'Europe/Bucharest';
        match ($this->perioada) {
            'azi'       => $query->where('first_seen_at', '>=', now($tz)->startOfDay()),
            'saptamana' => $query->where('first_seen_at', '>=', now($tz)->startOfWeek()),
            'luna'      => $query->where('first_seen_at', '>=', now($tz)->startOfMonth()),
            default     => null,
        };

        match ($this->stare) {
            'deschise'  => $query->whereNull('disappeared_at'),
            'facturate' => $query->whereNotNull('disappeared_at'),
            default     => null,
        };

        if ($this->search) {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('client_name', 'like', "%{$s}%")
                  ->orWhere('nr_comanda', 'like', "%{$s}%")
                  ->orWhere('observatii', 'like', "%{$s}%")
                  ->orWhere('produs_name', 'like', "%{$s}%")
                  ->orWhere('art_id', 'like', "%{$s}%")
                  ->orWhere('factura_nr', 'like', "%{$s}%");
            });
        }

        $comenziRaw = $query->orderByDesc('first_seen_at')->get();

        $comenzi = $comenziRaw->groupBy('nr_comanda')->map(function ($lines, $nr) {
            $first = $lines->first();
            $total = $lines->sum(fn($l) => (float) $l->cantitate * (float) $l->pret);

            $allInchise = $lines->every(fn($l) => $l->disappeared_at !== null);
            $firstSeen = $lines->min('first_seen_at');
            $lastSeen = $lines->max('last_seen_at');
            $disappearedAt = $allInchise ? $lines->max('disappeared_at') : null;

            // Documentele de facturare distincte găsite euristic pe liniile comenzii
            $docs = $lines
                ->filter(fn($l) => $l->factura_nr)
                ->map(fn($l) => [
                    'tip'     => $l->factura_tip,
                    'nr'      => $l->factura_nr,
                    'serie'   => $l->factura_serie,
                    'data'    => $l->factura_data,
                    'estimat' => (bool) $l->factura_estimat,
                ])
                ->unique(fn($d) => $d['serie'] . '|' . $d['nr'])
                ->values()
                ->toArray();

            return [
                'nr_comanda'     => $nr,
                'data'           => $first->data_comanda,
                'client'         => $first->client_name ?? $first->part_id,
                'part_id'        => $first->part_id,
                'observatii'     => $first->observatii ?? '',
                'serie'          => $first->serie,
                'nr_linii'       => $lines->count(),
                'total'          => $total,
                'total_fmt'      => number_format($total, 2, ',', '.'),
                'first_seen_at'  => $firstSeen?->format('d.m.Y H:i'),
                'last_seen_at'   => $lastSeen?->format('d.m.Y H:i'),
                'disappeared_at' => $disappearedAt?->format('d.m.Y H:i'),
                'is_deschisa'    => ! $allInchise,
                'docs'           => $docs,
                'linii'          => $lines->map(fn($l) => [
                    'art_id'         => $l->art_id,
                    'produs'         => $l->produs_name ?? $l->art_id,
                    'cantitate'      => $l->cantitate,
                    'pret'           => $l->pret,
                    'pozitie'        => $l->pozitie,
                    'factura_tip'    => $l->factura_tip,
                    'factura_nr'     => $l->factura_nr,
                    'factura_serie'  => $l->factura_serie,
                    'factura_data'   => $l->factura_data,
                    'factura_estimat'=> (bool) $l->factura_estimat,
                    'disappeared_at' => $l->disappeared_at?->format('d.m.Y H:i'),
                ])->toArray(),
            ];
        })->sortByDesc(fn($cmd) => $cmd['is_deschisa'] ? '9999' : ($cmd['disappeared_at'] ?? ''))->values();

        return [
            'error'          => null,
            'comenzi'        => $comenzi,
            'totalLinii'     => $comenziRaw->count(),
            'totalComenzi'   => $comenzi->count(),
            'totalValoare'   => number_format($comenzi->sum('total'), 2, ',', '.'),
            'tab'            => $this->tab,
            'countLive'      => Cache::get('wm_comenzi_count', '?'),
            'countIstoric'   => $this->countIstoric(),
            'totalDeschise'  => $comenzi->where('is_deschisa', true)->count(),
            'totalFacturate' => $comenzi->where('is_deschisa', false)->count(),
        ];
    }

    private function countIstoric(): int
    {
        return WinmentorComanda::distinct('nr_comanda')->count('nr_comanda');
    }

    private static function fetchComenziNefacturate(): array
    {
        $conn = IntegrationConnection::find(5);
        if (! $conn) return [];

        $r = Http::timeout(30)->withoutVerifying()
            ->withHeaders(['X-API-Key' => $conn->bridgeApiKey()])
            ->get(rtrim($conn->bridgeUrl(), '/') . '/api/comenzi/nefacturate');

        $items = $r->json()['data'] ?? [];

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
}
