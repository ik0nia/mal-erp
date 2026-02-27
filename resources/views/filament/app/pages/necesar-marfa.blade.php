<x-filament-panels::page>

    {{-- Header: stat cards + filtru threshold --}}
    <div class="flex flex-wrap items-stretch gap-4">

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500 dark:text-gray-400">Furnizori afectați</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $this->statSuppliers }}</p>
        </div>
        <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-400/20 dark:bg-warning-950/20">
            <p class="text-sm text-warning-700 dark:text-warning-300">Produse sub prag</p>
            <p class="mt-1 text-2xl font-bold text-warning-700 dark:text-warning-300">{{ $this->statProducts }}</p>
        </div>
        <div class="rounded-xl border border-danger-200 bg-danger-50 p-4 dark:border-danger-400/20 dark:bg-danger-950/20">
            <p class="text-sm text-danger-700 dark:text-danger-300">Stoc zero</p>
            <p class="mt-1 text-2xl font-bold text-danger-700 dark:text-danger-300">{{ $this->statZeroStock }}</p>
        </div>

        {{-- Filtru prag --}}
        <div class="ml-auto flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-gray-900">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
                Prag stoc (buc):
            </label>
            <input
                type="number"
                min="0"
                max="100"
                wire:model.live.debounce.500ms="threshold"
                class="w-20 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-center
                       focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500
                       dark:border-white/20 dark:bg-gray-800 dark:text-white"
            />
        </div>
    </div>

    {{-- Lista furnizori --}}
    @if($this->suppliers->isEmpty())
        <div class="rounded-xl border border-success-200 bg-success-50 p-8 text-center dark:border-success-400/20 dark:bg-success-950/20">
            <x-heroicon-o-check-circle class="mx-auto h-10 w-10 text-success-500" />
            <p class="mt-2 text-success-700 dark:text-success-300 font-medium">
                Toate produsele au stoc ≥ {{ $this->threshold }} bucăți. Nicio comandă necesară.
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <table class="w-full table-fixed text-sm">
                <colgroup>
                    <col style="width:9rem">
                    <col>
                    <col style="width:10rem">
                    <col style="width:6rem">
                    <col style="width:4rem">
                </colgroup>
                @foreach($this->suppliers as $supplier)
                    @php
                        $primaryCount = $supplier->products->count();
                        $extraCount   = $supplier->extraProducts->count();
                    @endphp

                    {{-- Supplier header row --}}
                    <tbody>
                        <tr class="border-t border-gray-100 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <td colspan="5" class="px-6 py-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        @if($supplier->logo)
                                            <img src="{{ Storage::disk('public')->url($supplier->logo) }}"
                                                 alt="{{ $supplier->name }}"
                                                 class="h-8 w-8 rounded object-contain">
                                        @endif
                                        <span class="text-base font-semibold text-gray-900 dark:text-white">{{ $supplier->name }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        @if($extraCount > 0)
                                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                                +{{ $extraCount }} noi
                                            </span>
                                        @endif
                                        <span class="rounded-full bg-warning-100 px-2.5 py-0.5 text-xs font-medium text-warning-800 dark:bg-warning-900/30 dark:text-warning-300">
                                            {{ $primaryCount }} {{ $primaryCount === 1 ? 'produs' : 'produse' }}
                                        </span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            <th class="px-6 py-1.5 text-left font-medium">SKU</th>
                            <th class="px-6 py-1.5 text-left font-medium">Produs</th>
                            <th class="px-6 py-1.5 text-left font-medium">Brand</th>
                            <th class="px-6 py-1.5 text-right font-medium">Stoc</th>
                            <th class="px-6 py-1.5 text-left font-medium">UM</th>
                        </tr>
                    </tbody>

                    {{-- Produse cu istoric --}}
                    @if($primaryCount > 0)
                        <tbody class="divide-y divide-gray-50 dark:divide-white/5">
                            @foreach($supplier->products as $product)
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                    <td class="px-6 py-2.5 font-mono text-xs text-gray-500 dark:text-gray-400">
                                        {{ $product->sku ?? '-' }}
                                    </td>
                                    <td class="px-6 py-2.5 font-medium text-gray-900 dark:text-white">
                                        {{ $product->name }}
                                    </td>
                                    <td class="px-6 py-2.5 text-gray-500 dark:text-gray-400">
                                        {{ $product->brand ?? '-' }}
                                    </td>
                                    <td class="px-6 py-2.5 text-right">
                                        @if((float)$product->stock === 0.0)
                                            <span class="inline-flex items-center rounded-full bg-danger-100 px-2 py-0.5 text-xs font-semibold text-danger-700 dark:bg-danger-900/40 dark:text-danger-300">0</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-xs font-semibold text-warning-700 dark:bg-warning-900/40 dark:text-warning-300">{{ number_format((float)$product->stock, 0) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-2.5 text-xs text-gray-400 dark:text-gray-500">
                                        {{ $product->unit ?? 'buc' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @elseif($extraCount > 0)
                        <tbody>
                            <tr>
                                <td colspan="5" class="px-6 py-3 text-sm text-gray-400 dark:text-gray-500 italic">
                                    Niciun produs cu istoric de sincronizare.
                                </td>
                            </tr>
                        </tbody>
                    @endif

                    {{-- Produse fara istoric — expandabile --}}
                    @if($extraCount > 0)
                        <tbody x-data="{ expanded: false }">
                            <tr class="border-t border-dashed border-gray-200 dark:border-white/10">
                                <td colspan="5" class="p-0">
                                    <button
                                        type="button"
                                        @click="expanded = !expanded"
                                        class="flex w-full items-center gap-2 px-6 py-2 text-left text-xs font-medium text-gray-500 transition hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 transition-transform duration-200" :class="expanded ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                        </svg>
                                        <span x-show="!expanded">Arată {{ $extraCount }} {{ $extraCount === 1 ? 'produs nou' : 'produse noi' }} (fără istoric)</span>
                                        <span x-show="expanded" x-cloak>Ascunde produsele noi</span>
                                    </button>
                                </td>
                            </tr>
                            @foreach($supplier->extraProducts as $product)
                                <tr x-show="expanded" x-transition class="opacity-75 bg-gray-50/50 hover:bg-gray-100 dark:bg-white/[0.02] dark:hover:bg-white/5">
                                    <td class="px-6 py-2 font-mono text-xs text-gray-400 dark:text-gray-500">
                                        {{ $product->sku ?? '-' }}
                                    </td>
                                    <td class="px-6 py-2 text-gray-600 dark:text-gray-400">
                                        {{ $product->name }}
                                    </td>
                                    <td class="px-6 py-2 text-gray-400 dark:text-gray-500">
                                        {{ $product->brand ?? '-' }}
                                    </td>
                                    <td class="px-6 py-2 text-right">
                                        @if((float)$product->stock === 0.0)
                                            <span class="inline-flex items-center rounded-full bg-danger-100 px-2 py-0.5 text-xs font-semibold text-danger-700 dark:bg-danger-900/40 dark:text-danger-300">0</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-xs font-semibold text-warning-700 dark:bg-warning-900/40 dark:text-warning-300">{{ number_format((float)$product->stock, 0) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-2 text-xs text-gray-400 dark:text-gray-500">
                                        {{ $product->unit ?? 'buc' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endif

                @endforeach
            </table>
        </div>
    @endif

</x-filament-panels::page>
