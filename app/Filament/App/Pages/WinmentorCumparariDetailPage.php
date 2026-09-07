<?php

namespace App\Filament\App\Pages;

use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class WinmentorCumparariDetailPage extends Page
{
    protected string $view = 'filament.app.pages.winmentor-cumparari-detail';
    protected static bool    $shouldRegisterNavigation = false;
    protected static ?string $title = 'Document Cumpărare';

    public string $nr      = '';
    public string $nir     = '';
    public string $part_id = ''; // compatibilitate link-uri vechi
    public int    $an      = 0;
    public int    $luna    = 0;

    protected $queryString = ['nr', 'nir', 'part_id', 'an', 'luna'];

    /**
     * Filtrele comune document: nr + an + luna + NIR (dacă e cunoscut).
     * part_id doar dacă e nenul (link-uri vechi) — un document poate avea
     * linii cu part_id NULL sau cu mai multe formate ale aceluiași furnizor.
     */
    private function applyDocFilters($query, string $prefix = ''): void
    {
        $query->where("{$prefix}nr_doc", $this->nr)
            ->where("{$prefix}an", $this->an)
            ->where("{$prefix}luna", $this->luna);

        if ($this->nir !== '') {
            $query->where("{$prefix}nr_receptie", $this->nir);
        } elseif ($this->part_id !== '') {
            $query->where("{$prefix}part_id", $this->part_id);
        }
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function getTitle(): string
    {
        return $this->nr ? "Document #{$this->nr}" : 'Document Cumpărare';
    }

    public function getDocument(): ?object
    {
        if (! $this->nr || ! $this->an || ! $this->luna) return null;

        $query = DB::table('winmentor_intrari_raw')->where('firma', 'MAL2019');
        $this->applyDocFilters($query);

        $row = $query
            ->selectRaw("
                nr_doc, an, luna,
                GROUP_CONCAT(DISTINCT part_id) as all_part_ids,
                MIN(den_furnizor) as den_furnizor,
                MIN(nr_receptie) as nr_receptie,
                MIN(data_intrare) as data_intrare,
                COUNT(*) as nr_linii,
                ROUND(SUM(cantitate * pret * COALESCE(curs_bnr, 1)), 2) as total,
                MAX(CASE WHEN moneda = 'EUR' THEN 1 ELSE 0 END) as are_eur
            ")
            ->groupBy('nr_doc', 'an', 'luna')
            ->first();

        if (! $row) return null;

        // Furnizorul poate apărea cu mai multe formate de part_id — încercăm toate
        $partIds  = array_filter(explode(',', $row->all_part_ids ?? ''));
        $supplier = empty($partIds) ? null
            : \App\Models\Supplier::whereIn('winmentor_id', $partIds)->first(['id', 'name']);

        $row->partner_name = $supplier?->name
            ?? ($row->den_furnizor ?: (implode(', ', $partIds) ?: '—'));
        $row->supplier_id  = $supplier?->id;
        $row->supplier_url = $supplier
            ? \App\Filament\App\Resources\SupplierResource::getUrl('view', ['record' => $supplier->id])
            : null;
        $row->part_id = $partIds[0] ?? null;
        $row->date_str = $row->data_intrare
            ? Carbon::parse($row->data_intrare)->format('d.m.Y')
            : sprintf('%02d.%d', $row->luna, $row->an);

        return $row;
    }

    public function getLines(): array
    {
        if (! $this->nr || ! $this->an || ! $this->luna) return [];

        $query = DB::table('winmentor_intrari_raw as i')
            ->leftJoin('woo_products as wp', 'wp.sku', '=', 'i.sku')
            ->where('i.firma', 'MAL2019');
        $this->applyDocFilters($query, 'i.');

        $lines = $query
            ->selectRaw("
                i.sku,
                i.den_articol,
                i.cantitate,
                i.uom,
                i.pret,
                i.pret_vanzare,
                i.moneda,
                i.curs_bnr,
                ROUND(i.cantitate * i.pret * COALESCE(i.curs_bnr, 1), 2) as total,
                i.den_gestiune,
                COALESCE(wp.winmentor_name, wp.name) as product_name
            ")
            ->orderBy('i.id')
            ->get();

        return $lines->map(function ($line) {
            $line->display_name = $line->product_name
                ?? ($line->den_articol ?: ($line->sku ?: '—'));
            return $line;
        })->all();
    }
}
