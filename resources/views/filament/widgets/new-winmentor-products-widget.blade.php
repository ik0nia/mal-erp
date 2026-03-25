@php $data = $this->getData(); @endphp

@if($data['total'] > 0)
<style>
.wm-stats { display:flex; flex-wrap:wrap; gap:0.75rem; }
.wm-stat  { flex:1 1 calc(50% - 0.375rem); min-width:0; }
@@media(min-width:768px){ .wm-stat { flex:1 1 0; } }
</style>
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="wm-stats">
            @foreach($data['stats'] as $stat)
            <a href="{{ $data['pageUrl'] }}"
               class="wm-stat rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-3 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center gap-2 mb-1">
                    <x-filament::icon
                        :icon="$stat['icon']"
                        @class([
                            'h-4 w-4 shrink-0',
                            'text-warning-500' => $stat['color'] === 'warning',
                            'text-success-500' => $stat['color'] === 'success',
                            'text-danger-500'  => $stat['color'] === 'danger',
                        ])
                    />
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 truncate">
                        {{ $stat['label'] }}
                    </span>
                </div>
                <div @class([
                    'text-2xl font-bold',
                    'text-warning-600 dark:text-warning-400' => $stat['color'] === 'warning',
                    'text-success-600 dark:text-success-400' => $stat['color'] === 'success',
                    'text-danger-600 dark:text-danger-400'   => $stat['color'] === 'danger',
                ])>
                    {{ $stat['value'] }}
                </div>
            </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
@endif
