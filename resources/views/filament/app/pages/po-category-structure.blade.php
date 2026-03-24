<div
    class="space-y-1 p-1"
    x-data="{
        syncToWire() {
            const qtys = {};
            document.querySelectorAll('.po-qty-input').forEach(function(el) {
                const v = parseFloat(el.value);
                if (!isNaN(v) && v > 0) qtys[el.dataset.pid] = v;
            });
            $wire.set('mountedActionsData.0.qtys_json', JSON.stringify(qtys));
        }
    }"
>

    @php
        $grandTotal = 0;
        $totalProducts = 0;
        foreach ($categories as $products) {
            $totalProducts += count($products);
            foreach ($products as $p) { $grandTotal += $p['recommended']; }
        }
    @endphp

    {{-- Summary bar --}}
    <div class="flex items-center gap-6 px-4 py-3 rounded-xl bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 mb-4">
        <div class="text-sm text-gray-500">
            <span class="font-bold text-gray-900 dark:text-gray-100 text-base">{{ count($categories) }}</span> categorii
        </div>
        <div class="text-sm text-gray-500">
            <span class="font-bold text-gray-900 dark:text-gray-100 text-base">{{ $totalProducts }}</span> produse cu rulaj
        </div>
        <div class="text-sm text-gray-500 ml-auto">
            Total recomandat: <span class="font-bold text-primary-600 text-base">{{ number_format($grandTotal) }}</span> buc
        </div>
    </div>

    @foreach($categories as $catName => $products)
        @php $catTotal = array_sum(array_column($products, 'recommended')); @endphp

        <details class="group border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden" open>
            <summary class="flex items-center justify-between px-4 py-2.5 cursor-pointer bg-indigo-50 dark:bg-indigo-900/20 hover:bg-indigo-100 dark:hover:bg-indigo-900/30 select-none">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-chevron-right class="w-4 h-4 text-indigo-500 transition-transform group-open:rotate-90"/>
                    <span class="font-semibold text-sm text-indigo-800 dark:text-indigo-300">{{ $catName }}</span>
                    <span class="text-xs text-indigo-500 font-normal">({{ count($products) }} produse)</span>
                </div>
                <span class="text-xs font-bold text-indigo-700 dark:text-indigo-300 bg-indigo-100 dark:bg-indigo-900/40 px-2 py-0.5 rounded-full">
                    {{ number_format($catTotal) }} buc recomandat
                </span>
            </summary>

            <table class="w-full text-xs border-t border-gray-200 dark:border-gray-700">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800 text-gray-500 uppercase tracking-wide text-[10px]">
                        <th class="px-3 py-2 text-left font-semibold w-[35%]">Produs</th>
                        <th class="px-3 py-2 text-left font-semibold">SKU Furnizor</th>
                        <th class="px-3 py-2 text-right font-semibold">Stoc</th>
                        <th class="px-3 py-2 text-right font-semibold">Vânz. 7z</th>
                        <th class="px-3 py-2 text-right font-semibold">Vânz. 30z</th>
                        <th class="px-3 py-2 text-right font-semibold text-primary-700">Recomandat</th>
                        <th class="px-3 py-2 text-right font-semibold text-green-700">Cantitate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($products as $p)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="px-3 py-2">
                                <div class="font-medium text-gray-800 dark:text-gray-200 leading-snug">{{ $p['name'] }}</div>
                                <div class="text-gray-400 text-[10px]">{{ $p['sku'] }}</div>
                            </td>
                            <td class="px-3 py-2 text-gray-500">{{ $p['supplier_sku'] ?: '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                <span class="{{ $p['stock'] <= 0 ? 'text-red-600 font-semibold' : 'text-gray-700 dark:text-gray-300' }}">
                                    {{ number_format($p['stock'], 1) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-400">
                                {{ number_format($p['sales_7d'], 1) }}
                            </td>
                            <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-400">
                                {{ number_format($p['sales_30d'], 1) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                @if($p['recommended'] > 0)
                                    <span class="font-bold text-primary-600 bg-primary-50 dark:bg-primary-900/20 px-2 py-0.5 rounded">
                                        {{ number_format($p['recommended']) }}
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right">
                                <input
                                    type="number"
                                    class="po-qty-input w-20 text-right border border-gray-300 dark:border-gray-600 rounded px-1 py-0.5 text-xs bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-1 focus:ring-green-500"
                                    data-pid="{{ $p['woo_product_id'] }}"
                                    value="{{ $currentQtys[$p['woo_product_id']] ?? ($p['recommended'] > 0 ? $p['recommended'] : '') }}"
                                    min="0"
                                    step="1"
                                    placeholder="0"
                                    x-on:input="syncToWire()"
                                >
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endforeach

    @if(empty($categories))
        <div class="text-center py-12 text-gray-400 text-sm">
            Niciun produs cu rulaj găsit pentru acest furnizor.
        </div>
    @endif

</div>
