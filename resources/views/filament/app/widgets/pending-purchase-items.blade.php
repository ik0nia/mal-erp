<style>
.ppi-filters { display:flex; flex-wrap:wrap; gap:1rem; margin-bottom:0.75rem; }
.ppi-filter  { flex:1 1 10rem; min-width:0; }
.ppi-filter label { display:block; font-size:0.75rem; font-weight:500; color:#6b7280; margin-bottom:0.25rem; }
.ppi-filter select, .ppi-filter input { width:100%; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.4rem 0.5rem; font-size:0.875rem; background:#fff; }
.ppi-checks { display:flex; flex-wrap:wrap; align-items:center; gap:1rem; }
.ppi-checks label { display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.875rem; font-weight:500; color:#374151; }
.ppi-stat-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1rem; }
@@media(min-width:640px){ .ppi-stat-grid { grid-template-columns:repeat(4, minmax(0, 1fr)); } }
.ppi-stat { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; padding:1rem; }
.ppi-stat-label { font-size:0.75rem; font-weight:500; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; }
.ppi-stat-value { margin-top:0.25rem; font-size:1.875rem; font-weight:700; color:#111827; }
.ppi-stat--danger { border-color:#fecaca; }
.ppi-stat--danger .ppi-stat-label, .ppi-stat--danger .ppi-stat-value { color:#dc2626; }
.ppi-stat--warning { border-color:#fde68a; }
.ppi-stat--warning .ppi-stat-label, .ppi-stat--warning .ppi-stat-value { color:#d97706; }
.ppi-supplier { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; overflow:hidden; margin-bottom:1.5rem; }
.ppi-supplier--urgent { border-left:4px solid #ef4444; }
.ppi-supplier-header { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.5rem; border-bottom:1px solid #f3f4f6; background:#f9fafb; }
.ppi-supplier-info { display:flex; align-items:center; gap:0.75rem; }
.ppi-supplier-icon { width:2.5rem; height:2.5rem; border-radius:0.375rem; background:#f3f4f6; display:flex; align-items:center; justify-content:center; }
.ppi-supplier-name { font-weight:600; color:#111827; }
.ppi-supplier-meta { font-size:0.875rem; color:#6b7280; }
.ppi-btn-po { display:inline-flex; align-items:center; gap:0.5rem; padding:0.5rem 1rem; background:#dc2626; color:#fff; font-size:0.875rem; font-weight:500; border-radius:0.5rem; border:none; cursor:pointer; }
.ppi-btn-po:hover { background:#b91c1c; }
.ppi-table { width:100%; font-size:0.875rem; border-collapse:collapse; }
.ppi-table th { padding:0.5rem 1rem; text-align:left; font-size:0.75rem; font-weight:500; color:#6b7280; text-transform:uppercase; border-bottom:1px solid #f3f4f6; }
.ppi-table td { padding:0.75rem 1rem; border-bottom:1px solid #f9fafb; vertical-align:top; }
.ppi-table tr:hover { background:#f9fafb; }
.ppi-table .text-right { text-align:right; }
.ppi-table .text-center { text-align:center; }
.ppi-urgent-row { background:#fef2f2; }
.ppi-badge { display:inline-flex; align-items:center; padding:0.125rem 0.375rem; border-radius:0.25rem; font-size:0.75rem; font-weight:500; }
.ppi-badge--danger { background:#fee2e2; color:#b91c1c; }
.ppi-badge--warning { background:#fef3c7; color:#92400e; }
.ppi-product-name { font-weight:500; color:#111827; }
.ppi-sku { font-size:0.75rem; color:#9ca3af; font-family:monospace; }
.ppi-discontinued { display:inline-flex; align-items:center; gap:0.25rem; font-size:0.75rem; font-weight:500; color:#dc2626; margin-top:0.25rem; }
.ppi-link { font-size:0.75rem; color:#dc2626; font-family:monospace; text-decoration:none; }
.ppi-link:hover { text-decoration:underline; }
.ppi-woo-section { margin-top:1.5rem; }
.ppi-woo-header { font-size:1.125rem; font-weight:600; color:#1f2937; margin-bottom:0.75rem; display:flex; align-items:center; gap:0.5rem; }
.ppi-woo-count { margin-left:0.5rem; padding:0.125rem 0.5rem; border-radius:9999px; font-size:0.75rem; font-weight:700; background:#ffedd5; color:#c2410c; }
.ppi-woo-table { border-radius:0.75rem; border:1px solid #fed7aa; background:#fff; overflow:hidden; }
.ppi-woo-table th { background:#fff7ed; color:#c2410c; padding:0.5rem 1rem; text-align:left; font-weight:500; font-size:0.875rem; }
.ppi-woo-table td { padding:0.75rem 1rem; border-bottom:1px solid #ffedd5; font-size:0.875rem; }
.ppi-status { padding:0.125rem 0.5rem; border-radius:9999px; font-size:0.75rem; font-weight:500; }
.ppi-status--submitted { background:#fef3c7; color:#92400e; }
.ppi-status--other { background:#dbeafe; color:#1e40af; }
.ppi-empty { text-align:center; padding:4rem 0; color:#9ca3af; }
.ppi-empty p { font-size:1.125rem; font-weight:500; }
.ppi-empty .sub { font-size:0.875rem; margin-top:0.25rem; }
.ppi-reset { margin-left:auto; font-size:0.75rem; color:#6b7280; text-decoration:underline; background:none; border:none; cursor:pointer; }
</style>

<x-filament-widgets::widget>
<div style="display:flex; flex-direction:column; gap:1.5rem;">

    {{-- Titlu --}}
    <div style="display:flex; align-items:center; gap:0.5rem;">
        <x-filament::icon icon="heroicon-o-clipboard-document-list" style="width:1.5rem; height:1.5rem; color:#dc2626;" />
        <h2 style="font-size:1.25rem; font-weight:700; color:#111827; margin:0;">Necesare de comandat</h2>
        @if($totalPending > 0)
            <span style="background:#fee2e2; color:#dc2626; font-size:0.75rem; font-weight:700; padding:0.125rem 0.5rem; border-radius:9999px;">{{ $totalPending }}</span>
        @endif
    </div>

    {{-- Stat cards --}}
    <div class="ppi-stat-grid">
        <div class="ppi-stat">
            <p class="ppi-stat-label">Total în așteptare</p>
            <p class="ppi-stat-value">{{ $totalPending }}</p>
        </div>
        <div class="ppi-stat ppi-stat--danger">
            <p class="ppi-stat-label">Urgente</p>
            <p class="ppi-stat-value">{{ $totalUrgent }}</p>
        </div>
        <div class="ppi-stat ppi-stat--warning">
            <p class="ppi-stat-label">Rezervate</p>
            <p class="ppi-stat-value">{{ $totalReserved }}</p>
        </div>
        <div class="ppi-stat">
            <p class="ppi-stat-label">Furnizori afectați</p>
            <p class="ppi-stat-value">{{ $totalSuppliers }}</p>
        </div>
    </div>

    {{-- Grupuri furnizori --}}
    @if(empty($supplierGroups))
        <div class="ppi-empty">
            <x-filament::icon icon="heroicon-o-check-circle" style="width:3rem; height:3rem; margin:0 auto 0.75rem; color:#34d399;"/>
            <p>Niciun necesar în așteptare</p>
            <p class="sub">Toate necesarele trimise au fost procesate.</p>
        </div>
    @else
        @foreach($supplierGroups as $group)
            <div class="ppi-supplier {{ $group['urgent_count'] > 0 ? 'ppi-supplier--urgent' : '' }}">
                <div class="ppi-supplier-header">
                    <div class="ppi-supplier-info">
                        <div class="ppi-supplier-icon">
                            <x-filament::icon icon="heroicon-o-truck" style="width:1.25rem; height:1.25rem; color:#9ca3af;"/>
                        </div>
                        <div>
                            <div class="ppi-supplier-name">{{ $group['supplier_name'] }}</div>
                            <div class="ppi-supplier-meta">
                                {{ $group['items_count'] }} {{ Str::plural('produs', $group['items_count']) }}
                                @if($group['urgent_count'] > 0)
                                    &bull; <span style="color:#dc2626; font-weight:500;">{{ $group['urgent_count'] }} urgent{{ $group['urgent_count'] > 1 ? 'e' : '' }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <a href="{{ $group['create_po_url'] }}" class="ppi-btn-po" style="text-decoration:none;">
                        <x-filament::icon icon="heroicon-o-shopping-bag" style="width:1rem; height:1rem;"/>
                        Creează PO — {{ $group['supplier_name'] }}
                    </a>
                </div>

                <div style="overflow-x:auto;">
                    <table class="ppi-table">
                        <thead>
                            <tr>
                                <th>Produs / SKU</th>
                                <th class="text-right">Cant.</th>
                                <th>Necesar până la</th>
                                <th class="text-center">Flags</th>
                                <th>Consultant / Locație</th>
                                <th>Justificație</th>
                                <th>Necesar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($group['items'] as $item)
                                <tr class="{{ $item['is_urgent'] ? 'ppi-urgent-row' : '' }}">
                                    <td>
                                        <div class="ppi-product-name">{{ $item['product_name'] }}</div>
                                        @if($item['sku'])
                                            <div class="ppi-sku">{{ $item['sku'] }}</div>
                                        @endif
                                        @if($item['is_discontinued'] ?? false)
                                            <span class="ppi-discontinued">
                                                <x-filament::icon icon="heroicon-o-archive-box-x-mark" style="width:0.75rem; height:0.75rem;"/>
                                                Fără reaprovizionare — nu mai comanda
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <span style="font-weight:500; color:#111827;">{{ number_format($item['quantity'], 0) }}</span>
                                        @if(($item['ordered_quantity'] ?? 0) > 0)
                                            <div style="font-size:0.75rem; color:#d97706; margin-top:0.125rem;">+{{ number_format($item['ordered_quantity'], 0) }} cmd.</div>
                                        @endif
                                    </td>
                                    <td style="color:#4b5563;">
                                        @if($item['needed_by'])
                                            <span style="{{ \Carbon\Carbon::createFromFormat('d.m.Y', $item['needed_by'])->isPast() ? 'color:#dc2626; font-weight:500;' : '' }}">
                                                {{ $item['needed_by'] }}
                                            </span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div style="display:flex; align-items:center; justify-content:center; gap:0.25rem;">
                                            @if($item['is_urgent'])
                                                <span class="ppi-badge ppi-badge--danger">Urgent</span>
                                            @endif
                                            @if($item['is_reserved'])
                                                <span class="ppi-badge ppi-badge--warning">Rezervat</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td style="color:#4b5563;">
                                        <div>{{ $item['consultant'] ?? '—' }}</div>
                                        @if($item['location'])
                                            <div style="font-size:0.75rem; color:#9ca3af;">{{ $item['location'] }}</div>
                                        @endif
                                    </td>
                                    <td style="color:#4b5563;">
                                        @if($item['is_reserved'] && $item['client_reference'])
                                            <span style="font-size:0.75rem; font-family:monospace; background:#f3f4f6; padding:0.125rem 0.375rem; border-radius:0.25rem;">
                                                {{ $item['client_reference'] }}
                                            </span>
                                        @elseif($item['notes'])
                                            <span style="font-size:0.75rem; color:#6b7280;">{{ Str::limit($item['notes'], 50) }}</span>
                                        @else
                                            <span style="color:#d1d5db;">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('filament.app.resources.purchase-requests.view', ['record' => $item['request_id']]) }}" class="ppi-link">
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
    @endif

    {{-- Comenzi WooCommerce cu produse "La comandă" --}}
    @php $wooOrders = $this->getWooOrdersPendingProcurement(); @endphp
    @if($wooOrders->isNotEmpty())
        <div class="ppi-woo-section">
            <div class="ppi-woo-header">
                <x-filament::icon icon="heroicon-o-shopping-cart" style="width:1.25rem; height:1.25rem; color:#d97706;"/>
                Comenzi online — produse la comandă
                <span class="ppi-woo-count">{{ $wooOrders->count() }}</span>
            </div>
            <div class="ppi-woo-table">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th class="ppi-woo-table th">Comandă WooCommerce</th>
                            <th class="ppi-woo-table th">Client</th>
                            <th class="ppi-woo-table th">Produse la comandă</th>
                            <th class="ppi-woo-table th">PNR</th>
                            <th class="ppi-woo-table th">Status PNR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($wooOrders as $pnr)
                            <tr style="border-bottom:1px solid #ffedd5;">
                                <td class="ppi-woo-table td">
                                    @if($pnr->wooOrder)
                                        <a href="{{ route('filament.app.resources.woo-orders.view', ['record' => $pnr->wooOrder->id]) }}" class="ppi-link" style="font-family:monospace;">
                                            #{{ $pnr->wooOrder->number }}
                                        </a>
                                        <div style="font-size:0.75rem; color:#9ca3af;">{{ $pnr->wooOrder->order_date?->format('d.m.Y') }}</div>
                                    @else
                                        <span style="color:#9ca3af;">—</span>
                                    @endif
                                </td>
                                <td class="ppi-woo-table td" style="color:#374151;">
                                    @if($pnr->wooOrder)
                                        {{ ($pnr->wooOrder->billing['first_name'] ?? '') . ' ' . ($pnr->wooOrder->billing['last_name'] ?? '') }}
                                    @endif
                                </td>
                                <td class="ppi-woo-table td">
                                    @foreach($pnr->items as $item)
                                        <div style="font-size:0.75rem; color:#374151;">
                                            {{ $item->product_name }}
                                            <span style="color:#c2410c; font-weight:600;">× {{ $item->quantity }}</span>
                                        </div>
                                    @endforeach
                                </td>
                                <td class="ppi-woo-table td">
                                    <a href="{{ route('filament.app.resources.purchase-requests.view', ['record' => $pnr->id]) }}" class="ppi-link">
                                        {{ $pnr->number }}
                                    </a>
                                </td>
                                <td class="ppi-woo-table td">
                                    <span class="ppi-status {{ $pnr->status === 'submitted' ? 'ppi-status--submitted' : 'ppi-status--other' }}">
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

</div>
</x-filament-widgets::widget>
