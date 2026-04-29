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
    public string $part_id = '';
    public int    $an      = 0;
    public int    $luna    = 0;

    protected $queryString = ['nr', 'part_id', 'an', 'luna'];

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

        $row = DB::table('winmentor_intrari_raw')
            ->where('firma', 'MAL2019')
            ->where('nr_doc', $this->nr)
            ->where('an', $this->an)
            ->where('luna', $this->luna)
            ->where('part_id', $this->part_id)
            ->selectRaw("
                nr_doc, an, luna,
                MIN(part_id) as part_id,
                MIN(den_furnizor) as den_furnizor,
                MIN(nr_receptie) as nr_receptie,
                MIN(data_intrare) as data_intrare,
                COUNT(*) as nr_linii,
                ROUND(SUM(cantitate * pret), 2) as total
            ")
            ->groupBy('nr_doc', 'an', 'luna')
            ->first();

        if (! $row) return null;

        $partnerName = \App\Models\Supplier::where('winmentor_id', $row->part_id)->value('name')
            ?? ($row->den_furnizor ?: ($row->part_id ?: '—'));

        $row->partner_name = $partnerName;
        $row->date_str = $row->data_intrare
            ? Carbon::parse($row->data_intrare)->format('d.m.Y')
            : sprintf('%02d.%d', $row->luna, $row->an);

        return $row;
    }

    public function getLines(): array
    {
        if (! $this->nr || ! $this->an || ! $this->luna) return [];

        $lines = DB::table('winmentor_intrari_raw as i')
            ->leftJoin('woo_products as wp', 'wp.sku', '=', 'i.sku')
            ->where('i.firma', 'MAL2019')
            ->where('i.nr_doc', $this->nr)
            ->where('i.an', $this->an)
            ->where('i.luna', $this->luna)
            ->where('i.part_id', $this->part_id)
            ->selectRaw("
                i.sku,
                i.den_articol,
                i.cantitate,
                i.uom,
                i.pret,
                i.pret_vanzare,
                i.moneda,
                i.curs_bnr,
                ROUND(i.cantitate * i.pret, 2) as total,
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
