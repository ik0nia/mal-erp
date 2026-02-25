<x-filament-panels::page>

    {{-- Day filter pills --}}
    <div class="flex items-center gap-2">
        <span class="text-sm text-gray-500 dark:text-gray-400">Interval:</span>
        @foreach([7 => '7 zile', 14 => '14 zile', 30 => '30 zile'] as $n => $label)
            <button
                wire:click="setDays({{ $n }})"
                class="rounded-full px-4 py-1.5 text-sm font-medium transition focus:outline-none
                    {{ $this->days === $n
                        ? 'bg-primary-600 text-white shadow-sm'
                        : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 dark:bg-gray-800 dark:border-white/10 dark:text-gray-300 dark:hover:bg-gray-700' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Charts --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        @livewire(\App\Filament\App\Widgets\StockMovementChartWidget::class)
        @livewire(\App\Filament\App\Widgets\PriceMovementChartWidget::class)
    </div>

    {{-- Top movers table --}}
    <div>
        <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
            Produse cu cele mai mari mișcări de stoc în ultimele <strong>{{ $this->days }}</strong> zile.
            Click pe produs pentru detalii.
        </p>
        {{ $this->table }}
    </div>

</x-filament-panels::page>
