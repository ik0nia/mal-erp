<div class="space-y-6">

    {{-- Filtre --}}
    <x-filament::section>
        <div class="flex flex-wrap gap-4 mb-3">
            <div class="flex-1 min-w-40">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Furnizor</label>
                <select wire:model.live="filterSupplierId"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">Toți furnizorii</option>
                    @foreach($supplierOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex-1 min-w-40">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Locație</label>
                <select wire:model.live="filterLocationId"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">Toate locațiile</option>
                    @foreach($locationOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            @if(!empty($consultantOptions))
            <div class="flex-1 min-w-40">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Consultant</label>
                <select wire:model.live="filterConsultantId"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">Toți consultanții</option>
                    @foreach($consultantOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <div class="flex-1 min-w-36">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Necesar de la</label>
                <input type="date" wire:model.live="filterNeededByFrom"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
            </div>

            <div class="flex-1 min-w-36">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Necesar până la</label>
                <input type="date" wire:model.live="filterNeededByTo"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-4">
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

            @if($filterSupplierId || $filterLocationId || $filterConsultantId || $filterNeededByFrom || $filterNeededByTo || $showUrgentOnly || $showReservedOnly)
                <button wire:click="resetFilters"
                        class="ml-auto text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 underline">
                    Resetează filtre
                </button>
            @endif
        </div>
    </x-filament::section>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <x-filament::section class="!p-0">
            <div class="p-4">
                <p class="text-sm text-gray-500 dark:text-gray-400">Total în așteptare</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ $totalPending }}</p>
            </div>
        </x-filament::section>
        <x-filament::section class="!p-0 border-danger-200 dark:border-danger-800">
            <div class="p-4">
                <p class="text-sm text-danger-600 dark:text-danger-400">Urgente</p>
                <p class="text-3xl font-bold text-danger-600 dark:text-danger-400 mt-1">{{ $totalUrgent }}</p>
            </div>
        </x-filament::section>
        <x-filament::section class="!p-0 border-warning-200 dark:border-warning-700">
            <div class="p-4">
                <p class="text-sm text-warning-600 dark:text-warning-400">Rezervate</p>
                <p class="text-3xl font-bold text-warning-600 dark:text-warning-400 mt-1">{{ $totalReserved }}</p>
            </div>
        </x-filament::section>
        <x-filament::section class="!p-0">
            <div class="p-4">
                <p class="text-sm text-gray-500 dark:text-gray-400">Furnizori afectați</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ $totalSuppliers }}</p>
            </div>
        </x-filament::section>
    </div>

    {{-- Grupuri furnizori --}}
    @if(empty($supplierGroups))
        <div class="text-center py-16 text-gray-400 dark:text-gray-500">
            <x-heroicon-o-check-circle class="w-12 h-12 mx-auto mb-3 text-success-400"/>
            <p class="text-lg font-medium">Niciun necesar în așteptare</p>
            <p class="text-sm mt-1">Toate necesarele trimise au fost procesate.</p>
        </div>
    @else
        <div class="space-y-6">
            @foreach($supplierGroups as $group)
                <x-filament::section class="{{ $group['urgent_count'] > 0 ? 'border-l-4 border-l-danger-500' : '' }} overflow-hidden !p-0">

                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                <x-heroicon-o-truck class="w-5 h-5 text-gray-400"/>
                            </div>
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
                                Creează PO — {{ $group['supplier_name'] }}
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                                    <th class="px-4 py-2 text-left font-medium">Produs / SKU</th>
                                    <th class="px-4 py-2 text-right font-medium">Cant.</th>
                                    <th class="px-4 py-2 text-left font-medium">Necesar până la</th>
                                    <th class="px-4 py-2 text-center font-medium">Flags</th>
                                    <th class="px-4 py-2 text-left font-medium">Consultant / Locație</th>
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
                                            @if($item['is_discontinued'] ?? false)
                                                <span class="inline-flex items-center gap-1 text-xs font-medium text-red-600 dark:text-red-400 mt-1">
                                                    <x-heroicon-o-archive-box-x-mark class="w-3 h-3"/>
                                                    Fără reaprovizionare — nu mai comanda
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <span class="font-medium text-gray-900 dark:text-white">
                                                {{ number_format($item['quantity'], 0) }}
                                            </span>
                                            @if(($item['ordered_quantity'] ?? 0) > 0)
                                                <div class="text-xs text-warning-600 dark:text-warning-400 mt-0.5">
                                                    +{{ number_format($item['ordered_quantity'], 0) }} cmd.
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                            @if($item['needed_by'])
                                                <span class="{{ \Carbon\Carbon::createFromFormat('d.m.Y', $item['needed_by'])->isPast() ? 'text-danger-600 font-medium' : '' }}">
                                                    {{ $item['needed_by'] }}
                                                </span>
                                            @else
                                                —
                                            @endif
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
                </x-filament::section>
            @endforeach
        </div>
    @endif

    {{-- Comenzi WooCommerce cu produse "La comandă" --}}
    @php $wooOrders = $this->getWooOrdersPendingProcurement(); @endphp
    @if($wooOrders->isNotEmpty())
        <div class="mt-2">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3 flex items-center gap-2">
                <x-heroicon-o-shopping-cart class="w-5 h-5 text-orange-500"/>
                Comenzi online — produse la comandă
                <span class="ml-2 px-2 py-0.5 rounded-full text-xs font-bold bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300">
                    {{ $wooOrders->count() }}
                </span>
            </h2>
            <x-filament::section class="!p-0 border-orange-200 dark:border-orange-800 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-orange-50 dark:bg-orange-900/30">
                        <tr>
                            <th class="text-left px-4 py-2 text-orange-700 dark:text-orange-300 font-medium">Comandă WooCommerce</th>
                            <th class="text-left px-4 py-2 text-orange-700 dark:text-orange-300 font-medium">Client</th>
                            <th class="text-left px-4 py-2 text-orange-700 dark:text-orange-300 font-medium">Produse la comandă</th>
                            <th class="text-left px-4 py-2 text-orange-700 dark:text-orange-300 font-medium">PNR</th>
                            <th class="text-left px-4 py-2 text-orange-700 dark:text-orange-300 font-medium">Status PNR</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-orange-100 dark:divide-orange-900">
                        @foreach($wooOrders as $pnr)
                            <tr class="hover:bg-orange-50 dark:hover:bg-orange-900/20">
                                <td class="px-4 py-3">
                                    @if($pnr->wooOrder)
                                        <a href="{{ route('filament.app.resources.woo-orders.view', ['record' => $pnr->wooOrder->id]) }}"
                                           class="font-mono text-primary-600 hover:underline">
                                            #{{ $pnr->wooOrder->number }}
                                        </a>
                                        <div class="text-xs text-gray-400">{{ $pnr->wooOrder->order_date?->format('d.m.Y') }}</div>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                    @if($pnr->wooOrder)
                                        {{ ($pnr->wooOrder->billing['first_name'] ?? '') . ' ' . ($pnr->wooOrder->billing['last_name'] ?? '') }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @foreach($pnr->items as $item)
                                        <div class="text-xs text-gray-700 dark:text-gray-300">
                                            {{ $item->product_name }}
                                            <span class="text-orange-600 font-semibold">× {{ $item->quantity }}</span>
                                        </div>
                                    @endforeach
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('filament.app.resources.purchase-requests.view', ['record' => $pnr->id]) }}"
                                       class="font-mono text-xs text-primary-600 hover:underline">
                                        {{ $pnr->number }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                        {{ $pnr->status === 'submitted' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' }}">
                                        {{ \App\Models\PurchaseRequest::statusOptions()[$pnr->status] ?? $pnr->status }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        </div>
    @endif

</div>
