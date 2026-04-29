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

    protected $queryString = ['dateFrom', 'dateTo'];

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

    public function getRows(): array
    {
        $query = DB::table('winmentor_intrari_raw')
            ->select([
                'nr_doc', 'an', 'luna',
                DB::raw('MIN(part_id) as part_id'),
                DB::raw('GROUP_CONCAT(DISTINCT part_id) as all_part_ids'),
                DB::raw('MIN(den_furnizor) as den_furnizor'),
                DB::raw('MIN(nr_receptie) as nr_receptie'),
                DB::raw('MIN(data_intrare) as data_intrare'),
                DB::raw('COUNT(*) as nr_linii'),
                DB::raw('ROUND(SUM(cantitate * pret), 2) as total'),
            ])
            ->where('firma', 'MAL2019')
            ->groupBy('nr_doc', 'an', 'luna');

        if ($this->dateFrom) {
            $query->where('data_intrare', '>=', Carbon::parse($this->dateFrom)->toDateString());
        }
        if ($this->dateTo) {
            $query->where('data_intrare', '<=', Carbon::parse($this->dateTo)->toDateString());
        }

        $rows = $query
            ->orderByRaw('MIN(data_intrare) DESC, nr_doc DESC')
            ->limit(300)
            ->get();

        // Colectăm TOȚI part_id-ii (un furnizor poate apărea cu mai multe formate în WM)
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

        return $rows->map(function ($row) use ($supplierNames, $poByNrDoc) {
            $partIds = array_filter(explode(',', $row->all_part_ids ?? ''));
            $row->partner_name = collect($partIds)->map(fn ($id) => $supplierNames->get($id))->filter()->first()
                ?? ($row->den_furnizor ?: ($row->part_id ?: '—'));
            $row->date_str = $row->data_intrare
                ? Carbon::parse($row->data_intrare)->format('d.m.Y')
                : sprintf('%02d.%d', $row->luna, $row->an);

            $po = $poByNrDoc[$row->nr_doc] ?? null;
            $row->po_number = $po?->number;
            $row->po_url    = $po ? PurchaseOrderResource::getUrl('view', ['record' => $po->id]) : null;

            return $row;
        })->all();
    }

    public function openDocument(string $nr, string $partId, int $an, int $luna): void
    {
        $this->redirect(WinmentorCumparariDetailPage::getUrl() . '?' . http_build_query([
            'nr' => $nr, 'part_id' => $partId, 'an' => $an, 'luna' => $luna,
        ]));
    }
}
