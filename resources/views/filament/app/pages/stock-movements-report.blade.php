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

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Intrări stoc</div>
            <div class="mt-1 text-3xl font-bold text-success-600 dark:text-success-400">{{ number_format($this->statTotalInQty) }}</div>
            <div class="mt-1 text-xs text-gray-400">{{ number_format($this->statTotalInValue, 2, ',', '.') }} lei</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Ieșiri stoc</div>
            <div class="mt-1 text-3xl font-bold text-danger-600 dark:text-danger-400">{{ number_format($this->statTotalOutQty) }}</div>
            <div class="mt-1 text-xs text-gray-400">{{ number_format($this->statTotalOutValue, 2, ',', '.') }} lei</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Produse cu mișcări</div>
            <div class="mt-1 text-3xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($this->statProductsWithMovement) }}</div>
            <div class="mt-1 text-xs text-gray-400">în ultimele {{ $this->days }} zile</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Prețuri modificate</div>
            <div class="mt-1 text-3xl font-bold text-warning-600 dark:text-warning-400">{{ number_format($this->statProductsWithPriceChange) }}</div>
            <div class="mt-1 text-xs text-gray-400">în ultimele {{ $this->days }} zile</div>
        </div>

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
