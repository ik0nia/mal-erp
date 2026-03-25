<x-filament-panels::page>
<div class="space-y-5">

    {{-- Stat cards --}}
    @php $stats = $this->getStats(); @endphp
    <div class="grid grid-cols-3 gap-4">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <p class="text-xs text-gray-500">Prețuri extrase total</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <p class="text-xs text-gray-500">Potrivite cu catalog</p>
            <p class="text-2xl font-bold text-blue-600">{{ number_format($stats['matched']) }}</p>
            @if($stats['total'] > 0)
            <p class="text-xs text-gray-400">{{ round($stats['matched']/$stats['total']*100) }}% din total</p>
            @endif
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <p class="text-xs text-gray-500">Mai ieftin cu >5% față de catalog</p>
            <p class="text-2xl font-bold text-green-600">{{ number_format($stats['cheaper']) }}</p>
            <p class="text-xs text-gray-400">Potențial de negociere</p>
        </div>
    </div>

    {{-- Filtre --}}
    <div class="flex flex-wrap gap-3 items-center">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Caută produs..."
            class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-sm dark:bg-gray-800 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500 w-56"/>

        <select wire:model.live="filterSupplier"
            class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-sm dark:bg-gray-800 dark:text-gray-200 focus:outline-none">
            <option value="">Toți furnizorii</option>
            @foreach($this->getSupplierOptions() as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>

        <div class="flex gap-1">
            @foreach(['' => 'Toate', 'matched' => 'Potrivite catalog', 'unmatched' => 'Nepotrivite'] as $val => $label)
            <button wire:click="$set('filterMatched', '{{ $val }}')"
                class="text-xs px-3 py-1.5 rounded-full border transition
                    {{ $filterMatched === $val
                        ? 'bg-primary-600 border-primary-600 text-white'
                        : 'border-gray-300 text-gray-600 hover:border-primary-400 dark:border-gray-600 dark:text-gray-400' }}">
                {{ $label }}
            </button>
            @endforeach
        </div>
    </div>

    {{-- Tabel --}}
    @php $quotes = $this->getQuotes(); @endphp
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        @if($quotes->isEmpty())
            <div class="p-12 text-center text-gray-400">
                <x-filament::icon icon="heroicon-o-currency-dollar" class="w-12 h-12 mx-auto mb-3 opacity-30"/>
                <p class="text-sm">Nu există prețuri extrase încă.</p>
                <p class="text-xs mt-1 text-gray-300">Procesarea AI a emailurilor va extrage prețurile automat.</p>
            </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                    <th class="px-4 py-2.5 font-medium">Furnizor</th>
                    <th class="px-4 py-2.5 font-medium">Produs (din email)</th>
                    <th class="px-4 py-2.5 font-medium">Produs catalog</th>
                    <th class="px-4 py-2.5 font-medium text-right">Preț oferit</th>
                    <th class="px-4 py-2.5 font-medium text-right">Preț catalog</th>
                    <th class="px-4 py-2.5 font-medium text-right">Delta</th>
                    <th class="px-4 py-2.5 font-medium">Data ofertei</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($quotes as $row)
                @php $q = $row['quote']; @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                    <td class="px-4 py-2.5">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200">
                            {{ $q->supplier?->name ?? '—' }}
                        </span>
                    </td>
                    <td class="px-4 py-2.5">
                        <span class="text-gray-700 dark:text-gray-300">{{ $q->product_name_raw }}</span>
                        @if($q->min_qty)
                            <span class="text-xs text-gray-400 ml-1">min {{ $q->min_qty }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5">
                        @if($q->product)
                            <span class="text-xs bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 rounded px-1.5 py-0.5">
                                {{ Str::limit($q->product->name, 40) }}
                            </span>
                        @else
                            <span class="text-xs text-gray-400 italic">nepotrivit</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-right font-semibold text-gray-900 dark:text-white">
                        {{ number_format($q->unit_price, 2) }} <span class="text-xs font-normal text-gray-400">{{ $q->currency }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-right">
                        @if($row['currentPrice'])
                            <span class="text-gray-700 dark:text-gray-300">{{ number_format($row['currentPrice'], 2) }}</span>
                            <span class="text-xs text-gray-400 ml-0.5">RON</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-right">
                        @if($row['delta'] !== null)
                            @php
                                $deltaClasses = match($row['deltaColor']) {
                                    'green' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                                    'red'   => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                    default => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
                                };
                            @endphp
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium {{ $deltaClasses }}">
                                {{ $row['delta'] > 0 ? '+' : '' }}{{ $row['delta'] }}%
                            </span>
                        @else
                            <span class="text-gray-300 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">
                        {{ $q->quoted_at ? \Carbon\Carbon::parse($q->quoted_at)->format('d.m.Y') : '—' }}
                        @if($q->email)
                            <span class="ml-1 text-gray-300 text-xs" title="{{ $q->email->subject }}">↗</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-4 py-2 text-xs text-gray-400 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-100 dark:border-gray-800">
            {{ $quotes->count() }} prețuri afișate (max 200) · procesarea AI continuă în background
        </div>
        @endif
    </div>

</div>
</x-filament-panels::page>
