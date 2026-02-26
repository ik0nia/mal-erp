<x-filament-panels::page>

    {{-- Filters row: interval + furnizor --}}
    <div class="flex flex-wrap items-center gap-3">

        {{-- Day pills --}}
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

        {{-- Divider --}}
        <span class="text-gray-300 dark:text-gray-600">|</span>

        {{-- Supplier filter --}}
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500 dark:text-gray-400">Furnizor:</span>
            <select
                wire:change="setSupplier($event.target.value ? Number($event.target.value) : null)"
                class="rounded-lg border border-gray-200 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-gray-300"
            >
                <option value="">Toți furnizorii</option>
                @foreach($this->supplierOptions as $id => $name)
                    <option value="{{ $id }}" {{ $this->supplierId === $id ? 'selected' : '' }}>{{ $name }}</option>
                @endforeach
            </select>
            @if($this->supplierId)
                <button
                    wire:click="setSupplier(null)"
                    class="text-xs text-gray-400 hover:text-danger-500 transition"
                    title="Șterge filtrul"
                >✕</button>
            @endif
        </div>

        {{-- Category filter chip (vizibil doar când e selectată o categorie) --}}
        @if($this->categoryId)
        <span class="text-gray-300 dark:text-gray-600">|</span>
        <div class="flex items-center gap-1.5">
            <span class="text-sm text-gray-500 dark:text-gray-400">Categorie:</span>
            <span class="rounded-full bg-primary-100 dark:bg-primary-900/40 px-3 py-1 text-sm font-medium text-primary-700 dark:text-primary-300">
                {{ $this->categoryName }}
            </span>
            <button wire:click="setCategory(null)"
                class="text-xs text-gray-400 hover:text-danger-500 transition" title="Șterge filtrul">✕</button>
        </div>
        @endif

    </div>

    {{-- Stat cards —— global --}}
    <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem;">

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

    {{-- Supplier chart --}}
    @if(count($this->supplierStats) > 0)
    <div>
        @livewire(\App\Filament\App\Widgets\SupplierMovementChartWidget::class)
    </div>
    @endif

    {{-- Supplier stats table --}}
    @if(count($this->supplierStats) > 0)
    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-white/5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                Statistici furnizori — ultimele {{ $this->days }} zile
            </h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-white/5 bg-gray-50 dark:bg-white/5">
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Furnizor</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Produse</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Intrări (buc)</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Ieșiri (buc)</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Val. mișcări (lei)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach($this->supplierStats as $s)
                    <tr
                        wire:click="setSupplier({{ $this->supplierId === $s['id'] ? 'null' : $s['id'] }})"
                        class="cursor-pointer transition hover:bg-primary-50 dark:hover:bg-primary-900/20
                            {{ $this->supplierId === $s['id'] ? 'bg-primary-50 dark:bg-primary-900/30' : '' }}"
                    >
                        <td class="px-4 py-2.5 font-medium text-gray-800 dark:text-gray-200">
                            {{ $s['name'] }}
                            @if($this->supplierId === $s['id'])
                                <span class="ml-1 text-xs text-primary-500">● filtrat</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right text-gray-600 dark:text-gray-400">{{ number_format($s['products']) }}</td>
                        <td class="px-4 py-2.5 text-right text-success-600 dark:text-success-400">+{{ number_format($s['in_qty'], 0) }}</td>
                        <td class="px-4 py-2.5 text-right text-danger-600 dark:text-danger-400">-{{ number_format($s['out_qty'], 0) }}</td>
                        <td class="px-4 py-2.5 text-right font-semibold text-gray-800 dark:text-gray-200">{{ number_format($s['value'], 2, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Category cards --}}
    @if(count($this->categoryStats) > 0)
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
        @foreach($this->categoryStats as $cat)
        <button
            wire:click="setCategory({{ $this->categoryId === $cat['id'] ? 'null' : $cat['id'] }})"
            class="rounded-xl border p-3 text-left transition focus:outline-none
                {{ $this->categoryId === $cat['id']
                    ? 'border-primary-400 bg-primary-50 dark:border-primary-500 dark:bg-primary-900/30 ring-1 ring-primary-400'
                    : 'border-gray-200 bg-white hover:border-primary-300 hover:bg-primary-50/50 dark:border-white/10 dark:bg-gray-900 dark:hover:bg-primary-900/10' }}"
        >
            <div class="truncate text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $cat['name'] }}</div>
            <div class="mt-1 text-lg font-bold text-gray-800 dark:text-gray-100">
                {{ number_format($cat['value'], 0, ',', '.') }}
                <span class="text-xs font-normal text-gray-400">lei</span>
            </div>
            <div class="mt-0.5 text-xs text-gray-400">{{ number_format($cat['products']) }} produse</div>
        </button>
        @endforeach
    </div>
    @endif

    {{-- Top movers table --}}
    <div>
        <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
            Produse cu cele mai mari mișcări de stoc în ultimele <strong>{{ $this->days }}</strong> zile
            @if($this->supplierId && isset($this->supplierOptions[$this->supplierId]))
                — furnizor: <strong>{{ $this->supplierOptions[$this->supplierId] }}</strong>
            @endif
            @if($this->categoryId && $this->categoryName)
                — categorie: <strong>{{ $this->categoryName }}</strong>
            @endif.
            Click pe produs pentru detalii.
        </p>
        {{ $this->table }}
    </div>

</x-filament-panels::page>
