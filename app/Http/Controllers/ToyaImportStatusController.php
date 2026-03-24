<?php

namespace App\Http\Controllers;

use App\Models\WooProduct;
use Illuminate\Support\Facades\DB;

class ToyaImportStatusController extends Controller
{
    public function __invoke()
    {
        $base = WooProduct::query()->where('source', WooProduct::SOURCE_TOYA_API);

        $total      = (clone $base)->count();
        $withImage  = (clone $base)->whereNotNull('main_image_url')->where('main_image_url', '!=', '')->count();
        $withDesc   = (clone $base)->where(fn ($q) => $q->whereNotNull('description')->orWhereNotNull('short_description'))->count();
        $withCat    = (clone $base)->whereHas('categories')->count();
        $instock    = (clone $base)->where('stock_status', 'instock')->count();
        $readyToPub = (clone $base)
            ->whereNotNull('main_image_url')->where('main_image_url', '!=', '')
            ->where(fn ($q) => $q->whereNotNull('description')->orWhereNotNull('short_description'))
            ->whereHas('categories')
            ->count();

        $pct = fn ($v) => $total > 0 ? round($v / $total * 100) : 0;

        return response()->view('toya-import-status', compact(
            'total', 'withImage', 'withDesc', 'withCat', 'instock', 'readyToPub', 'pct'
        ));
    }
}
