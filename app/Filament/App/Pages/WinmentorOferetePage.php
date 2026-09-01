<?php

namespace App\Filament\App\Pages;

use App\Models\IntegrationConnection;
use App\Services\Winmentor\WinmentorBridgeClient;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Url;

class WinmentorOferetePage extends Page
{
    protected string $view = 'filament.app.pages.winmentor-oferte';

    protected static ?string $navigationLabel = 'Oferte & Comenzi';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';
    protected static ?int    $navigationSort  = 93;
    protected static ?string $title = 'Oferte & Comenzi Clienți — WinMentor';

    #[Url]
    public string $tab = 'comenzi';

    #[Url]
    public int $luna = 0;

    #[Url]
    public int $an = 0;

    #[Url]
    public string $searchOferte = '';

    #[Url]
    public string $filterActiv = 'toate';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        if ($this->luna === 0) $this->luna = (int) now()->format('n');
        if ($this->an === 0) $this->an = (int) now()->format('Y');
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function updatedLuna(): void
    {
        // Force re-render
    }

    public function updatedAn(): void
    {
        // Force re-render
    }

    public function getViewData(): array
    {
        try {
            $client = app(WinmentorBridgeClient::class);
        } catch (\Throwable $e) {
            return [
                'error' => 'Nu mă pot conecta la WinMentor Bridge: ' . $e->getMessage(),
                'lunaLabel' => '',
            ];
        }

        // Selectăm luna dorită în Bridge
        $this->selectLuna();

        $lunaLabel = $this->numeLuna($this->luna) . ' ' . $this->an;
        $data = [];

        if ($this->tab === 'comenzi') {
            $data = $this->loadComenzi($client);
        } elseif ($this->tab === 'oferte') {
            $data = $this->loadOferte($client);
        } elseif ($this->tab === 'conditii') {
            $data = $this->loadConditii($client);
        } elseif ($this->tab === 'discounturi') {
            $data = $this->loadDiscounturi($client);
        }

        return array_merge($data, ['error' => null, 'lunaLabel' => $lunaLabel]);
    }

    private function selectLuna(): void
    {
        $conn = $this->getConn();
        $baseUrl = rtrim($conn->bridgeUrl(), '/');
        $apiKey  = $conn->bridgeApiKey();

        Http::timeout(10)->withoutVerifying()
            ->withHeaders(['X-API-Key' => $apiKey])
            ->post($baseUrl . '/api/firme/select', [
                'firma' => $conn->settings['firma'] ?? 'MAL2019',
                'luna'  => $this->luna,
                'an'    => $this->an,
            ]);
    }

    private function loadComenzi(WinmentorBridgeClient $client): array
    {
        $raw = array_map(fn (array $row) => [
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
        ], $this->bridgeGet('/api/comenzi/nefacturate'));

        $parteneri = $this->getParteneriMap();

        // Rezolvă partenerii lipsă prin lookup individual
        $missingIds = collect($raw)->pluck(0)->unique()
            ->filter(fn($id) => $id && !isset($parteneri[$id]))->values();
        foreach ($missingIds as $id) {
            $parteneri[$id] = $this->lookupPartener($id) ?? $id;
        }

        $produse = $this->getProduseMap(collect($raw)->pluck(3)->unique()->toArray());

        $comenzi = collect();
        foreach ($raw as $row) {
            $comenzi->push([
                'nr_comanda'  => $row[2] ?? '',
                'data'        => $row[1] ?? '',
                'part_id'     => $row[0] ?? '',
                'client'      => $parteneri[$row[0]] ?? $row[0],
                'art_id'      => $row[3] ?? '',
                'produs'      => $produse[$row[3]] ?? $row[3],
                'cantitate'   => $row[4] ?? 0,
                'pret'        => $row[5] ?? '0',
                'cant_fact'   => $row[6] ?? 0,
                'observatii'  => $row[9] ?? '',
                'pozitie'     => $row[12] ?? '',
                'tip_doc'     => $row[14] ?? '',
                'id_doc'      => $row[10] ?? '',
            ]);
        }

        $grouped = $comenzi->groupBy('nr_comanda')->map(function ($lines, $nr) {
            $first = $lines->first();
            $total = $lines->sum(function ($l) {
                return (float) str_replace(',', '.', $l['pret']) * (float) str_replace(',', '.', $l['cantitate']);
            });
            return [
                'nr_comanda' => $nr,
                'data'       => $first['data'],
                'client'     => $first['client'],
                'part_id'    => $first['part_id'],
                'observatii' => $first['observatii'],
                'tip_doc'    => $first['tip_doc'],
                'nr_linii'   => $lines->count(),
                'total'      => number_format($total, 2, ',', '.'),
                'linii'      => $lines->toArray(),
            ];
        })->sortByDesc('nr_comanda')->values();

        return ['comenzi' => $grouped, 'totalComenzi' => count($raw)];
    }

