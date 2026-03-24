<x-filament-panels::page>

    @if(empty($items))
        <div class="text-center py-16 text-gray-400 dark:text-gray-500">
            <x-heroicon-o-check-circle class="w-12 h-12 mx-auto mb-3 text-success-400"/>
            <p class="text-lg font-medium">Toate produsele au furnizor alocat</p>
            <p class="text-sm mt-1">Nu există necesare în așteptare fără furnizor.</p>
        </div>
    @else
        {{-- Header cu statistici + buton Salvează toate --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-warning-100 text-warning-800 dark:bg-warning-900/30 dark:text-warning-300">
                    {{ count($items) }} {{ count($items) === 1 ? 'produs fără furnizor' : 'produse fără furnizor' }}
                </span>
                @php $urgentCount = collect($items)->where('is_urgent', true)->count(); @endphp
                @if($urgentCount > 0)
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-danger-100 text-danger-800 dark:bg-danger-900/30 dark:text-danger-300">
                        {{ $urgentCount }} urgent{{ $urgentCount > 1 ? 'e' : '' }}
                    </span>
                @endif
            </div>
            <button wire:click="saveAll"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg transition-colors">
                <x-heroicon-o-check class="w-4 h-4"/>
                Alocă toate selecțiile
            </button>
        </div>

        {{-- Tabel items --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                            <th class="px-4 py-3 text-left font-medium">Produs / SKU</th>
                            <th class="px-4 py-3 text-right font-medium">Cant.</th>
                            <th class="px-4 py-3 text-left font-medium">Necesar până la</th>
                            <th class="px-4 py-3 text-left font-medium">Consultant / Locație</th>
                            <th class="px-4 py-3 text-left font-medium">Justificație</th>
                            <th class="px-4 py-3 text-left font-medium">Necesar</th>
                            <th class="px-4 py-3 text-left font-medium" style="min-width: 280px">Alocare furnizor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-700/50">
                        @foreach($items as $item)
                            <tr wire:key="item-{{ $item['id'] }}"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700/30
                                       {{ $item['is_urgent'] ? 'bg-danger-50/60 dark:bg-danger-900/10' : '' }}">

                                {{-- Produs --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        @if($item['is_urgent'])
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-bold bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400 shrink-0">URG</span>
                                        @endif
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-white">{{ $item['product_name'] }}</p>
                                            @if($item['sku'])
                                                <p class="text-xs text-gray-400 font-mono">{{ $item['sku'] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                {{-- Cantitate --}}
                                <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-white">
                                    {{ number_format($item['quantity'], 0) }}
                                </td>

                                {{-- Necesar până la --}}
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                    @if($item['needed_by'])
                                        @php
                                            $isPast = \Carbon\Carbon::createFromFormat('d.m.Y', $item['needed_by'])->isPast();
                                        @endphp
                                        <span class="{{ $isPast ? 'text-danger-600 dark:text-danger-400 font-medium' : '' }}">
                                            {{ $item['needed_by'] }}
                                            @if($isPast)
                                                <span class="text-xs ml-1">(expirat)</span>
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>

                                {{-- Consultant / Locație --}}
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                    <div>{{ $item['consultant'] ?? '—' }}</div>
                                    @if($item['location'])
                                        <div class="text-xs text-gray-400">{{ $item['location'] }}</div>
                                    @endif
                                </td>

                                {{-- Justificație --}}
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                    @if($item['is_reserved'] && $item['client_reference'])
                                        <span class="inline-flex items-center gap-1 text-xs">
                                            <x-heroicon-s-bookmark class="w-3 h-3 text-warning-500"/>
                                            <span class="font-mono bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 rounded">{{ $item['client_reference'] }}</span>
                                        </span>
                                    @elseif($item['notes'])
                                        <span class="text-xs">{{ Str::limit($item['notes'], 40) }}</span>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>

                                {{-- Link necesar --}}
                                <td class="px-4 py-3">
                                    <a href="{{ route('filament.app.resources.purchase-requests.view', ['record' => $item['request_id']]) }}"
                                       class="text-xs text-primary-600 hover:text-primary-700 font-mono">
                                        {{ $item['request_number'] }}
                                    </a>
                                </td>

                                {{-- Alocare furnizor --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1">
                                            <select wire:model="selectedSuppliers.{{ $item['id'] }}"
                                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                                <option value="">— Selectează furnizor —</option>

                                                @if(!empty($item['suggested']))
                                                    <optgroup label="✓ Furnizori cunoscuți pentru acest produs">
                                                        @foreach($item['suggested'] as $s)
                                                            <option value="{{ $s['id'] }}">
                                                                {{ $s['is_preferred'] ? '★ ' : '' }}{{ $s['name'] }}
                                                                @if($s['price'])
                                                                    ({{ number_format($s['price'], 2, ',', '.') }} RON)
                                                                @endif
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                    <optgroup label="Alți furnizori">
                                                        @foreach($allSuppliers as $id => $name)
                                                            @if(!in_array($id, $item['suggested_ids']))
                                                                <option value="{{ $id }}">{{ $name }}</option>
                                                            @endif
                                                        @endforeach
                                                    </optgroup>
                                                @else
                                                    {{-- Produs fără furnizori cunoscuți —  toți furnizorii --}}
                                                    @foreach($allSuppliers as $id => $name)
                                                        <option value="{{ $id }}">{{ $name }}</option>
                                                    @endforeach
                                                @endif
                                            </select>

                                            @if(empty($item['suggested']))
                                                <p class="text-xs text-gray-400 mt-1">
                                                    <x-heroicon-o-exclamation-circle class="w-3 h-3 inline"/> Produs fără furnizori configurați
                                                </p>
                                            @endif
                                        </div>

                                        <button wire:click="saveAssignment({{ $item['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="saveAssignment({{ $item['id'] }})"
                                                class="shrink-0 inline-flex items-center gap-1 px-3 py-2 bg-success-600 hover:bg-success-700 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors">
                                            <x-heroicon-o-check class="w-3.5 h-3.5"/>
                                            <span wire:loading.remove wire:target="saveAssignment({{ $item['id'] }})">Alocă</span>
                                            <span wire:loading wire:target="saveAssignment({{ $item['id'] }})">...</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Notă informatională --}}
        <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
            <p class="text-xs text-blue-700 dark:text-blue-300">
                <x-heroicon-o-information-circle class="w-4 h-4 inline mr-1"/>
                După alocare, produsele apar automat în pagina <strong>Generează comandă</strong> a buyer-ului responsabil pentru furnizorul selectat.
                Furnizorii marcați cu <strong>★</strong> sunt cei preferați pentru produsul respectiv.
            </p>
        </div>
    @endif

</x-filament-panels::page>
