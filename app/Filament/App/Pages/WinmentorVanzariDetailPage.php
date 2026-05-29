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

        $tipDocExpr = "CASE
            WHEN nr_factura REGEXP '^2[0-9]{5}$' THEN 'Bon casă'
            WHEN tip_document = 'S' THEN 'Bon casă'
            WHEN tip_document = 'AE' THEN 'Aviz'
            WHEN tip_document = 'F' THEN 'Factură'
            WHEN nr_factura REGEXP '^1[0-9]{5}$' THEN 'Aviz'
            WHEN nr_factura REGEXP '^[0-9]{5}$' THEN 'Factură'
            ELSE 'Alt document'
        END";

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
                MIN(marca_agent) as marca_agent,
                COUNT(*) as nr_linii,
                ROUND(SUM(cantitate * pret), 2) as total,
                ROUND(SUM(cantitate * pret * 0.21), 2) as tva_estimat,
                ROUND(SUM(cantitate * pret * 1.21), 2) as total_cu_tva,
                ({$tipDocExpr}) as tip_doc,
                MIN(serie_document) as serie_document,
                MIN(observatii_factura) as observatii,
                MIN(localitate_client) as localitate,
                MIN(discount) as discount
            ")
            ->groupBy('nr_factura', 'an', 'luna')
            ->first();

        if (! $row) return null;

        // Lookup partener
        $partnerName = null;
        $partnerData = null;

        if ($row->cod_fiscal_client) {
            $partnerData = \App\Models\Customer::where('winmentor_id', $row->cod_fiscal_client)->first();
            $partnerName = $partnerData?->name;
        }
        if (! $partnerName && $row->part_id) {
            $supplier = \App\Models\Supplier::where('winmentor_id', $row->part_id)->first();
            $partnerName = $supplier?->name;
            if (! $partnerData) $partnerData = $supplier;
        }

        // Lookup partener din WinMentor dacă nu e în ERP
        $wmPartener = null;
        if ($row->part_id) {
            $wmPartener = DB::table('winmentor_parteneri')->where('wm_id', $row->part_id)->first();
        }
        if (! $wmPartener && $row->cod_fiscal_client) {
            $wmPartener = DB::table('winmentor_parteneri')->where('cod_fiscal', 'like', '%' . preg_replace('/[^0-9]/', '', $row->cod_fiscal_client) . '%')->first();
        }

        if (! $partnerName) {
            $partnerName = $wmPartener?->denumire ?? (($row->cod_fiscal_client && strlen($row->cod_fiscal_client) > 4) ? $row->cod_fiscal_client : 'Client retail');
        }

        $row->partner_name  = $partnerName;
        $row->partner_cui   = $wmPartener?->cod_fiscal ?? $row->cod_fiscal_client ?? null;
        $row->partner_addr  = $wmPartener?->adresa ?? $row->adresa_client ?? null;
        $row->partner_loc   = $wmPartener?->localitate ?? $row->localitate ?? null;
        $row->partner_phone = $wmPartener?->telefon ?? null;
        $row->partner_email = $partnerData?->email ?? null;
        $row->partner_judet = $wmPartener?->judet ?? null;

        // Agent
        $personalMap = \Illuminate\Support\Facades\Cache::get('wm_personal_map', []);
        $row->agent_name = $personalMap[$row->marca_agent] ?? null;

        // Cash paid se determină din încasări (BF/CH = cash)
        $row->is_cash = false; // se setează după getIncasari()

        $row->date_str = $row->zi
            ? sprintf('%02d.%02d.%d', $row->zi, $row->luna, $row->an)
            : sprintf('%02d.%d', $row->luna, $row->an);

        // Încasări legate de acest document
        $row->incasari = $this->getIncasari($row->nr_factura, $row->serie_document);

        // is_cash din încasări (BF/CH = cash)
        if ($row->tip_doc !== 'Bon casă') {
            $row->is_cash = collect($row->incasari)->contains(fn ($i) =>
                str_contains($i->tip_plata, 'cash') || str_contains($i->tip_plata, 'Chitanta')
            );
        }

        return $row;
    }

    private function getIncasari(?string $nrFactura, ?string $serieDocument): array
    {
        if (! $nrFactura) return [];

        try {
            $conn = \App\Models\IntegrationConnection::find(5);
            if (! $conn) return [];

            $health = (new WinmentorBridgeClient())->health();
            if (! ($health['data']['comConnected'] ?? false)) return [];

            $r = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders(['X-API-Key' => $conn->bridgeApiKey()])
                ->get(rtrim($conn->bridgeUrl(), '/') . '/api/incasari/ext');

            $items = $r->json()['data'] ?? [];

            // Căutăm încasări care referă acest document
            // detaliiFacturi conține: ~F.MAL 76173/28.05.2026=1752,8
            $searchPatterns = array_filter([
                $nrFactura,
                $serieDocument ? str_replace('.', ' ', $serieDocument) : null, // F.MAL76173 → F MAL76173
            ]);

            $matched = [];
            foreach ($items as $item) {
                $details = $item['detaliiFacturi'] ?? '';
                foreach ($searchPatterns as $pattern) {
                    if (str_contains($details, $pattern)) {
                        $ref = $item['documentRef'] ?? '';
                        $tipPlata = match (true) {
                            str_starts_with(strtoupper($ref), 'BF')  => 'Bon fiscal (cash)',
                            str_starts_with(strtoupper($ref), 'EX')  => 'Extras cont (banca/card)',
                            str_starts_with(strtoupper($ref), 'CH')  => 'Chitanta (cash)',
                            str_starts_with(strtoupper($ref), 'DI')  => 'Dispozitie incasare',
                            str_starts_with(strtoupper($ref), 'REG') => 'Regularizare',
                            default => $ref,
                        };
                        $matched[] = (object) [
                            'document_ref' => $ref,
                            'tip_plata'    => $tipPlata,
                            'data'         => $item['data'] ?? '',
                            'suma'         => $item['suma'] ?? '',
                            'detalii'      => $details,
                        ];
                        break;
                    }
                }
            }

            return $matched;
        } catch (\Throwable) {
            return [];
        }
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
                v.den_articol,
                v.discount,
                COALESCE(v.den_articol, wp.winmentor_name, wp.name) as product_name
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

        // Lookup woo_product IDs for linking
        $skus = $lines->pluck('sku')->filter()->unique()->values()->all();
        $wooProducts = ! empty($skus)
            ? \App\Models\WooProduct::whereIn('sku', $skus)->pluck('id', 'sku')->all()
            : [];

        return $lines->map(function ($line) use ($bridgeNames, $wooProducts) {
            $line->display_name = $line->product_name
                ?? $bridgeNames[$line->sku]
                ?? $line->sku
                ?? '—';
            $line->woo_product_id = $wooProducts[$line->sku] ?? null;
            return $line;
        })->all();
    }
}