    private function loadOferte(WinmentorBridgeClient $client): array
    {
        $raw = $this->bridgeGet('/api/oferte');

        $parteneri = $this->getParteneriMap();

        // Rezolvă partenerii lipsă
        $missingIds = collect($raw)->pluck('partID')->unique()
            ->filter(fn($id) => $id && !isset($parteneri[$id]))->values();
        foreach ($missingIds as $id) {
            $parteneri[$id] = $this->lookupPartener($id) ?? $id;
        }

        $artIds = collect($raw)->pluck('artID')->unique()->toArray();
        $produse = $this->getProduseMap($artIds);

        $oferte = collect();
        $today = Carbon::today();

        foreach ($raw as $row) {
            $sfarsit = $this->parseDate($row['dataSfarsit'] ?? '');
            $activ = $sfarsit && $sfarsit->gte($today);

            $oferte->push([
                'part_id'      => $row['partID'],
                'client'       => $parteneri[$row['partID']] ?? $row['partID'],
                'art_id'       => $row['artID'],
                'produs'       => $produse[$row['artID']] ?? $row['artID'],
                'data_inceput' => $row['dataInceput'] ?? '',
                'data_sfarsit' => $row['dataSfarsit'] ?? '',
                'pret'         => $row['pret'] ?? '',
                'cantitate'    => $row['cantitate'] ?? '',
                'activ'        => $activ,
            ]);
        }

        if ($this->filterActiv === 'active') {
            $oferte = $oferte->where('activ', true);
        } elseif ($this->filterActiv === 'expirate') {
            $oferte = $oferte->where('activ', false);
        }

        if ($this->searchOferte) {
            $s = mb_strtolower($this->searchOferte);
            $oferte = $oferte->filter(fn($o) =>
                str_contains(mb_strtolower($o['client']), $s) ||
                str_contains(mb_strtolower($o['produs']), $s) ||
                str_contains($o['art_id'], $s) ||
                str_contains($o['part_id'], $s)
            );
        }

        $oferte = $oferte->sortByDesc('activ')->values();

        $stats = [
            'total'     => count($raw),
            'active'    => collect($raw)->filter(fn($r) => ($sf = $this->parseDate($r['dataSfarsit'] ?? '')) && $sf->gte($today))->count(),
            'parteneri' => collect($raw)->pluck('partID')->unique()->count(),
            'produse'   => collect($raw)->pluck('artID')->unique()->count(),
        ];

        return ['oferte' => $oferte, 'oferteStats' => $stats];
    }

    private function loadConditii(WinmentorBridgeClient $client): array
    {
        $raw = $this->bridgeGet('/api/oferte/clienti');

        $parteneri = $this->getParteneriMap();

        $missingIds = collect($raw)->pluck(0)->unique()
            ->filter(fn($id) => $id && !isset($parteneri[$id]))->values();
        foreach ($missingIds as $id) {
            $parteneri[$id] = $this->lookupPartener($id) ?? $id;
        }

        $artIds = collect($raw)->pluck(1)->unique()->toArray();
        $produse = $this->getProduseMap($artIds);

        $conditii = collect();
        foreach ($raw as $row) {
            $partId = $row[0] ?? null;
            $artId  = $row[1] ?? null;
            $conditii->push([
                'part_id'  => $partId ?? '',
                'client'   => ($partId !== null ? ($parteneri[$partId] ?? $partId) : ''),
                'art_id'   => $artId ?? '',
                'produs'   => ($artId !== null ? ($produse[$artId] ?? $artId) : ''),
                'moneda'   => $row[8] ?? '',
                'conditie' => $row[9] ?? '',
            ]);
        }

        return ['conditii' => $conditii];
    }

    private function loadDiscounturi(WinmentorBridgeClient $client): array
    {
        $raw = $this->bridgeGet('/api/discount/pe-articole');

        $artIds = collect($raw)->pluck(1)->unique()->toArray();
        $produse = $this->getProduseMap($artIds);

        $grouped = collect($raw)->groupBy(0)->map(function ($rows, $criteriu) use ($produse) {
            return [
                'criteriu' => $criteriu,
                'articole' => $rows->map(fn($r) => [
                    'art_id'   => $r[1],
                    'produs'   => $produse[$r[1]] ?? $r[1],
                    'discount' => $r[2],
                ])->toArray(),
            ];
        })->values();

        return ['discounturi' => $grouped];
    }

    // ── Helpers ──

    private function getConn(): IntegrationConnection
    {
        return IntegrationConnection::find(5);
    }

    private function bridgeGet(string $path): array
    {
        $conn = $this->getConn();
        $baseUrl = rtrim($conn->bridgeUrl(), '/');
        $apiKey  = $conn->bridgeApiKey();

        $r = Http::timeout(30)
            ->withoutVerifying()
            ->withHeaders(['X-API-Key' => $apiKey])
            ->get($baseUrl . $path);

        return $r->json()['data'] ?? [];
    }

    private function getParteneriMap(): array
    {
        return \Illuminate\Support\Facades\DB::table('winmentor_parteneri')
            ->pluck('denumire', 'wm_id')
            ->toArray();
    }

    private function lookupPartener(string $id): ?string
    {
        $conn = $this->getConn();
        $baseUrl = rtrim($conn->bridgeUrl(), '/');
        $apiKey  = $conn->bridgeApiKey();

        try {
            $r = Http::timeout(10)->withoutVerifying()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->get($baseUrl . '/api/parteneri/' . $id . '/info');
            $name = $r->json()['data'] ?? null;
            return is_string($name) && $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getProduseMap(array $artIds): array
    {
        if (empty($artIds)) return [];
        return \App\Models\WooProduct::whereIn('sku', $artIds)
            ->pluck('name', 'sku')
            ->toArray();
    }

    private function parseDate(?string $date): ?Carbon
    {
        if (!$date) return null;
        try {
            return Carbon::createFromFormat('d.m.Y', $date);
        } catch (\Throwable) {
            return null;
        }
    }

    private function numeLuna(int $luna): string
    {
        return [
            1 => 'Ianuarie', 2 => 'Februarie', 3 => 'Martie', 4 => 'Aprilie',
            5 => 'Mai', 6 => 'Iunie', 7 => 'Iulie', 8 => 'August',
            9 => 'Septembrie', 10 => 'Octombrie', 11 => 'Noiembrie', 12 => 'Decembrie',
        ][$luna] ?? '';
    }
}
