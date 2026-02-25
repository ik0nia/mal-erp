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
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        {{-- Intrări --}}
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-white/10">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Intrări stoc</p>
            <p class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                {{ number_format($this->statTotalInQty) }}<span class="text-sm font-normal text-gray-500 dark:text-gray-400"> buc</span>
            </p>
            <p class="mt-0.5 text-sm text-emerald-600 dark:text-emerald-400">
                {{ number_format($this->statTotalInValue, 2, ',', '.') }} lei
            </p>
        </div>

        {{-- Ieșiri --}}
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-white/10">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Ieșiri stoc</p>
            <p class="mt-2 text-2xl font-bold text-red-600 dark:text-red-400">
                {{ number_format($this->statTotalOutQty) }}<span class="text-sm font-normal text-gray-500 dark:text-gray-400"> buc</span>
            </p>
            <p class="mt-0.5 text-sm text-red-600 dark:text-red-400">
                {{ number_format($this->statTotalOutValue, 2, ',', '.') }} lei
            </p>
        </div>

        {{-- Produse cu mișcări --}}
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-white/10">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Produse cu mișcări</p>
            <p class="mt-2 text-2xl font-bold text-primary-600 dark:text-primary-400">
                {{ number_format($this->statProductsWithMovement) }}<span class="text-sm font-normal text-gray-500 dark:text-gray-400"> produse</span>
            </p>
            <p class="mt-0.5 text-sm text-gray-400 dark:text-gray-500">în ultimele {{ $this->days }} zile</p>
        </div>

        {{-- Prețuri modificate --}}
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-white/10">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Prețuri modificate</p>
            <p class="mt-2 text-2xl font-bold text-amber-600 dark:text-amber-400">
                {{ number_format($this->statProductsWithPriceChange) }}<span class="text-sm font-normal text-gray-500 dark:text-gray-400"> produse</span>
            </p>
            <p class="mt-0.5 text-sm text-gray-400 dark:text-gray-500">în ultimele {{ $this->days }} zile</p>
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
