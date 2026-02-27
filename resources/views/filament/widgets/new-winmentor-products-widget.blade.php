@php $data = $this->getData(); @endphp

@if($data['total'] > 0)
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex gap-4 overflow-x-auto">
            @foreach($data['stats'] as $stat)
            <a href="{{ $data['pageUrl'] }}"
               class="flex-1 min-w-0 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-3 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center gap-2 mb-1">
                    <x-filament::icon
                        :icon="$stat['icon']"
                        class="h-4 w-4
                            {{ $stat['color'] === 'warning' ? 'text-warning-500' : '' }}
                            {{ $stat['color'] === 'success' ? 'text-success-500' : '' }}
                            {{ $stat['color'] === 'danger'  ? 'text-danger-500'  : '' }}"
                    />
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 truncate">
                        {{ $stat['label'] }}
                    </span>
                </div>
                <div class="text-2xl font-bold
                    {{ $stat['color'] === 'warning' ? 'text-warning-600 dark:text-warning-400' : '' }}
                    {{ $stat['color'] === 'success' ? 'text-success-600 dark:text-success-400' : '' }}
                    {{ $stat['color'] === 'danger'  ? 'text-danger-600 dark:text-danger-400'   : '' }}">
                    {{ $stat['value'] }}
                </div>
            </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
@endif
