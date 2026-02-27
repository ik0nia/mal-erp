<x-filament-panels::page>

    {{-- Year / Month filters --}}
    <div class="flex flex-wrap items-center gap-3">

        {{-- Year pills --}}
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500 dark:text-gray-400">An:</span>
            @foreach($this->availableYears as $yr)
                <button
                    wire:click="setYear({{ $yr }})"
                    class="rounded-full px-4 py-1.5 text-sm font-medium transition focus:outline-none
                        {{ $this->year === $yr
                            ? 'bg-primary-600 text-white shadow-sm'
                            : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 dark:bg-gray-800 dark:border-white/10 dark:text-gray-300 dark:hover:bg-gray-700' }}"
                >{{ $yr }}</button>
            @endforeach
        </div>

        <span class="text-gray-300 dark:text-gray-600">|</span>

        {{-- Month pills --}}
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="text-sm text-gray-500 dark:text-gray-400">Lună:</span>
            <button
                wire:click="setMonth(null)"
                class="rounded-full px-3 py-1.5 text-sm font-medium transition focus:outline-none
                    {{ $this->month === null
                        ? 'bg-primary-600 text-white shadow-sm'
                        : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 dark:bg-gray-800 dark:border-white/10 dark:text-gray-300 dark:hover:bg-gray-700' }}"
            >Toate</button>
            @foreach(['Ian','Feb','Mar','Apr','Mai','Iun','Iul','Aug','Sep','Oct','Nov','Dec'] as $idx => $mn)
                <button
                    wire:click="setMonth({{ $idx + 1 }})"
                    class="rounded-full px-3 py-1.5 text-sm font-medium transition focus:outline-none
                        {{ $this->month === ($idx + 1)
                            ? 'bg-primary-600 text-white shadow-sm'
                            : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 dark:bg-gray-800 dark:border-white/10 dark:text-gray-300 dark:hover:bg-gray-700' }}"
                >{{ $mn }}</button>
            @endforeach
        </div>

    </div>

    {{-- Stat cards --}}
    <div style="display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 1rem;">

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total vânzări</div>
            <div class="mt-1 text-2xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($this->statRevenue, 2, ',', '.') }}</div>
            <div class="mt-1 text-xs text-gray-400">lei (fără anulate)</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Comenzi active</div>
            <div class="mt-1 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ number_format($this->statOrders) }}</div>
            <div class="mt-1 text-xs text-gray-400">fără anulate/eșuate</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Medie/comandă</div>
            <div class="mt-1 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ number_format($this->statAvgOrder, 2, ',', '.') }}</div>
            <div class="mt-1 text-xs text-gray-400">lei</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Finalizate</div>
            <div class="mt-1 text-2xl font-bold text-success-600 dark:text-success-400">{{ number_format($this->statCompleted) }}</div>
            <div class="mt-1 text-xs text-gray-400">
                @if($this->statOrders > 0)
                    {{ round($this->statCompleted / $this->statOrders * 100) }}% din total
                @else
                    &mdash;
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">În procesare</div>
            <div class="mt-1 text-2xl font-bold text-warning-600 dark:text-warning-400">{{ number_format($this->statProcessing) }}</div>
            <div class="mt-1 text-xs text-gray-400">
                @if($this->statOrders > 0)
                    {{ round($this->statProcessing / $this->statOrders * 100) }}% din total
                @else
                    &mdash;
                @endif
            </div>
        </div>

    </div>

    {{-- Charts: sales bar + status doughnut --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="md:col-span-2">
            @livewire(\App\Filament\App\Widgets\SalesChartWidget::class)
        </div>
        <div>
            @livewire(\App\Filament\App\Widgets\OrderStatusChartWidget::class)
        </div>
    </div>

    {{-- Root category cards --}}
    @if(count($this->categoryData) > 0)
    @php $maxCatRevenue = max(array_column($this->categoryData, 'revenue')) ?: 1; @endphp
    <div>
        <h3 class="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200">Vânzări pe categorii principale</h3>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
            @foreach($this->categoryData as $cat)
            <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
                <div class="truncate text-xs font-semibold text-gray-700 dark:text-gray-200" title="{{ $cat['name'] }}">{{ $cat['name'] }}</div>
                <div class="mt-1 text-lg font-bold text-gray-800 dark:text-gray-100">
                    {{ number_format($cat['revenue'], 0, ',', '.') }}
                    <span class="text-xs font-normal text-gray-400">lei</span>
                </div>
                <div class="mt-1.5 h-1.5 w-full rounded-full bg-gray-100 dark:bg-white/10">
                    <div class="h-1.5 rounded-full bg-primary-500"
                         style="width: {{ round($cat['revenue'] / $maxCatRevenue * 100) }}%"></div>
                </div>
                <div class="mt-1 text-xs text-gray-400">{{ number_format($cat['orders']) }} comenzi</div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Supplier + Brand tables --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

        {{-- Top furnizori --}}
        @if(count($this->supplierData) > 0)
        @php $maxSupRevenue = max(array_column($this->supplierData, 'revenue')) ?: 1; @endphp
        <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-white/5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Top furnizori</h3>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach($this->supplierData as $s)
                <div class="px-4 py-3">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $s['name'] }}</span>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ number_format($s['revenue'], 2, ',', '.') }} lei</span>
                    </div>
                    <div class="h-1.5 w-full rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-1.5 rounded-full bg-indigo-500"
                             style="width: {{ round($s['revenue'] / $maxSupRevenue * 100) }}%"></div>
                    </div>
                    <div class="mt-1 text-xs text-gray-400">{{ number_format($s['orders']) }} comenzi</div>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Top brand-uri --}}
        @if(count($this->brandData) > 0)
        @php $maxBrandRevenue = max(array_column($this->brandData, 'revenue')) ?: 1; @endphp
        <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-white/5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Top brand-uri</h3>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach($this->brandData as $b)
                <div class="px-4 py-3">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $b['name'] }}</span>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ number_format($b['revenue'], 2, ',', '.') }} lei</span>
                    </div>
                    <div class="h-1.5 w-full rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-1.5 rounded-full bg-emerald-500"
                             style="width: {{ round($b['revenue'] / $maxBrandRevenue * 100) }}%"></div>
                    </div>
                    <div class="mt-1 text-xs text-gray-400">{{ number_format($b['orders']) }} comenzi</div>
                </div>
                @endforeach
            </div>
        </div>
        @endif

    </div>

    {{-- Status breakdown table --}}
    @if(count($this->statusData) > 0)
    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-white/5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Detaliu comenzi pe status</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-white/5 bg-gray-50 dark:bg-white/5">
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Comenzi</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Valoare (lei)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @php
                        $statusLabels = [
                            'completed'  => 'Finalizate',
                            'processing' => 'În procesare',
                            'cancelled'  => 'Anulate',
                            'on-hold'    => 'În așteptare',
                            'refunded'   => 'Rambursate',
                            'failed'     => 'Eșuate',
                            'pending'    => 'În așteptare plată',
                        ];
                    @endphp
                    @foreach($this->statusData as $s)
                    <tr>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium
                                @switch($s['status'])
                                    @case('completed') bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-400 @break
                                    @case('processing') bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-400 @break
                                    @case('on-hold') bg-warning-100 text-warning-700 dark:bg-warning-900/40 dark:text-warning-400 @break
                                    @case('cancelled') bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-400 @break
                                    @case('refunded') bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400 @break
                                    @default bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400
                                @endswitch
                            ">{{ $statusLabels[$s['status']] ?? $s['status'] }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-right text-gray-600 dark:text-gray-400">{{ number_format($s['cnt']) }}</td>
                        <td class="px-4 py-2.5 text-right font-semibold text-gray-800 dark:text-gray-200">{{ number_format($s['revenue'], 2, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5">
                        <td class="px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-gray-200">Total</td>
                        <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-700 dark:text-gray-200">
                            {{ number_format(array_sum(array_column($this->statusData, 'cnt'))) }}
                        </td>
                        <td class="px-4 py-2.5 text-right text-sm font-bold text-primary-600 dark:text-primary-400">
                            {{ number_format(array_sum(array_column($this->statusData, 'revenue')), 2, ',', '.') }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endif

</x-filament-panels::page>
