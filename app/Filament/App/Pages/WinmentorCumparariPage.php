<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class WinmentorCumparariPage extends Page
{
    protected string $view = 'filament.app.pages.winmentor-cumparari';

    protected static ?string $navigationLabel = 'Cumpărări';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';
    protected static ?int    $navigationSort  = 92;
    protected static ?string $title = 'Cumpărări WinMentor';

    public string $dateFrom = '';
    public string $dateTo   = '';
    public int    $page     = 1;
    public int    $perPage  = 50;
    public bool   $hasMore  = false;

    protected $queryString = ['dateFrom', 'dateTo', 'page'];

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
        if ($this->dateFrom === '' && $this->dateTo === '') {
            $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
            $this->dateTo   = now()->format('Y-m-d');
        }
    }

    public function updatedDateFrom(): void { $this->page = 1; }
    public function updatedDateTo(): void   { $this->page = 1; }

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
        $this->dateFrom = now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
        $this->dateTo   = now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d');
        $this->page = 1;
    }

    public function goPrevMonth(): void
    {
        $ref = Carbon::parse($this->dateFrom ?: now())->subMonthNoOverflow();
        $this->dateFrom = $ref->startOfMonth()->format('Y-m-d');
        $this->dateTo   = $ref->endOfMonth()->format('Y-m-d');
        $this->page = 1;
    }

    public function goNextMonth(): void
    {
        $ref = Carbon::parse($this->dateFrom ?: now())->addMonthNoOverflow();
        $this->dateFrom = $ref->startOfMonth()->format('Y-m-d');
        $this->dateTo   = min($ref->endOfMonth(), now())->format('Y-m-d');
        $this->page = 1;
    }

    public function prevPage(): void { $this->page = max(1, $this->page - 1); }

    public function nextPage(): void { if ($this->hasMore) $this->page++; }

    public function getRows(): array
    {
        // Grupare pe nr_doc + NIR: numerele de factură sunt per-furnizor (două facturi
        // cu același număr de la furnizori diferiți au NIR-uri diferite → rânduri separate),
        // iar același furnizor poate apărea cu mai multe formate de part_id pe liniile
        // aceluiași document (NU grupa pe part_id — dublează recepțiile).
        // Total RON: prețurile EUR se convertesc cu cursul BNR salvat pe rând.
        $query = DB::table('winmentor_intrari_raw')
            ->select([
                'nr_doc', 'an', 'luna', 'nr_receptie',
                DB::raw('GROUP_CONCAT(DISTINCT part_id) as all_part_ids'),
                DB::raw('MIN(den_furnizor) as den_furnizor'),
                DB::raw('MIN(data_intrare) as data_intrare'),
                DB::raw('COUNT(*) as nr_linii'),
                DB::raw('ROUND(SUM(cantitate * pret * COALESCE(curs_bnr, 1)), 2) as total'),
                DB::raw("MAX(CASE WHEN moneda = 'EUR' THEN 1 ELSE 0 END) as are_eur"),
            ])
            ->where('firma', 'MAL2019')
            ->groupBy('nr_doc', 'an', 'luna', 'nr_receptie');

        if ($this->dateFrom) {
            $query->where('data_intrare', '>=', Carbon::parse($this->dateFrom)->toDateString());
        }
        if ($this->dateTo) {
            $query->where('data_intrare', '<=', Carbon::parse($this->dateTo)->toDateString());
        }

        $rows = $query
            ->orderByRaw('MIN(data_intrare) DESC, nr_doc DESC')
            ->offset(($this->page - 1) * $this->perPage)
            ->limit($this->perPage + 1)
            ->get();

        $this->hasMore = $rows->count() > $this->perPage;
        $rows = $rows->take($this->perPage);

        // Un furnizor poate apărea cu mai multe formate de part_id — le colectăm pe toate
        $allPartIds = $rows->flatMap(fn ($r) => explode(',', $r->all_part_ids ?? ''))->filter()->unique()->values()->all();
        $supplierNames = \App\Models\Supplier::whereIn('winmentor_id', $allPartIds)->pluck('name', 'winmentor_id');

        $nrDocs = $rows->pluck('nr_doc')->filter()->unique()->values()->all();

        // Construim map nr_doc → PO, inclusiv din winmentor_receptie_nrs (PO cu mai multe facturi)
        $poByNrDoc = [];
        PurchaseOrder::whereIn('invoice_number', $nrDocs)
            ->get(['id', 'number', 'invoice_number', 'winmentor_receptie_nrs'])
            ->each(function ($po) use (&$poByNrDoc) {
                if ($po->invoice_number) $poByNrDoc[$po->invoice_number] = $po;
                foreach ($po->winmentor_receptie_nrs ?? [] as $nir) {
                    if ($nr = $nir['nr_doc'] ?? null) $poByNrDoc[$nr] = $po;
                }
            });

        $supplierIds = \App\Models\Supplier::whereIn('winmentor_id', $allPartIds)->pluck('id', 'winmentor_id');

        return $rows->map(function ($row) use ($supplierNames, $supplierIds, $poByNrDoc) {
            $partIds = array_filter(explode(',', $row->all_part_ids ?? ''));
            $row->partner_name = collect($partIds)->map(fn ($id) => $supplierNames->get($id))->filter()->first()
                ?? ($row->den_furnizor ?: (implode(', ', $partIds) ?: '—'));
            $row->supplier_id = collect($partIds)->map(fn ($id) => $supplierIds->get($id))->filter()->first();
            $row->date_str = $row->data_intrare
                ? Carbon::parse($row->data_intrare)->format('d.m.Y')
                : sprintf('%02d.%d', $row->luna, $row->an);

            $po = $poByNrDoc[$row->nr_doc] ?? null;
            $row->po_number = $po?->number;
            $row->po_url    = $po ? PurchaseOrderResource::getUrl('view', ['record' => $po->id]) : null;

            return $row;
        })->all();
    }

    public function openDocument(string $nr, string $nir, int $an, int $luna): void
    {
        $this->redirect(WinmentorCumparariDetailPage::getUrl() . '?' . http_build_query([
            'nr' => $nr, 'nir' => $nir, 'an' => $an, 'luna' => $luna,
        ]));
    }
}
