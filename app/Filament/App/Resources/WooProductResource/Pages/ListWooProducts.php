<?php

namespace App\Filament\App\Resources\WooProductResource\Pages;

use App\Filament\App\Resources\WooProductResource;
use App\Models\WooCategory;
use Filament\Resources\Pages\ListRecords;

class ListWooProducts extends ListRecords
{
    protected static string $resource = WooProductResource::class;

    /**
     * Query string tableFilters au prioritate față de sesiune —
     * permite navigarea pe categorii din breadcrumbs/badge-uri.
     */
    public function mount(): void
    {
        $qsFilters = request()->query('tableFilters');

        if (is_array($qsFilters) && ! empty($qsFilters)) {
            $this->tableFilters = $qsFilters;
        }

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make()
                ->label('Creează produs')
                ->icon('heroicon-o-plus'),
        ];
    }

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [
            WooProductResource::getUrl() => 'Produse',
        ];

        // Dacă filtrul categorie e activ, afișăm calea ierarhică
        $catId = data_get($this->tableFilters, 'category_id.value');
        if ($catId) {
            $category = WooCategory::find($catId);
            if ($category) {
                foreach ($category->getAncestorsPath() as $ancestor) {
                    $url = WooProductResource::getUrl('index', [
                        'tableFilters' => ['category_id' => ['value' => (string) $ancestor->id]],
                    ]);
                    $breadcrumbs[$url] = $ancestor->name;
                }
            }
        }

        return $breadcrumbs;
    }
}
