<x-filament-panels::page>

    {{-- Filtre --}}
    <div style="margin-bottom: 1.5rem; border-radius: 0.75rem; border: 1px solid #e5e7eb; background: #fff; padding: 1rem;">
        <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 0.75rem;">
            {{-- Furnizor --}}
            <div style="flex: 1 1 0%; min-width: 10rem;">
                <label style="display: block; font-size: 0.75rem; font-weight: 500; color: #6b7280; margin-bottom: 0.25rem;">Furnizor</label>
                <select wire:model.live="filterSupplierId"
                        style="width: 100%; border-radius: 0.5rem; border: 1px solid #d1d5db; font-size: 0.875rem; padding: 0.375rem 0.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    <option value="">Toți furnizorii</option>
                    @foreach($supplierOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Locație --}}
            <div style="flex: 1 1 0%; min-width: 10rem;">
                <label style="display: block; font-size: 0.75rem; font-weight: 500; color: #6b7280; margin-bottom: 0.25rem;">Locație</label>
                <select wire:model.live="filterLocationId"
                        style="width: 100%; border-radius: 0.5rem; border: 1px solid #d1d5db; font-size: 0.875rem; padding: 0.375rem 0.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    <option value="">Toate locațiile</option>
                    @foreach($locationOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Consultant --}}
            @if(!empty($consultantOptions))
            <div style="flex: 1 1 0%; min-width: 10rem;">
                <label style="display: block; font-size: 0.75rem; font-weight: 500; color: #6b7280; margin-bottom: 0.25rem;">Consultant</label>
                <select wire:model.live="filterConsultantId"
                        style="width: 100%; border-radius: 0.5rem; border: 1px solid #d1d5db; font-size: 0.875rem; padding: 0.375rem 0.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    <option value="">Toți consultanții</option>
                    @foreach($consultantOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            {{-- Data de la --}}
            <div style="flex: 1 1 0%; min-width: 9rem;">
                <label style="display: block; font-size: 0.75rem; font-weight: 500; color: #6b7280; margin-bottom: 0.25rem;">Necesar de la</label>
                <input type="date" wire:model.live="filterNeededByFrom"
                       style="width: 100%; border-radius: 0.5rem; border: 1px solid #d1d5db; font-size: 0.875rem; padding: 0.375rem 0.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
            </div>

            {{-- Data până la --}}
            <div style="flex: 1 1 0%; min-width: 9rem;">
                <label style="display: block; font-size: 0.75rem; font-weight: 500; color: #6b7280; margin-bottom: 0.25rem;">Necesar până la</label>
                <input type="date" wire:model.live="filterNeededByTo"
                       style="width: 100%; border-radius: 0.5rem; border: 1px solid #d1d5db; font-size: 0.875rem; padding: 0.375rem 0.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
            </div>
        </div>

        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 1rem;">
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" wire:model.live="showUrgentOnly"
                       style="border-radius: 0.25rem; border: 1px solid #d1d5db;">
                <span style="font-size: 0.875rem; font-weight: 500; color: #374151;">Doar urgente</span>
            </label>

            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" wire:model.live="showReservedOnly"
                       style="border-radius: 0.25rem; border: 1px solid #d1d5db;">
                <span style="font-size: 0.875rem; font-weight: 500; color: #374151;">Doar rezervate</span>
            </label>

            @if($filterSupplierId || $filterLocationId || $filterConsultantId || $filterNeededByFrom || $filterNeededByTo || $showUrgentOnly || $showReservedOnly)
                <button wire:click="resetFilters"
                        style="margin-left: auto; font-size: 0.75rem; color: #6b7280; text-decoration: underline; background: none; border: none; cursor: pointer;">
                    Resetează filtre
                </button>
            @endif
        </div>
    </div>

    {{-- Stat cards --}}
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
        <div style="border-radius: 0.75rem; border: 1px solid #e5e7eb; background: #fff; padding: 1rem;">
            <p style="font-size: 0.875rem; color: #6b7280;">Total în așteptare</p>
            <p style="font-size: 1.875rem; font-weight: 700; color: #111827; margin-top: 0.25rem;">{{ $totalPending }}</p>
        </div>
        <div style="border-radius: 0.75rem; border: 1px solid #fecaca; background: #fff; padding: 1rem;">
            <p style="font-size: 0.875rem; color: #dc2626;">Urgente</p>
            <p style="font-size: 1.875rem; font-weight: 700; color: #dc2626; margin-top: 0.25rem;">{{ $totalUrgent }}</p>
        </div>
        <div style="border-radius: 0.75rem; border: 1px solid #fde68a; background: #fff; padding: 1rem;">
            <p style="font-size: 0.875rem; color: #d97706;">Rezervate</p>
            <p style="font-size: 1.875rem; font-weight: 700; color: #d97706; margin-top: 0.25rem;">{{ $totalReserved }}</p>
        </div>
        <div style="border-radius: 0.75rem; border: 1px solid #e5e7eb; background: #fff; padding: 1rem;">
            <p style="font-size: 0.875rem; color: #6b7280;">Furnizori afectați</p>
            <p style="font-size: 1.875rem; font-weight: 700; color: #111827; margin-top: 0.25rem;">{{ $totalSuppliers }}</p>
        </div>
    </div>

    @if(empty($supplierGroups))
        <div style="text-align: center; padding: 4rem 0; color: #9ca3af;">
            <x-filament::icon icon="heroicon-o-check-circle" style="width: 3rem; height: 3rem; margin: 0 auto 0.75rem; color: #4ade80;"/>
            <p style="font-size: 1.125rem; font-weight: 500;">Niciun necesar în așteptare</p>
            <p style="font-size: 0.875rem; margin-top: 0.25rem;">Toate necesarele trimise au fost procesate.</p>
        </div>
    @else
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            @foreach($supplierGroups as $group)
                <div style="border-radius: 0.75rem; border: 1px solid #e5e7eb; background: #fff; overflow: hidden; {{ $group['urgent_count'] > 0 ? 'border-left: 4px solid #ef4444;' : '' }}">

                    {{-- Header furnizor --}}
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.5rem; border-bottom: 1px solid #f3f4f6; background: #f9fafb;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="width: 2.5rem; height: 2.5rem; border-radius: 0.375rem; background: #f3f4f6; display: flex; align-items: center; justify-content: center;">
                                <x-filament::icon icon="heroicon-o-truck" style="width: 1.25rem; height: 1.25rem; color: #9ca3af;"/>
                            </div>
                            <div>
                                <h3 style="font-weight: 600; color: #111827;">{{ $group['supplier_name'] }}</h3>
                                <p style="font-size: 0.875rem; color: #6b7280;">
                                    {{ $group['items_count'] }} {{ Str::plural('produs', $group['items_count']) }}
                                    @if($group['urgent_count'] > 0)
                                        &bull; <span style="color: #dc2626; font-weight: 500;">{{ $group['urgent_count'] }} urgent{{ $group['urgent_count'] > 1 ? 'e' : '' }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div>
                            <a href="{{ $group['create_po_url'] }}"
                               style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; background: #8B1A1A; color: #fff; font-size: 0.875rem; font-weight: 500; border-radius: 0.5rem; text-decoration: none;">
                                <x-filament::icon icon="heroicon-o-shopping-bag" style="width: 1rem; height: 1rem;"/>
                                Crează PO
                            </a>
                        </div>
                    </div>

                    {{-- Tabel items --}}
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
                            <thead>
                                <tr style="font-size: 0.75rem; color: #6b7280; border-bottom: 1px solid #f3f4f6;">
                                    <th style="padding: 0.5rem 1rem; text-align: left; font-weight: 500;">Produs / SKU</th>
                                    <th style="padding: 0.5rem 1rem; text-align: right; font-weight: 500;">Cant.</th>
                                    <th style="padding: 0.5rem 1rem; text-align: left; font-weight: 500;">Necesar până la</th>
                                    <th style="padding: 0.5rem 1rem; text-align: center; font-weight: 500;">Flags</th>
                                    <th style="padding: 0.5rem 1rem; text-align: left; font-weight: 500;">Consultant / Locație</th>
                                    <th style="padding: 0.5rem 1rem; text-align: left; font-weight: 500;">Justificație</th>
                                    <th style="padding: 0.5rem 1rem; text-align: left; font-weight: 500;">Necesar</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($group['items'] as $item)
                                    <tr style="border-bottom: 1px solid #f9fafb; {{ $item['is_urgent'] ? 'background: #fef2f2;' : '' }}">
                                        <td style="padding: 0.75rem 1rem;">
                                            <p style="font-weight: 500; color: #111827;">{{ $item['product_name'] }}</p>
                                            @if($item['sku'])
                                                <p style="font-size: 0.75rem; color: #9ca3af; font-family: monospace;">{{ $item['sku'] }}</p>
                                            @endif
                                            @if($item['is_discontinued'] ?? false)
                                                <span style="display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; font-weight: 500; color: #dc2626; margin-top: 0.25rem;">
                                                    <x-filament::icon icon="heroicon-o-archive-box-x-mark" style="width: 0.75rem; height: 0.75rem;"/>
                                                    Fără reaprovizionare — nu mai comanda
                                                </span>
                                            @endif
                                        </td>
                                        <td style="padding: 0.75rem 1rem; text-align: right;">
                                            <span style="font-weight: 500; color: #111827;">
                                                {{ number_format($item['quantity'], 0, '.', '') }}
                                            </span>
                                            @if(($item['ordered_quantity'] ?? 0) > 0)
                                                <div style="font-size: 0.75rem; color: #d97706; margin-top: 0.125rem;">
                                                    +{{ number_format($item['ordered_quantity'], 0, '.', '') }} cmd.
                                                </div>
                                            @endif
                                        </td>
                                        <td style="padding: 0.75rem 1rem; color: #4b5563;">
                                            @if($item['needed_by'])
                                                <span style="{{ \Carbon\Carbon::createFromFormat('d.m.Y', $item['needed_by'])->isPast() ? 'color: #dc2626; font-weight: 500;' : '' }}">
                                                    {{ $item['needed_by'] }}
                                                </span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td style="padding: 0.75rem 1rem;">
                                            <div style="display: flex; align-items: center; justify-content: center; gap: 0.25rem;">
                                                @if($item['is_urgent'])
                                                    <span style="display: inline-flex; align-items: center; padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 500; background: #fee2e2; color: #b91c1c;">
                                                        Urgent
                                                    </span>
                                                @endif
                                                @if($item['is_reserved'])
                                                    <span style="display: inline-flex; align-items: center; padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 500; background: #fef3c7; color: #b45309;">
                                                        Rezervat
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                        <td style="padding: 0.75rem 1rem; color: #4b5563;">
                                            <div>{{ $item['consultant'] ?? '—' }}</div>
                                            @if($item['location'])
                                                <div style="font-size: 0.75rem; color: #9ca3af;">{{ $item['location'] }}</div>
                                            @endif
                                        </td>
                                        <td style="padding: 0.75rem 1rem; color: #4b5563;">
                                            @if($item['is_reserved'] && $item['client_reference'])
                                                <span style="font-size: 0.75rem; font-family: monospace; background: #f3f4f6; padding: 0.125rem 0.375rem; border-radius: 0.25rem;">
                                                    {{ $item['client_reference'] }}
                                                </span>
                                            @elseif($item['notes'])
                                                <span style="font-size: 0.75rem; color: #6b7280;">{{ Str::limit($item['notes'], 50) }}</span>
                                            @else
                                                <span style="color: #d1d5db;">—</span>
                                            @endif
                                        </td>
                                        <td style="padding: 0.75rem 1rem;">
                                            <a href="{{ route('filament.app.resources.purchase-requests.view', ['record' => $item['request_id']]) }}"
                                               style="font-size: 0.75rem; color: #8B1A1A; font-family: monospace;">
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

    {{-- Comenzi WooCommerce cu produse "La comandă" --}}
    @php $wooOrders = $this->getWooOrdersPendingProcurement(); @endphp
    @if($wooOrders->isNotEmpty())
        <div style="margin-top: 2rem;">
            <h2 style="font-size: 1.125rem; font-weight: 600; color: #1f2937; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                <x-filament::icon icon="heroicon-o-shopping-cart" style="width: 1.25rem; height: 1.25rem; color: #d97706;"/>
                Comenzi online — produse la comandă
                <span style="margin-left: 0.5rem; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; background: #fef3c7; color: #b45309;">
                    {{ $wooOrders->count() }}
                </span>
            </h2>
            <div style="border-radius: 0.75rem; border: 1px solid #fde68a; background: #fff; overflow: hidden;">
                <table style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
                    <thead style="background: #fffbeb;">
                        <tr>
                            <th style="text-align: left; padding: 0.5rem 1rem; color: #b45309; font-weight: 500;">Comandă WooCommerce</th>
                            <th style="text-align: left; padding: 0.5rem 1rem; color: #b45309; font-weight: 500;">Client</th>
                            <th style="text-align: left; padding: 0.5rem 1rem; color: #b45309; font-weight: 500;">Produse la comandă</th>
                            <th style="text-align: left; padding: 0.5rem 1rem; color: #b45309; font-weight: 500;">PNR</th>
                            <th style="text-align: left; padding: 0.5rem 1rem; color: #b45309; font-weight: 500;">Status PNR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($wooOrders as $pnr)
                            <tr style="border-bottom: 1px solid #fef3c7;">
                                <td style="padding: 0.75rem 1rem;">
                                    @if($pnr->wooOrder)
                                        <a href="{{ route('filament.app.resources.woo-orders.view', ['record' => $pnr->wooOrder->id]) }}"
                                           style="font-family: monospace; color: #8B1A1A; text-decoration: none;">
                                            #{{ $pnr->wooOrder->number }}
                                        </a>
                                        <div style="font-size: 0.75rem; color: #9ca3af;">{{ $pnr->wooOrder->order_date?->format('d.m.Y') }}</div>
                                    @else
                                        <span style="color: #9ca3af;">—</span>
                                    @endif
                                </td>
                                <td style="padding: 0.75rem 1rem; color: #374151;">
                                    @if($pnr->wooOrder)
                                        {{ ($pnr->wooOrder->billing['first_name'] ?? '') . ' ' . ($pnr->wooOrder->billing['last_name'] ?? '') }}
                                    @endif
                                </td>
                                <td style="padding: 0.75rem 1rem;">
                                    @foreach($pnr->items as $item)
                                        <div style="font-size: 0.75rem; color: #374151;">
                                            {{ $item->product_name }}
                                            <span style="color: #d97706; font-weight: 600;">× {{ $item->quantity }}</span>
                                        </div>
                                    @endforeach
                                </td>
                                <td style="padding: 0.75rem 1rem;">
                                    <a href="{{ route('filament.app.resources.purchase-requests.view', ['record' => $pnr->id]) }}"
                                       style="font-family: monospace; font-size: 0.75rem; color: #8B1A1A; text-decoration: none;">
                                        {{ $pnr->number }}
                                    </a>
                                </td>
                                <td style="padding: 0.75rem 1rem;">
                                    <span style="padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500;
                                        {{ $pnr->status === 'submitted' ? 'background: #fef3c7; color: #b45309;' : 'background: #dbeafe; color: #1d4ed8;' }}">
                                        {{ \App\Models\PurchaseRequest::statusOptions()[$pnr->status] ?? $pnr->status }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</x-filament-panels::page>
