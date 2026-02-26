<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class ProductEnrichmentProgress extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Magazin Online';
    protected static ?string $navigationLabel = 'Progres îmbogățire produse';
    protected static ?int    $navigationSort  = 99;

    protected static string $view = 'filament.app.pages.product-enrichment-progress';

    /** Auto-refresh every 8 seconds. */
    protected ?string $pollingInterval = '8s';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && ($user->isSuperAdmin() || $user->role === \App\Models\User::ROLE_MANAGER);
    }

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

        // Top-level breakdown
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

    public function getRecentlyApproved(): \Illuminate\Support\Collection
    {
        return DB::table('product_image_candidates as pic')
            ->join('woo_products as wp', 'wp.id', '=', 'pic.woo_product_id')
            ->where('pic.status', 'approved')
            ->orderByDesc('pic.updated_at')
            ->limit(6)
            ->select('wp.name', 'pic.image_url', 'pic.updated_at')
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
            'total'       => $total,
            'with_attrs'  => $withAttrs,
            'total_attrs' => $totalAttrs,
            'normalized'  => $normalized,
            'reformatted' => $reformatted,
            'attr_pct'    => $total > 0 ? round($withAttrs / $total * 100) : 0,
            'norm_pct'    => $total > 0 ? round($normalized / $total * 100) : 0,
            'reformat_pct' => $total > 0 ? round($reformatted / $total * 100) : 0,
        ];
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
