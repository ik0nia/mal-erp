<x-filament-panels::page>

    {{-- Filtre --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <div class="flex-1 min-w-48">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Furnizor</label>
            <select wire:model.live="filterSupplierId"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">Toți furnizorii</option>
                @foreach($supplierOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-end gap-4">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" wire:model.live="showUrgentOnly"
                       class="rounded border-gray-300 text-danger-600 focus:ring-danger-500">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Doar urgente</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" wire:model.live="showReservedOnly"
                       class="rounded border-gray-300 text-warning-600 focus:ring-warning-500">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Doar rezervate</span>
            </label>
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <p class="text-sm text-gray-500 dark:text-gray-400">Total în așteptare</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ $totalPending }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-danger-200 dark:border-danger-800 p-4 shadow-sm">
            <p class="text-sm text-danger-600 dark:text-danger-400">Urgente</p>
            <p class="text-3xl font-bold text-danger-600 dark:text-danger-400 mt-1">{{ $totalUrgent }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-warning-200 dark:border-warning-700 p-4 shadow-sm">
            <p class="text-sm text-warning-600 dark:text-warning-400">Rezervate</p>
            <p class="text-3xl font-bold text-warning-600 dark:text-warning-400 mt-1">{{ $totalReserved }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <p class="text-sm text-gray-500 dark:text-gray-400">Furnizori afectați</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ $totalSuppliers }}</p>
        </div>
    </div>

    @if(empty($supplierGroups))
        <div class="text-center py-16 text-gray-400 dark:text-gray-500">
            <x-heroicon-o-check-circle class="w-12 h-12 mx-auto mb-3 text-success-400"/>
            <p class="text-lg font-medium">Niciun necesar în așteptare</p>
            <p class="text-sm mt-1">Toate necesarele trimise au fost procesate.</p>
        </div>
    @else
        <div class="space-y-6">
            @foreach($supplierGroups as $group)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden
                            {{ $group['urgent_count'] > 0 ? 'border-l-4 border-l-danger-500' : '' }}">

                    {{-- Header furnizor --}}
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <div class="flex items-center gap-3">
                            @if($group['supplier_logo'])
                                <img src="{{ Storage::url($group['supplier_logo']) }}"
                                     alt="{{ $group['supplier_name'] }}"
                                     class="w-10 h-10 object-contain rounded bg-white border border-gray-200 p-1">
                            @else
                                <div class="w-10 h-10 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                    <x-heroicon-o-truck class="w-5 h-5 text-gray-400"/>
                                </div>
                            @endif
                            <div>
                                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $group['supplier_name'] }}</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ $group['items_count'] }} {{ Str::plural('produs', $group['items_count']) }}
                                    @if($group['urgent_count'] > 0)
                                        &bull; <span class="text-danger-600 font-medium">{{ $group['urgent_count'] }} urgent{{ $group['urgent_count'] > 1 ? 'e' : '' }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div>
                            <button wire:click="createPoForSupplier({{ $group['supplier_id'] }})"
                                    class="inline-flex items-center gap-2 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg transition-colors">
                                <x-heroicon-o-shopping-bag class="w-4 h-4"/>
                                Crează PO
                            </button>
                        </div>
                    </div>

                    {{-- Tabel items --}}
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                                    <th class="px-4 py-2 text-left font-medium">Produs / SKU</th>
                                    <th class="px-4 py-2 text-right font-medium">Cant.</th>
                                    <th class="px-4 py-2 text-left font-medium">Necesar până la</th>
                                    <th class="px-4 py-2 text-center font-medium">Flags</th>
                                    <th class="px-4 py-2 text-left font-medium">Consultant</th>
                                    <th class="px-4 py-2 text-left font-medium">Justificație</th>
                                    <th class="px-4 py-2 text-left font-medium">Necesar</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 dark:divide-gray-700/50">
                                @foreach($group['items'] as $item)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30
                                               {{ $item['is_urgent'] ? 'bg-danger-50 dark:bg-danger-900/10' : '' }}">
                                        <td class="px-4 py-3">
                                            <p class="font-medium text-gray-900 dark:text-white">{{ $item['product_name'] }}</p>
                                            @if($item['sku'])
                                                <p class="text-xs text-gray-400 font-mono">{{ $item['sku'] }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-white">
                                            {{ number_format($item['quantity'], 0) }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                            {{ $item['needed_by'] ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center justify-center gap-1">
                                                @if($item['is_urgent'])
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400">
                                                        Urgent
                                                    </span>
                                                @endif
                                                @if($item['is_reserved'])
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400">
                                                        Rezervat
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                            <div>{{ $item['consultant'] ?? '—' }}</div>
                                            @if($item['location'])
                                                <div class="text-xs text-gray-400">{{ $item['location'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                            @if($item['is_reserved'] && $item['client_reference'])
                                                <span class="text-xs font-mono bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 rounded">
                                                    {{ $item['client_reference'] }}
                                                </span>
                                            @elseif($item['notes'])
                                                <span class="text-xs text-gray-500">{{ Str::limit($item['notes'], 50) }}</span>
                                            @else
                                                <span class="text-gray-300">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <a href="{{ route('filament.app.resources.purchase-requests.view', ['record' => $item['request_id']]) }}"
                                               class="text-xs text-primary-600 hover:text-primary-700 font-mono">
                                                {{ $item['request_number'] }}
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</x-filament-panels::page>
