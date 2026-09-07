<?php

namespace App\Filament\App\Pages;

use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class WinmentorVanzariPage extends Page
{
    protected string $view = 'filament.app.pages.winmentor-vanzari';

    protected static ?string $navigationLabel = 'Vânzări';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
    protected static ?int    $navigationSort  = 91;
    protected static ?string $title = 'Vânzări WinMentor';

    public string $dateFrom   = '';
    public string $dateTo     = '';
    public string $tipDoc     = '';
    public string $search     = '';
    public string $gestiune   = '';
    public string $agent      = '';
    public string $statusPlata = '';
    public string $sortBy     = 'recent';
    public int    $page       = 1;
    public int    $perPage    = 50;

    protected $queryString = ['dateFrom', 'dateTo', 'tipDoc', 'search', 'gestiune', 'agent', 'statusPlata', 'sortBy', 'page'];

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
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo   = now()->format('Y-m-d');
    }

    /**
     * Buton „Actualizează din WinMentor" — rulează watch-ul la cerere (~5-10s)
     * și raportează câte linii noi au apărut. Lock-ul din comandă previne
     * suprapunerea cu rularea din cron.
     */
    public function refreshFromWinmentor(): void
    {
        $bridge = new \App\Services\Winmentor\WinmentorBridgeClient;

        if (! ($bridge->health()['data']['comConnected'] ?? false)) {
            \Filament\Notifications\Notification::make()
                ->title('WinMentor indisponibil')
                ->body('Conexiunea COM nu e activă — încearcă mai târziu.')
                ->warning()->send();
            return;
        }

        $start = now();

        try {
            \Illuminate\Support\Facades\Artisan::call('winmentor:watch-vanzari', ['--firma' => 'MAL2019']);
        } catch (\Throwable $e) {
            \Filament\Notifications\Notification::make()
                ->title('Eroare la actualizare')
                ->body($e->getMessage())
                ->danger()->send();
            return;
        }

        // created_at e păstrat la reinserare pentru rândurile existente,
        // deci tot ce e >= $start sunt linii cu adevărat noi
        $newLines = DB::table('winmentor_vanzari_raw')->where('created_at', '>=', $start)->count();

        \Filament\Notifications\Notification::make()
            ->title($newLines > 0 ? "{$newLines} linii noi aduse din WinMentor" : 'Nimic nou în WinMentor')
            ->{$newLines > 0 ? 'success' : 'info'}()
            ->send();
    }

    public function updatedSearch(): void   { $this->page = 1; }
    public function updatedDateFrom(): void  { $this->page = 1; }
    public function updatedDateTo(): void    { $this->page = 1; }
    public function updatedTipDoc(): void    { $this->page = 1; }
    public function updatedGestiune(): void    { $this->page = 1; }
    public function updatedAgent(): void       { $this->page = 1; }
    public function updatedStatusPlata(): void { $this->page = 1; }
    public function updatedSortBy(): void      { $this->page = 1; }

    // Navigare rapidă: azi, ieri, luna curentă, luna anterioară
    public function goToday(): void
    {
        $this->dateFrom = now()->format('Y-m-d');
        $this->dateTo   = now()->format('Y-m-d');
        $this->page = 1;
    }

    public function goYesterday(): void
    {
        $this->dateFrom = now()->subDay()->format('Y-m-d');
        $this->dateTo   = now()->subDay()->format('Y-m-d');
        $this->page = 1;
    }

    public function goThisMonth(): void
    {
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo   = now()->format('Y-m-d');
        $this->page = 1;
    }

    public function goLastMonth(): void
    {
        $this->dateFrom = now()->subMonth()->startOfMonth()->format('Y-m-d');
        $this->dateTo   = now()->subMonth()->endOfMonth()->format('Y-m-d');
        $this->page = 1;
    }

    public function goPrevDay(): void
    {
        $d = Carbon::parse($this->dateFrom)->subDay();
        $this->dateFrom = $d->format('Y-m-d');
        $this->dateTo   = $d->format('Y-m-d');
        $this->page = 1;
    }

    public function goNextDay(): void
    {
        $d = Carbon::parse($this->dateTo)->addDay();
        $this->dateFrom = $d->format('Y-m-d');
        $this->dateTo   = $d->format('Y-m-d');
        $this->page = 1;
    }

    public function goPrevMonth(): void
    {
        $d = Carbon::parse($this->dateFrom)->subMonth();
        $this->dateFrom = $d->startOfMonth()->format('Y-m-d');
        $this->dateTo   = $d->endOfMonth()->format('Y-m-d');
        $this->page = 1;
    }

    public function goNextMonth(): void
    {
        $d = Carbon::parse($this->dateFrom)->addMonth();
        $this->dateFrom = $d->startOfMonth()->format('Y-m-d');
        $this->dateTo   = min($d->endOfMonth(), now())->format('Y-m-d');
        $this->page = 1;
    }

    /**
     * Stat cards: total vânzări per tip document — reflectă perioada selectată.
     */
    public function getStats(): array
    {
        $q = DB::table('winmentor_vanzari_raw')
            ->where('firma', 'MAL2019');

        // Aplicăm aceleași filtre de dată ca buildBaseQuery
        if ($this->dateFrom) {
            $from = Carbon::parse($this->dateFrom);
            $q->where(function ($q2) use ($from) {
                $q2->where('an', '>', $from->year)
                   ->orWhere(fn ($q3) => $q3->where('an', $from->year)->where('luna', '>', $from->month))
                   ->orWhere(fn ($q3) => $q3->where('an', $from->year)->where('luna', $from->month)->where('zi', '>=', $from->day));
            });
        }
        if ($this->dateTo) {
            $to = Carbon::parse($this->dateTo);
            $q->where(function ($q2) use ($to) {
                $q2->where('an', '<', $to->year)
                   ->orWhere(fn ($q3) => $q3->where('an', $to->year)->where('luna', '<', $to->month))
                   ->orWhere(fn ($q3) => $q3->where('an', $to->year)->where('luna', $to->month)->where('zi', '<=', $to->day));
            });
        }

        // Bonuri: tip S sau nr 2xxxxx (indiferent de tip_document)
        // Avize: tip AE, sau (tip = sau null) cu nr 1xxxxx
        // Facturi: tip F, sau (tip = sau null) cu nr xxxxx (5 cifre, nu 2xxxxx)
        $stats = (clone $q)->selectRaw("
            COUNT(DISTINCT CASE WHEN tip_document = 'AE' OR (tip_document IN ('=') AND nr_factura REGEXP '^1[0-9]{5}$') OR (tip_document IS NULL AND nr_factura REGEXP '^1[0-9]{5}$') THEN nr_factura END) as nr_avize,
            COUNT(DISTINCT CASE WHEN tip_document = 'F' OR (tip_document IN ('=') AND nr_factura REGEXP '^[0-9]{5}$' AND nr_factura NOT REGEXP '^2') OR (tip_document IS NULL AND nr_factura REGEXP '^[0-9]{5}$' AND nr_factura NOT REGEXP '^2') THEN nr_factura END) as nr_facturi,
            COUNT(DISTINCT CASE WHEN tip_document = 'S' OR nr_factura REGEXP '^2[0-9]{5}$' THEN nr_factura END) as nr_bonuri,
            ROUND(SUM(CASE WHEN tip_document = 'AE' OR (tip_document IN ('=') AND nr_factura REGEXP '^1[0-9]{5}$') OR (tip_document IS NULL AND nr_factura REGEXP '^1[0-9]{5}$') THEN cantitate * pret ELSE 0 END), 2) as val_avize,
            ROUND(SUM(CASE WHEN tip_document = 'F' OR (tip_document IN ('=') AND nr_factura REGEXP '^[0-9]{5}$' AND nr_factura NOT REGEXP '^2') OR (tip_document IS NULL AND nr_factura REGEXP '^[0-9]{5}$' AND nr_factura NOT REGEXP '^2') THEN cantitate * pret ELSE 0 END), 2) as val_facturi,
            ROUND(SUM(CASE WHEN tip_document = 'S' OR nr_factura REGEXP '^2[0-9]{5}$' THEN cantitate * pret ELSE 0 END), 2) as val_bonuri,
            ROUND(SUM(cantitate * pret), 2) as val_total
        ")->first();

        // Facturi restante (scadență depășită, fără încasare identificată)
        $restante = (clone $q)
            ->whereIn('tip_document', ['F', 'AE'])
            ->whereNotNull('data_scadenta')
            ->whereRaw("STR_TO_DATE(data_scadenta, '%d.%m.%Y') < CURDATE()")
            ->selectRaw("COUNT(DISTINCT nr_factura) as nr_restante, ROUND(SUM(cantitate * pret), 2) as val_restante")
            ->first();

        $stats->nr_restante  = $restante->nr_restante ?? 0;
        $stats->val_restante = $restante->val_restante ?? 0;

        return (array) $stats;
    }

    private function resolvedTipDoc(): string
    {
        // Bonuri: nr 2xxxxx (întotdeauna) sau tip S fără nr aviz/factură
        // Avize: tip AE sau nr 1xxxxx (dacă nu e deja tip S)
        // Facturi: tip F sau nr xxxxx (5 cifre, nu începe cu 2)
        return "CASE
            WHEN nr_factura REGEXP '^2[0-9]{5}$' THEN 'Bon casă'
            WHEN tip_document = 'S' THEN 'Bon casă'
            WHEN tip_document = 'AE' THEN 'Aviz'
            WHEN tip_document = 'F' THEN 'Factură'
            WHEN nr_factura REGEXP '^1[0-9]{5}$' THEN 'Aviz'
            WHEN nr_factura REGEXP '^[0-9]{5}$' THEN 'Factură'
            ELSE 'Alt document'
        END";
    }

    private function buildBaseQuery()
    {
        $tipDocExpr = $this->resolvedTipDoc();

        $q = DB::table('winmentor_vanzari_raw')
            ->select([
                'nr_factura', 'an', 'luna',
                DB::raw('MIN(zi) as zi'),
                DB::raw('MIN(part_id) as part_id'),
                DB::raw('MIN(cod_fiscal_client) as cod_fiscal_client'),
                DB::raw('MIN(adresa_client) as adresa_client'),
                DB::raw('COUNT(*) as nr_linii'),
                DB::raw('ROUND(SUM(cantitate * pret), 2) as total'),
                DB::raw('MIN(created_at) as detected_at'),
                DB::raw("({$tipDocExpr}) as tip_doc"),
                DB::raw('MIN(serie_document) as serie_document'),
                DB::raw('MIN(observatii_factura) as observatii'),
                DB::raw('MIN(localitate_client) as localitate_client'),
                DB::raw('MIN(marca_agent) as marca_agent'),
                DB::raw('MIN(valoare_factura) as valoare_factura'),
                DB::raw('MIN(data_scadenta) as data_scadenta'),
                DB::raw('MIN(data_emitere) as data_emitere'),
                DB::raw('MIN(cota_tva) as cota_tva'),
            ])
            ->where('firma', 'MAL2019')
            ->groupBy('nr_factura', 'an', 'luna');

        if (! $this->search) {
            if ($this->dateFrom) {
                $from = Carbon::parse($this->dateFrom);
                $q->where(function ($q2) use ($from) {
                    $q2->where('an', '>', $from->year)
                       ->orWhere(fn ($q3) => $q3->where('an', $from->year)->where('luna', '>', $from->month))
                       ->orWhere(fn ($q3) => $q3->where('an', $from->year)->where('luna', $from->month)->where('zi', '>=', $from->day));
                });
            }

            if ($this->dateTo) {
                $to = Carbon::parse($this->dateTo);
                $q->where(function ($q2) use ($to) {
                    $q2->where('an', '<', $to->year)
                       ->orWhere(fn ($q3) => $q3->where('an', $to->year)->where('luna', '<', $to->month))
                       ->orWhere(fn ($q3) => $q3->where('an', $to->year)->where('luna', $to->month)->where('zi', '<=', $to->day));
                });
            }
        }

        match ($this->tipDoc) {
            'aviz'     => $q->where(fn ($q2) => $q2->where('tip_document', 'AE')->orWhere(fn ($q3) => $q3->whereIn('tip_document', ['=', null])->whereRaw("nr_factura REGEXP '^1[0-9]{5}$'"))),
            'factura'  => $q->where(fn ($q2) => $q2->where('tip_document', 'F')->orWhere(fn ($q3) => $q3->whereIn('tip_document', ['=', null])->whereRaw("nr_factura REGEXP '^[0-9]{5}$'"))),
            'bon_casa' => $q->where(fn ($q2) => $q2->where('tip_document', 'S')->orWhere(fn ($q3) => $q3->whereNull('tip_document')->whereRaw("nr_factura REGEXP '^2[0-9]{5}$'"))),
            default    => null,
        };

        // Status plată: restant = factură cu scadență depășită
        match ($this->statusPlata) {
            'restant' => $q->where('tip_document', 'F')
                          ->whereNotNull('data_scadenta')
                          ->whereRaw("STR_TO_DATE(data_scadenta, '%d.%m.%Y') < CURDATE()"),
            'scadent' => $q->where('tip_document', 'F')
                          ->whereNotNull('data_scadenta')
                          ->whereRaw("STR_TO_DATE(data_scadenta, '%d.%m.%Y') >= CURDATE()"),
            default   => null,
        };

        if ($this->gestiune) {
            $q->where('den_gestiune', $this->gestiune);
        }

        if ($this->agent) {
            $q->where('marca_agent', $this->agent);
        }

        if ($this->search) {
            $s = '%' . $this->search . '%';

            $matchedCuis = \App\Models\Customer::where('name', 'like', $s)
                ->whereNotNull('winmentor_id')
                ->pluck('winmentor_id')
                ->all();

            $wmPartners = DB::table('winmentor_parteneri')
                ->where('denumire', 'like', $s)
                ->pluck('wm_id')
                ->all();

            $q->where(function ($q2) use ($s, $matchedCuis, $wmPartners) {
                $q2->where('nr_factura', 'like', $s)
                   ->orWhere('cod_fiscal_client', 'like', $s)
                   ->orWhere('observatii_factura', 'like', $s)
                   ->orWhere('localitate_client', 'like', $s)
                   ->orWhere('den_articol', 'like', $s)
                   ->orWhere('sku', 'like', $s);
                if (! empty($matchedCuis)) {
                    $q2->orWhereIn('cod_fiscal_client', $matchedCuis);
                }
                if (! empty($wmPartners)) {
                    $q2->orWhereIn('part_id', $wmPartners);
                }
            });
        }

        return $q;
    }

    /**
     * Grafic vanzari pe zile (luna curenta filtrata).
     */
    public function getDailyChart(): array
    {
        $q = DB::table('winmentor_vanzari_raw')
            ->where('firma', 'MAL2019');

        if ($this->dateFrom) {
            $from = Carbon::parse($this->dateFrom);
            $q->where(function ($q2) use ($from) {
                $q2->where('an', '>', $from->year)
                   ->orWhere(fn ($q3) => $q3->where('an', $from->year)->where('luna', '>', $from->month))
                   ->orWhere(fn ($q3) => $q3->where('an', $from->year)->where('luna', $from->month)->where('zi', '>=', $from->day));
            });
        }
        if ($this->dateTo) {
            $to = Carbon::parse($this->dateTo);
            $q->where(function ($q2) use ($to) {
                $q2->where('an', '<', $to->year)
                   ->orWhere(fn ($q3) => $q3->where('an', $to->year)->where('luna', '<', $to->month))
                   ->orWhere(fn ($q3) => $q3->where('an', $to->year)->where('luna', $to->month)->where('zi', '<=', $to->day));
            });
        }

        $data = $q->selectRaw("
                an, luna, zi,
                ROUND(SUM(CASE WHEN tip_document IN ('AE') OR (tip_document IN ('=') AND nr_factura REGEXP '^1[0-9]{5}$') THEN cantitate * pret ELSE 0 END), 0) as avize,
                ROUND(SUM(CASE WHEN tip_document = 'F' OR (tip_document IN ('=') AND nr_factura REGEXP '^[0-9]{5}$' AND nr_factura NOT REGEXP '^2') THEN cantitate * pret ELSE 0 END), 0) as facturi,
                ROUND(SUM(CASE WHEN tip_document = 'S' OR nr_factura REGEXP '^2[0-9]{5}$' THEN cantitate * pret ELSE 0 END), 0) as bonuri
            ")
            ->groupByRaw('an, luna, zi')
            ->orderByRaw('an, luna, zi')
            ->get();

        return [
            'labels'  => $data->map(fn ($r) => sprintf('%02d.%02d', $r->zi, $r->luna))->values()->all(),
            'avize'   => $data->pluck('avize')->map(fn ($v) => (float) $v)->all(),
            'facturi' => $data->pluck('facturi')->map(fn ($v) => (float) $v)->all(),
            'bonuri'  => $data->pluck('bonuri')->map(fn ($v) => (float) $v)->all(),
        ];
    }

    /**
     * Liste pentru dropdown-uri filtre.
     */
    public function getGestiuni(): array
    {
        return DB::table('winmentor_vanzari_raw')
            ->where('firma', 'MAL2019')
            ->whereNotNull('den_gestiune')
            ->where('den_gestiune', '!=', '')
            ->distinct()
            ->pluck('den_gestiune')
            ->sort()
            ->all();
    }

    public function getAgenti(): array
    {
        $marci = DB::table('winmentor_vanzari_raw')
            ->where('firma', 'MAL2019')
            ->whereNotNull('marca_agent')
            ->where('marca_agent', '!=', '')
            ->distinct()
            ->pluck('marca_agent')
            ->all();

        $personalMap = \Illuminate\Support\Facades\Cache::get('wm_personal_map', []);

        return collect($marci)->map(fn ($m) => [
            'marca' => $m,
            'name'  => $personalMap[$m] ?? "Agent #{$m}",
        ])->sortBy('name')->values()->all();
    }

    /**
     * Export CSV al datelor filtrate.
     */
    public function exportCsv()
    {
        $rows = $this->buildBaseQuery()
            ->orderByRaw('an DESC, luna DESC, MIN(zi) DESC, nr_factura DESC')
            ->limit(10000)
            ->get();

        $csv = "Nr Document;Data;Tip;Partener;Linii;Total RON;Serie;Observatii;Localitate\n";

        // Lookup parteneri
        $cuis    = $rows->pluck('cod_fiscal_client')->filter()->unique()->values()->all();
        $partIds = $rows->pluck('part_id')->filter()->unique()->values()->all();
        $byWmId  = \App\Models\Customer::whereIn('winmentor_id', $cuis)->pluck('name', 'winmentor_id');
        $suppNames = \App\Models\Supplier::whereIn('winmentor_id', $partIds)->pluck('name', 'winmentor_id');
        $wmNames = DB::table('winmentor_parteneri')->whereIn('wm_id', array_merge($cuis, $partIds))->pluck('denumire', 'wm_id');

        foreach ($rows as $row) {
            $name = $byWmId->get($row->cod_fiscal_client) ?? $suppNames->get($row->part_id) ?? $wmNames->get($row->part_id) ?? $wmNames->get($row->cod_fiscal_client) ?? 'Client retail';
            $date = $row->zi ? sprintf('%02d.%02d.%d', $row->zi, $row->luna, $row->an) : sprintf('%02d.%d', $row->luna, $row->an);
            $csv .= implode(';', [
                $row->nr_factura,
                $date,
                $row->tip_doc,
                str_replace(';', ',', $name),
                $row->nr_linii,
                number_format($row->total, 2, ',', ''),
                $row->serie_document ?? '',
                str_replace([';', "\n"], [',', ' '], $row->observatii ?? ''),
                $row->localitate_client ?? '',
            ]) . "\n";
        }

        return response()->streamDownload(function () use ($csv) {
            echo "\xEF\xBB\xBF" . $csv; // BOM for Excel
        }, 'vanzari-' . now()->format('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    public function getTotalCount(): int
    {
        $base = $this->buildBaseQuery();
        return DB::table(DB::raw('(' . $base->toSql() . ') as sub'))
            ->mergeBindings($base)
            ->count();
    }

    public function getRows(): array
    {
        $orderClause = match ($this->sortBy) {
            'total_desc' => 'ROUND(SUM(cantitate * pret), 2) DESC',
            'total_asc'  => 'ROUND(SUM(cantitate * pret), 2) ASC',
            'scadenta'   => 'MIN(data_scadenta) ASC, an DESC, luna DESC',
            default      => 'an DESC, luna DESC, MIN(zi) DESC, MIN(created_at) DESC, nr_factura DESC',
        };

        $rows = $this->buildBaseQuery()
            ->orderByRaw($orderClause)
            ->limit($this->perPage)
            ->offset(($this->page - 1) * $this->perPage)
            ->get();

        // Detectare facturi/avize plătite cash: din încasări (BF/CH = cash)
        $nrFacturi = $rows->pluck('nr_factura')->filter()->unique()->values()->all();
        $cashPaid = [];
        if (! empty($nrFacturi)) {
            try {
                $conn = \App\Models\IntegrationConnection::find(5);
                $incasari = \Illuminate\Support\Facades\Cache::remember(
                    'wm_incasari_ext_' . now()->format('Y-m-d-H'),
                    3600,
                    function () use ($conn) {
                        $r = \Illuminate\Support\Facades\Http::timeout(30)
                            ->withHeaders(['X-API-Key' => $conn?->bridgeApiKey()])
                            ->get(rtrim($conn?->bridgeUrl(), '/') . '/api/incasari/ext');
                        return $r->json()['data'] ?? [];
                    }
                );
                foreach ($incasari as $inc) {
                    $ref = strtoupper($inc['documentRef'] ?? '');
                    $details = $inc['detaliiFacturi'] ?? '';
                    $tipPlata = match (true) {
                        str_starts_with($ref, 'BF')  => 'BF',  // Bon fiscal
                        str_starts_with($ref, 'CH')  => 'CH',  // Chitanță
                        str_starts_with($ref, 'EX')  => 'EX',  // Extras cont (bancă)
                        str_starts_with($ref, 'DI')  => 'DI',  // Dispoziție încasare
                        default => null,
                    };
                    if (! $tipPlata) continue;

                    foreach ($nrFacturi as $nr) {
                        if (str_contains($details, (string) $nr)) {
                            // Prioritate: BF > CH > EX (păstrăm cel mai specific)
                            if (! isset($cashPaid[$nr]) || in_array($tipPlata, ['BF', 'CH'])) {
                                $cashPaid[$nr] = $tipPlata;
                            }
                        }
                    }
                }
            } catch (\Throwable) {}
        }

        // Lookup parteneri: cod_fiscal_client → customers.winmentor_id
        $cuis      = $rows->pluck('cod_fiscal_client')->filter()->unique()->values()->all();
        $partIds   = $rows->pluck('part_id')->filter()->unique()->values()->all();

        $byWmId    = \App\Models\Customer::whereIn('winmentor_id', $cuis)->pluck('name', 'winmentor_id');
        $suppNames = \App\Models\Supplier::whereIn('winmentor_id', $partIds)->pluck('name', 'winmentor_id');
        $wmNames   = DB::table('winmentor_parteneri')
            ->whereIn('wm_id', array_merge($cuis, $partIds))
            ->pluck('denumire', 'wm_id');

        // Agent names (cached)
        $personalMap = \Illuminate\Support\Facades\Cache::remember('wm_personal_map', 3600, function () {
            try {
                $conn = \App\Models\IntegrationConnection::find(5);
                $r = \Illuminate\Support\Facades\Http::timeout(15)
                    ->withHeaders(['X-API-Key' => $conn?->bridgeApiKey()])
                    ->get(rtrim($conn?->bridgeUrl(), '/') . '/api/personal');
                $map = [];
                foreach ($r->json()['data'] ?? [] as $p) {
                    if (($p['marca'] ?? '') && ($p['nume'] ?? '') && ! isset($map[$p['marca']])) {
                        $map[$p['marca']] = trim($p['nume']);
                    }
                }
                return $map;
            } catch (\Throwable) { return []; }
        });

        return $rows->map(function ($row) use ($byWmId, $suppNames, $wmNames, $cashPaid, $personalMap) {
            $name = $byWmId->get($row->cod_fiscal_client)
                ?? $suppNames->get($row->part_id)
                ?? $wmNames->get($row->part_id)
                ?? $wmNames->get($row->cod_fiscal_client)
                ?? null;

            if (! $name) {
                $cui = $row->cod_fiscal_client;
                $name = ($cui && strlen($cui) > 4) ? $cui : 'Client retail';
            }

            $row->partner_name = $name;
            $row->date_str = $row->zi
                ? sprintf('%02d.%02d.%d', $row->zi, $row->luna, $row->an)
                : sprintf('%02d.%d', $row->luna, $row->an);
            $row->tip_color = match ($row->tip_doc) {
                'Factură'  => 'blue',
                'Bon casă' => 'green',
                'Aviz'     => 'amber',
                default    => 'gray',
            };
            $plata = $cashPaid[$row->nr_factura] ?? null;
            $row->is_cash = $plata && $row->tip_doc !== 'Bon casă';
            $row->plata_tip = $plata; // BF, CH, EX, DI

            // Total cu TVA (real din WM sau calculat)
            $cota = (int) ($row->cota_tva ?? 21) ?: 21;
            $row->total_cu_tva = $row->valoare_factura
                ? (float) $row->valoare_factura
                : round($row->total * (1 + $cota / 100), 2);

            // Scadență
            $row->scadenta_str = $row->data_scadenta ?? null;
            $row->is_restant = false;
            if ($row->data_scadenta && $row->tip_doc !== 'Bon casă') {
                try {
                    $scad = \Carbon\Carbon::createFromFormat('d.m.Y', $row->data_scadenta);
                    $row->is_restant = ! $plata && $scad->isBefore(today());
                    $row->zile_restante = $row->is_restant ? max(1, (int) today()->diffInDays($scad)) : 0;
                } catch (\Throwable) {}
            }
            $row->agent_name = $personalMap[$row->marca_agent] ?? null;
            return $row;
        })->all();
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function prevPage(): void
    {
        if ($this->page > 1) $this->page--;
    }

    /**
     * Rezumat perioadă selectată (toate tipurile, nu doar luna curentă).
     */
    public function getPeriodSummary(): array
    {
        $base = $this->buildBaseQuery();
        $sub  = DB::table(DB::raw('(' . $base->toSql() . ') as sub'))
            ->mergeBindings($base);

        $summary = (clone $sub)->selectRaw("
            COUNT(*) as nr_docs,
            ROUND(SUM(total), 2) as val_total,
            COUNT(DISTINCT CASE WHEN tip_doc = 'Factură' THEN nr_factura END) as nr_facturi,
            COUNT(DISTINCT CASE WHEN tip_doc = 'Aviz' THEN nr_factura END) as nr_avize,
            COUNT(DISTINCT CASE WHEN tip_doc = 'Bon casă' THEN nr_factura END) as nr_bonuri
        ")->first();

        return (array) $summary;
    }

    public function openDocument(string $nr, int $an, int $luna): void
    {
        $this->redirect(WinmentorVanzariDetailPage::getUrl() . '?' . http_build_query([
            'nr' => $nr, 'an' => $an, 'luna' => $luna,
        ]));
    }
}
