<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class NavOrderPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-bars-3';
    protected static ?string $navigationLabel = 'Ordine meniuri';
    protected static ?string $title           = 'Ordine meniu navigare';
    protected static ?int    $navigationSort  = 97;
    protected string  $view            = 'filament.pages.nav-order';

    public array $groups = [];

    public function mount(): void
    {
        $this->loadGroups();
    }

    private function loadGroups(): void
    {
        $itemsByGroup = [];
        foreach (AppSetting::NAV_ITEMS as $key => $meta) {
            $sort = (int) AppSetting::get($key, (string) $meta['default']);
            $itemsByGroup[$meta['group']][] = [
                'key'   => $key,
                'label' => $meta['label'],
                'sort'  => $sort,
            ];
        }

        foreach ($itemsByGroup as &$items) {
            usort($items, fn ($a, $b) => $a['sort'] <=> $b['sort']);
        }

        $groups = [];
        foreach (AppSetting::NAV_GROUPS as $key => $meta) {
            $sort     = (int) AppSetting::get($key, (string) $meta['default']);
            $groups[] = [
                'key'   => $key,
                'label' => $meta['label'],
                'sort'  => $sort,
                'items' => $itemsByGroup[$meta['label']] ?? [],
            ];
        }

        usort($groups, fn ($a, $b) => $a['sort'] <=> $b['sort']);
        $this->groups = array_values($groups);
    }

    public function updateGroupOrder(array $orderedKeys): void
    {
        $indexed = collect($this->groups)->keyBy('key')->all();
        $reordered = [];
        foreach ($orderedKeys as $i => $key) {
            if (isset($indexed[$key])) {
                $indexed[$key]['sort'] = $i + 1;
                $reordered[] = $indexed[$key];
            }
        }
        $this->groups = $reordered;
    }

    public function updateItemOrder(string $groupKey, array $orderedKeys): void
    {
        foreach ($this->groups as &$group) {
            if ($group['key'] === $groupKey) {
                $indexed = collect($group['items'])->keyBy('key')->all();
                $reordered = [];
                foreach ($orderedKeys as $i => $key) {
                    if (isset($indexed[$key])) {
                        $indexed[$key]['sort'] = $i + 1;
                        $reordered[] = $indexed[$key];
                    }
                }
                $group['items'] = $reordered;
                break;
            }
        }
    }

    public function save(): void
    {
        foreach ($this->groups as $i => $group) {
            AppSetting::set($group['key'], (string) ($i + 1));
            foreach ($group['items'] as $j => $item) {
                AppSetting::set($item['key'], (string) ($j + 1));
            }
        }

        Notification::make()->success()->title('Ordinea meniurilor a fost salvată.')->send();
    }
}
