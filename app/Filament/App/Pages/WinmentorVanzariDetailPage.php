<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use App\Services\Winmentor\WinmentorBridgeClient;

class WinmentorVanzariDetailPage extends Page
{
    protected string $view = 'filament.app.pages.winmentor-vanzari-detail';
    protected static bool    $shouldRegisterNavigation = false;
    protected static ?string $title = 'Document Vânzare';

    public string $nr   = '';
    public int    $an   = 0;
    public int    $luna = 0;

    protected $queryString = ['nr', 'an', 'luna'];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function getTitle(): string
    {
        return $this->nr ? "Document #{$this->nr}" : 'Document Vânzare';
    }

    public function getDocument(): ?object
    {
        if (! $this->nr || ! $this->an || ! $this->luna) return null;

        $row = DB::table('winmentor_vanzari_raw')
            ->where('firma', 'MAL2019')
            ->where('nr_factura', $this->nr)
            ->where('an', $this->an)
            ->where('luna', $this->luna)
            ->selectRaw("
                nr_factura, an, luna,
                MIN(zi) as zi,
                MIN(part_id) as part_id,
                MIN(adresa_client) as adresa_client,
                MIN(cod_fiscal_client) as cod_fiscal_client,
                COUNT(*) as nr_linii,
                ROUND(SUM(cantitate * pret), 2) as total,
                CASE
                    WHEN nr_factura REGEXP '^1[0-9]{5}$' THEN 'Aviz'
                    WHEN nr_factura REGEXP '^2[0-9]{5}$' THEN 'Bon casă'
                    WHEN nr_factura REGEXP '^[0-9]{5}$' THEN 'Factură'
                    ELSE 'Alt document'
                END as tip_doc
            ")
            ->groupBy('nr_factura', 'an', 'luna')
            ->first();

        if (! $row) return null;

        // Lookup partener: cod_fiscal_client (CUI) → customers.winmentor_id
        $partnerName = null;
        if ($row->cod_fiscal_client) {
            $partnerName = \App\Models\Customer::where('winmentor_id', $row->cod_fiscal_client)->value('name');
        }
        if (! $partnerName && $row->part_id) {
            $partnerName = \App\Models\Supplier::where('winmentor_id', $row->part_id)->value('name');
        }
        if (! $partnerName) {
            $cui = $row->cod_fiscal_client;
            $partnerName = ($cui && strlen($cui) > 4) ? $cui : 'Client retail';
        }

        $row->partner_name = $partnerName;
        $row->date_str = $row->zi
            ? sprintf('%02d.%02d.%d', $row->zi, $row->luna, $row->an)
            : sprintf('%02d.%d', $row->luna, $row->an);

        return $row;
    }

    public function getLines(): array
    {
        if (! $this->nr || ! $this->an || ! $this->luna) return [];

        $lines = DB::table('winmentor_vanzari_raw as v')
            ->leftJoin('woo_products as wp', 'wp.sku', '=', 'v.sku')
            ->where('v.firma', 'MAL2019')
            ->where('v.nr_factura', $this->nr)
            ->where('v.an', $this->an)
            ->where('v.luna', $this->luna)
            ->selectRaw("
                v.sku,
                v.cantitate,
                v.uom,
                v.pret,
                ROUND(v.cantitate * v.pret, 2) as total,
                v.den_gestiune,
                COALESCE(wp.winmentor_name, wp.name) as product_name
            ")
            ->orderBy('v.id')
            ->get();

        // Pentru SKU-uri negăsite în woo_products, încearcă Bridge (max 10 SKU-uri)
        $unknownSkus = $lines
            ->filter(fn ($l) => ! $l->product_name && $l->sku)
            ->pluck('sku')
            ->unique()
            ->values();

        $bridgeNames = [];
        if ($unknownSkus->isNotEmpty() && $unknownSkus->count() <= 10) {
            try {
                $bridge = app(WinmentorBridgeClient::class);
                foreach ($unknownSkus as $sku) {
                    $art = $bridge->searchArticolBySku($sku);
                    if ($art) {
                        $bridgeNames[$sku] = $art['denumire'] ?? $art['denUM'] ?? null;
                    }
                }
            } catch (\Throwable) {}
        }

        return $lines->map(function ($line) use ($bridgeNames) {
            $line->display_name = $line->product_name
                ?? $bridgeNames[$line->sku]
                ?? $line->sku
                ?? '—';
            return $line;
        })->all();
    }
}
