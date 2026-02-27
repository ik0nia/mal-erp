<?php

namespace App\Filament\App\Widgets;

use App\Models\WooProduct;
use Filament\Widgets\Widget;

class NewWinmentorProductsWidget extends Widget
{
    protected static ?int $sort = -10;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.new-winmentor-products-widget';

    public function getData(): array
    {
        $base = WooProduct::query()
            ->where('is_placeholder', true)
            ->where('source', WooProduct::SOURCE_WINMENTOR_CSV);

        $total = (clone $base)->count();

        if ($total === 0) {
            return ['total' => 0, 'stats' => []];
        }

        $withImage       = (clone $base)->whereNotNull('main_image_url')->where('main_image_url', '!=', '')->count();
        $withDescription = (clone $base)->where(fn ($q) => $q->whereNotNull('description')->orWhereNotNull('short_description'))->count();
        $withCategory    = (clone $base)->whereHas('categories')->count();
        $withBrand       = (clone $base)->whereNotNull('brand')->where('brand', '!=', '')->count();
        $withSupplier    = (clone $base)->whereHas('suppliers')->count();

        $pageUrl = \App\Filament\App\Pages\NewWinmentorProducts::getUrl();

        return [
            'total'   => $total,
            'pageUrl' => $pageUrl,
            'stats'   => [
                ['label' => 'Produse noi WinMentor', 'value' => $total,                    'color' => 'warning', 'icon' => 'heroicon-o-sparkles'],
                ['label' => 'Fără poză',              'value' => $total - $withImage,       'color' => $withImage === $total       ? 'success' : 'danger', 'icon' => 'heroicon-o-photo'],
                ['label' => 'Fără descriere',         'value' => $total - $withDescription, 'color' => $withDescription === $total ? 'success' : 'danger', 'icon' => 'heroicon-o-document-text'],
                ['label' => 'Fără categorie',         'value' => $total - $withCategory,    'color' => $withCategory === $total    ? 'success' : 'danger', 'icon' => 'heroicon-o-tag'],
                ['label' => 'Fără furnizor',          'value' => $total - $withSupplier,    'color' => $withSupplier === $total    ? 'success' : 'danger', 'icon' => 'heroicon-o-building-office'],
            ],
        ];
    }
}
