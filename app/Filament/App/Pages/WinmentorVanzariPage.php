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

    public string $dateFrom = '';
    public string $dateTo   = '';
    public string $tipDoc   = '';
    public string $search   = '';
    public int    $page     = 1;
    public int    $perPage  = 50;

    protected $queryString = ['dateFrom', 'dateTo', 'tipDoc', 'search', 'page'];

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

    public function updatedSearch(): void  { $this->page = 1; }
    public function updatedDateFrom(): void { $this->page = 1; }
    public function updatedDateTo(): void   { $this->page = 1; }
    public function updatedTipDoc(): void   { $this->page = 1; }

    private function buildBaseQuery()
    {
        $q = DB::table('winmentor_vanzari_raw')
            ->select([
                'nr_factura', 'an', 'luna',
                DB::raw('MIN(zi) as zi'),
                DB::raw('MIN(part_id) as part_id'),
                DB::raw('MIN(cod_fiscal_client) as cod_fiscal_client'),
                DB::raw('MIN(adresa_client) as adresa_client'),
                DB::raw('COUNT(*) as nr_linii'),
                DB::raw('ROUND(SUM(cantitate * pret), 2) as total'),
                DB::raw("CASE
                    WHEN nr_factura REGEXP '^1[0-9]{5}$' THEN 'Aviz'
                    WHEN nr_factura REGEXP '^2[0-9]{5}$' THEN 'Bon casă'
                    WHEN nr_factura REGEXP '^[0-9]{5}$' THEN 'Factură'
                    ELSE 'Alt document'
                END as tip_doc"),
            ])
            ->where('firma', 'MAL2019')
            ->groupBy('nr_factura', 'an', 'luna');

        // Filtrele de dată se ignoră când e activ search-ul
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
            'aviz'     => $q->whereRaw("nr_factura REGEXP '^1[0-9]{5}$'"),
            'factura'  => $q->whereRaw("nr_factura REGEXP '^[0-9]{5}$'"),
            'bon_casa' => $q->whereRaw("nr_factura REGEXP '^2[0-9]{5}$'"),
            default    => null,
        };

        if ($this->search) {
            $s = '%' . $this->search . '%';

            // Găsim CUI-urile clienților care se potrivesc după nume
            $matchedCuis = \App\Models\Customer::where('name', 'like', $s)
                ->whereNotNull('winmentor_id')
                ->pluck('winmentor_id')
                ->all();

            $q->where(function ($q2) use ($s, $matchedCuis) {
                $q2->where('nr_factura', 'like', $s)
                   ->orWhere('cod_fiscal_client', 'like', $s);
                if (! empty($matchedCuis)) {
                    $q2->orWhereIn('cod_fiscal_client', $matchedCuis);
                }
            });
        }

        return $q;
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
        $rows = $this->buildBaseQuery()
            ->orderByRaw('an DESC, luna DESC, MIN(zi) DESC, nr_factura DESC')
            ->limit($this->perPage)
            ->offset(($this->page - 1) * $this->perPage)
            ->get();

        // Lookup parteneri: cod_fiscal_client → customers.winmentor_id
        $cuis      = $rows->pluck('cod_fiscal_client')->filter()->unique()->values()->all();
        $partIds   = $rows->pluck('part_id')->filter()->unique()->values()->all();

        $byWmId    = \App\Models\Customer::whereIn('winmentor_id', $cuis)->pluck('name', 'winmentor_id');
        $suppNames = \App\Models\Supplier::whereIn('winmentor_id', $partIds)->pluck('name', 'winmentor_id');

        return $rows->map(function ($row) use ($byWmId, $suppNames) {
            $name = $byWmId->get($row->cod_fiscal_client)
                ?? $suppNames->get($row->part_id)
                ?? null;

            if (! $name) {
                // Retail sau necunoscut
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

    public function openDocument(string $nr, int $an, int $luna): void
    {
        $this->redirect(WinmentorVanzariDetailPage::getUrl() . '?' . http_build_query([
            'nr' => $nr, 'an' => $an, 'luna' => $luna,
        ]));
    }
}
