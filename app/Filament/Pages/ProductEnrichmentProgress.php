<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class ProductEnrichmentProgress extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Integrări';
    protected static ?string $navigationLabel = 'Progres îmbogățire produse';
    protected static ?int    $navigationSort  = 99;

    protected static string $view = 'filament.app.pages.product-enrichment-progress';

    /** Auto-refresh every 8 seconds. */
    protected ?string $pollingInterval = '8s';

    // -------------------------------------------------------------------------
    // Data helpers called from the Blade view
    // -------------------------------------------------------------------------

    public function getImageStats(): array
    {
        $total = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->count();

        $searched = DB::table('woo_products as wp')
            ->where('wp.is_placeholder', true)
            ->where('wp.source', 'winmentor_csv')
            ->whereExists(fn ($q) => $q->from('product_image_candidates as pic')
                ->whereColumn('pic.woo_product_id', 'wp.id'))
            ->count();

        $pending  = DB::table('product_image_candidates')->where('status', 'pending')->count();
        $approved = DB::table('product_image_candidates')->where('status', 'approved')->count();
        $rejected = DB::table('product_image_candidates')->where('status', 'rejected')->count();

        $withImage = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->whereNotNull('main_image_url')
            ->where('main_image_url', '!=', '')
            ->count();

        return [
            'total'      => $total,
            'searched'   => $searched,
            'pending'    => $pending,
            'approved'   => $approved,
            'rejected'   => $rejected,
            'with_image' => $withImage,
            'search_pct' => $total > 0 ? round($searched / $total * 100) : 0,
            'image_pct'  => $total > 0 ? round($withImage / $total * 100) : 0,
        ];
    }

    public function getCategoryStats(): array
    {
        $total = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->count();

        $categorized = DB::table('woo_products as wp')
            ->where('wp.is_placeholder', true)
            ->where('wp.source', 'winmentor_csv')
            ->whereExists(fn ($q) => $q->from('woo_product_category as pc')
                ->whereColumn('pc.woo_product_id', 'wp.id'))
            ->count();

        $uncategorized = $total - $categorized;

        $breakdown = DB::table('woo_product_category as pc')
            ->join('woo_products as wp', 'wp.id', '=', 'pc.woo_product_id')
            ->join('woo_categories as wc', 'wc.id', '=', 'pc.woo_category_id')
            ->leftJoin('woo_categories as parent', 'parent.id', '=', 'wc.parent_id')
            ->leftJoin('woo_categories as gp', 'gp.id', '=', 'parent.parent_id')
            ->where('wp.is_placeholder', true)
            ->where('wp.source', 'winmentor_csv')
            ->select(DB::raw('
                COALESCE(gp.name, parent.name, wc.name) as top_name,
                COUNT(*) as cnt
            '))
            ->groupBy('top_name')
            ->orderByDesc('cnt')
            ->get();

        return [
            'total'         => $total,
            'categorized'   => $categorized,
            'uncategorized' => $uncategorized,
            'cat_pct'       => $total > 0 ? round($categorized / $total * 100) : 0,
            'breakdown'     => $breakdown,
        ];
    }

    public function getLocalImageStats(): array
    {
        $totalWithImage = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->whereNotNull('main_image_url')
            ->where('main_image_url', '!=', '')
            ->count();

        $local = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->where('main_image_url', 'LIKE', '%erp.malinco.ro%')
            ->count();

        $external = $totalWithImage - $local;

        return [
            'total_with_image' => $totalWithImage,
            'local'            => $local,
            'external'         => $external,
            'local_pct'        => $totalWithImage > 0 ? round($local / $totalWithImage * 100) : 0,
        ];
    }

    public function getRecentlyApproved(): \Illuminate\Support\Collection
    {
        return DB::table('product_image_candidates as pic')
            ->join('woo_products as wp', 'wp.id', '=', 'pic.woo_product_id')
            ->where('pic.status', 'approved')
            ->whereNotNull('wp.main_image_url')
            ->where('wp.main_image_url', '!=', '')
            ->orderByDesc('pic.updated_at')
            ->limit(6)
            ->select('wp.name', 'wp.main_image_url as image_url', 'pic.updated_at')
            ->get();
    }

    public function getDescriptionStats(): array
    {
        $total = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->count();

        $withDesc = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->count();

        $withShort = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->whereNotNull('short_description')
            ->where('short_description', '!=', '')
            ->count();

        return [
            'total'      => $total,
            'with_desc'  => $withDesc,
            'with_short' => $withShort,
            'desc_pct'   => $total > 0 ? round($withDesc / $total * 100) : 0,
        ];
    }

    public function getRecentlyDescribed(): \Illuminate\Support\Collection
    {
        return DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->select('id', 'name', 'short_description', 'updated_at')
            ->get();
    }

    public function getAttributeStats(): array
    {
        $total = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->count();

        $withAttrs = DB::table('woo_product_attributes')
            ->distinct('woo_product_id')
            ->count('woo_product_id');

        $totalAttrs = DB::table('woo_product_attributes')->count();

        $normalized = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->whereNotNull('erp_notes')
            ->where('erp_notes', '!=', '')
            ->count();

        $reformatted = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->where('erp_notes', 'like', '%[titlu-reformat]%')
            ->count();

        return [
            'total'        => $total,
            'with_attrs'   => $withAttrs,
            'total_attrs'  => $totalAttrs,
            'normalized'   => $normalized,
            'reformatted'  => $reformatted,
            'attr_pct'     => $total > 0 ? round($withAttrs / $total * 100) : 0,
            'norm_pct'     => $total > 0 ? round($normalized / $total * 100) : 0,
            'reformat_pct' => $total > 0 ? round($reformatted / $total * 100) : 0,
        ];
    }

    public function getWebEnrichStats(): array
    {
        $total = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->whereRaw("sku NOT LIKE '9%'")
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->count();

        $enriched = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->where('erp_notes', 'like', '%[web-enriched]%')
            ->count();

        return [
            'total'    => $total,
            'enriched' => $enriched,
            'pct'      => $total > 0 ? round($enriched / $total * 100) : 0,
        ];
    }

    public function getRecentlyWebEnriched(): \Illuminate\Support\Collection
    {
        return DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->where('erp_notes', 'like', '%[web-enriched]%')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->select('id', 'name', 'description', 'updated_at')
            ->get()
            ->map(function ($p) {
                $p->attr_count = DB::table('woo_product_attributes')
                    ->where('woo_product_id', $p->id)
                    ->count();
                return $p;
            });
    }

    public function getWooContentStats(): array
    {
        $total = DB::table('woo_products')
            ->where('is_placeholder', false)
            ->count();

        $withShort = DB::table('woo_products')
            ->where('is_placeholder', false)
            ->whereNotNull('short_description')
            ->where('short_description', '!=', '')
            ->count();

        $withDesc = DB::table('woo_products')
            ->where('is_placeholder', false)
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->whereRaw('LENGTH(description) >= 200')
            ->where('description', 'not like', '%data-start%')
            ->count();

        $needsAttention = DB::table('woo_products')
            ->where('is_placeholder', false)
            ->where(function ($q) {
                $q->whereNull('short_description')
                  ->orWhere('short_description', '')
                  ->orWhereNull('description')
                  ->orWhere('description', '')
                  ->orWhereRaw('LENGTH(description) < 200')
                  ->orWhere('description', 'like', '%data-start%');
            })
            ->count();

        $withAttrWoo = DB::table('woo_products as wp')
            ->where('is_placeholder', false)
            ->whereExists(fn ($q) => $q->from('woo_product_attributes')
                ->whereColumn('woo_product_id', 'wp.id')
                ->where('source', 'woocommerce'))
            ->count();

        $withAttrGen = DB::table('woo_products as wp')
            ->where('is_placeholder', false)
            ->whereExists(fn ($q) => $q->from('woo_product_attributes')
                ->whereColumn('woo_product_id', 'wp.id')
                ->where('source', 'generated'))
            ->count();

        $withAnyAttr = DB::table('woo_products as wp')
            ->where('is_placeholder', false)
            ->whereExists(fn ($q) => $q->from('woo_product_attributes')
                ->whereColumn('woo_product_id', 'wp.id'))
            ->count();

        $totalAttrWoo = DB::table('woo_product_attributes')->where('source', 'woocommerce')->count();
        $totalAttrGen = DB::table('woo_product_attributes')->where('source', 'generated')->where('name', '!=', '_evaluated')->count();

        return [
            'total'           => $total,
            'with_short'      => $withShort,
            'with_desc'       => $withDesc,
            'needs_attention' => $needsAttention,
            'done'            => $total - $needsAttention,
            'short_pct'       => $total > 0 ? round($withShort / $total * 100) : 0,
            'desc_pct'        => $total > 0 ? round($withDesc / $total * 100) : 0,
            'done_pct'        => $total > 0 ? round(($total - $needsAttention) / $total * 100) : 0,
            'with_attr_woo'   => $withAttrWoo,
            'with_attr_gen'   => $withAttrGen,
            'with_any_attr'   => $withAnyAttr,
            'total_attr_woo'  => $totalAttrWoo,
            'total_attr_gen'  => $totalAttrGen,
            'attr_pct'        => $total > 0 ? round($withAnyAttr / $total * 100) : 0,
        ];
    }

    public function getRecentlyEvaluatedWoo(): \Illuminate\Support\Collection
    {
        return DB::table('woo_products')
            ->where('is_placeholder', false)
            ->whereNotNull('short_description')
            ->where('short_description', '!=', '')
            ->orderByDesc('updated_at')
            ->limit(6)
            ->select('id', 'name', 'sku', 'short_description', 'updated_at')
            ->get()
            ->map(function ($p) {
                $p->attr_count = DB::table('woo_product_attributes')
                    ->where('woo_product_id', $p->id)
                    ->count();
                return $p;
            });
    }

    public function getTopAttributes(): \Illuminate\Support\Collection
    {
        return DB::table('woo_product_attributes')
            ->select('name', DB::raw('COUNT(*) as cnt'))
            ->groupBy('name')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get();
    }

    public function getRecentlyCategorized(): \Illuminate\Support\Collection
    {
        return DB::table('woo_product_category as pc')
            ->join('woo_products as wp', 'wp.id', '=', 'pc.woo_product_id')
            ->join('woo_categories as wc', 'wc.id', '=', 'pc.woo_category_id')
            ->leftJoin('woo_categories as parent', 'parent.id', '=', 'wc.parent_id')
            ->where('wp.is_placeholder', true)
            ->where('wp.source', 'winmentor_csv')
            ->orderByDesc('wp.updated_at')
            ->limit(8)
            ->select(
                'wp.name as product_name',
                'wc.name as category_name',
                'parent.name as parent_name',
                'wp.updated_at'
            )
            ->get();
    }
}
