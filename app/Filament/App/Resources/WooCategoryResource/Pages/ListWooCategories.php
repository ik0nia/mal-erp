<?php

namespace App\Filament\App\Resources\WooCategoryResource\Pages;

use App\Filament\App\Resources\WooCategoryResource;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use App\Models\WooCategory;
use Filament\Resources\Pages\ListRecords;

class ListWooCategories extends ListRecords
{
    protected static string $resource = WooCategoryResource::class;

    public function getTableRecords(): EloquentCollection | Paginator | CursorPaginator
    {
        $records = parent::getTableRecords();

        if (! $records instanceof EloquentCollection) {
            return $records;
        }

        return $this->sortHierarchically($records);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function sortHierarchically(EloquentCollection $records): EloquentCollection
    {
        if ($records->isEmpty()) {
            return $records;
        }

        $ordered = [];

        foreach ($records->groupBy('connection_id')->sortKeys() as $connectionRecords) {
            /** @var EloquentCollection<int, WooCategory> $connectionRecords */
            $recordsById = $connectionRecords->keyBy('id');
            $childrenByParent = [];

            foreach ($connectionRecords as $record) {
                $parentId = $record->parent_id;

                if (! $parentId || ! $recordsById->has($parentId)) {
                    $childrenByParent[0][] = $record;

                    continue;
                }

                $childrenByParent[$parentId][] = $record;
            }

            foreach ($childrenByParent as &$children) {
                usort($children, function (WooCategory $a, WooCategory $b): int {
                    $menuOrderA = $a->menu_order ?? PHP_INT_MAX;
                    $menuOrderB = $b->menu_order ?? PHP_INT_MAX;

                    if ($menuOrderA !== $menuOrderB) {
                        return $menuOrderA <=> $menuOrderB;
                    }

                    return strcasecmp($a->name, $b->name);
                });
            }
            unset($children);

            $walk = function (array $nodes, int $depth) use (&$walk, &$ordered, $childrenByParent): void {
                foreach ($nodes as $node) {
                    $node->setAttribute('_tree_depth', $depth);
                    $ordered[] = $node;

                    $walk($childrenByParent[$node->id] ?? [], $depth + 1);
                }
            };

            $walk($childrenByParent[0] ?? [], 0);
        }

        return new EloquentCollection($ordered);
    }
}
