<style>
.ua-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; }
.ua-badge { display:inline-flex; align-items:center; padding:0.25rem 0.75rem; border-radius:9999px; font-size:0.875rem; font-weight:500; }
.ua-badge--warning { background:#fef3c7; color:#92400e; }
.ua-badge--danger { background:#fee2e2; color:#991b1b; }
.ua-btn-save-all { display:inline-flex; align-items:center; gap:0.5rem; padding:0.5rem 1rem; background:#dc2626; color:#fff; font-size:0.875rem; font-weight:500; border-radius:0.5rem; border:none; cursor:pointer; }
.ua-btn-save-all:hover { background:#b91c1c; }
.ua-table-wrap { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; overflow:hidden; }
.ua-table { width:100%; font-size:0.875rem; border-collapse:collapse; }
.ua-table th { padding:0.75rem 1rem; text-align:left; font-size:0.75rem; font-weight:500; color:#6b7280; text-transform:uppercase; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
.ua-table td { padding:0.75rem 1rem; border-bottom:1px solid #f3f4f6; vertical-align:top; }
.ua-table tr:hover td { background:#f9fafb; }
.ua-urgent-row td { background:#fef2f2; }
.ua-urg-badge { display:inline-flex; align-items:center; padding:0.125rem 0.375rem; border-radius:0.25rem; font-size:0.75rem; font-weight:700; background:#fee2e2; color:#b91c1c; margin-right:0.5rem; flex-shrink:0; }
.ua-product-name { font-weight:500; color:#111827; }
.ua-sku { font-size:0.75rem; color:#9ca3af; font-family:monospace; }
.ua-link { font-size:0.75rem; color:#dc2626; font-family:monospace; text-decoration:none; }
.ua-link:hover { text-decoration:underline; }
.ua-alloc { display:flex; align-items:center; gap:0.5rem; }
.ua-alloc select { flex:1; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.4rem 0.5rem; font-size:0.875rem; background:#fff; }
.ua-btn-alloc { flex-shrink:0; display:inline-flex; align-items:center; gap:0.25rem; padding:0.5rem 0.75rem; background:#16a34a; color:#fff; font-size:0.75rem; font-weight:500; border-radius:0.5rem; border:none; cursor:pointer; }
.ua-btn-alloc:hover { background:#15803d; }
.ua-btn-alloc:disabled { opacity:0.5; }
.ua-note { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; padding:1rem; margin-top:1rem; }
.ua-note p { font-size:0.75rem; color:#374151; display:flex; align-items:flex-start; gap:0.5rem; }
.ua-empty { text-align:center; padding:4rem 0; color:#9ca3af; }
.ua-empty p { font-size:1.125rem; font-weight:500; }
.ua-empty .sub { font-size:0.875rem; margin-top:0.25rem; }
</style>

<x-filament-panels::page>

    @if(empty($items))
        <div class="ua-empty">
            <x-filament::icon icon="heroicon-o-check-circle" style="width:3rem; height:3rem; margin:0 auto 0.75rem; color:#34d399;"/>
            <p>Toate produsele au furnizor alocat</p>
            <p class="sub">Nu există necesare în așteptare fără furnizor.</p>
        </div>
    @else
        <div class="ua-header">
            <div style="display:flex; align-items:center; gap:0.75rem;">
                <span class="ua-badge ua-badge--warning">
                    {{ count($items) }} {{ count($items) === 1 ? 'produs fără furnizor' : 'produse fără furnizor' }}
                </span>
                @php $urgentCount = collect($items)->where('is_urgent', true)->count(); @endphp
                @if($urgentCount > 0)
                    <span class="ua-badge ua-badge--danger">
                        {{ $urgentCount }} urgent{{ $urgentCount > 1 ? 'e' : '' }}
                    </span>
                @endif
            </div>
            <button wire:click="saveAll" class="ua-btn-save-all">
                <x-filament::icon icon="heroicon-o-check" style="width:1rem; height:1rem;"/>
                Alocă toate selecțiile
            </button>
        </div>

        <div class="ua-table-wrap">
            <div style="overflow-x:auto;">
                <table class="ua-table">
                    <thead>
                        <tr>
                            <th>Produs / SKU</th>
                            <th style="text-align:right;">Cant.</th>
                            <th>Necesar până la</th>
                            <th>Consultant / Locație</th>
                            <th>Justificație</th>
                            <th>Necesar</th>
                            <th style="min-width:280px;">Alocare furnizor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                            <tr wire:key="item-{{ $item['id'] }}" class="{{ $item['is_urgent'] ? 'ua-urgent-row' : '' }}">
                                <td>
                                    <div style="display:flex; align-items:center; gap:0.5rem;">
                                        @if($item['is_urgent'])
                                            <span class="ua-urg-badge">URG</span>
                                        @endif
                                        <div>
                                            <div class="ua-product-name">{{ $item['product_name'] }}</div>
                                            @if($item['sku'])
                                                <div class="ua-sku">{{ $item['sku'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align:right; font-weight:500; color:#111827;">
                                    {{ number_format($item['quantity'], 0, '.', '') }}
                                </td>
                                <td style="color:#4b5563;">
                                    @if($item['needed_by'])
                                        @php $isPast = \Carbon\Carbon::createFromFormat('d.m.Y', $item['needed_by'])->isPast(); @endphp
                                        <span style="{{ $isPast ? 'color:#dc2626; font-weight:500;' : '' }}">
                                            {{ $item['needed_by'] }}
                                            @if($isPast)
                                                <span style="font-size:0.75rem; margin-left:0.25rem;">(expirat)</span>
                                            @endif
                                        </span>
                                    @else
                                        <span style="color:#d1d5db;">—</span>
                                    @endif
                                </td>
                                <td style="color:#4b5563;">
                                    <div>{{ $item['consultant'] ?? '—' }}</div>
                                    @if($item['location'])
                                        <div style="font-size:0.75rem; color:#9ca3af;">{{ $item['location'] }}</div>
                                    @endif
                                </td>
                                <td style="color:#6b7280;">
                                    @if($item['is_reserved'] && $item['client_reference'])
                                        <span style="font-size:0.75rem; display:inline-flex; align-items:center; gap:0.25rem;">
                                            <x-filament::icon icon="heroicon-s-bookmark" style="width:0.75rem; height:0.75rem; color:#d97706;"/>
                                            <span style="font-family:monospace; background:#f3f4f6; padding:0.125rem 0.375rem; border-radius:0.25rem;">{{ $item['client_reference'] }}</span>
                                        </span>
                                    @elseif($item['notes'])
                                        <span style="font-size:0.75rem;">{{ Str::limit($item['notes'], 40) }}</span>
                                    @else
                                        <span style="color:#d1d5db;">—</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('filament.app.resources.purchase-requests.view', ['record' => $item['request_id']]) }}" class="ua-link">
                                        {{ $item['request_number'] }}
                                    </a>
                                </td>
                                <td>
                                    <div class="ua-alloc">
                                        <select wire:model="selectedSuppliers.{{ $item['id'] }}">
                                            <option value="">— Selectează furnizor —</option>
                                            @if(!empty($item['suggested']))
                                                <optgroup label="✓ Furnizori cunoscuți">
                                                    @foreach($item['suggested'] as $s)
                                                        <option value="{{ $s['id'] }}">
                                                            {{ $s['is_preferred'] ? '★ ' : '' }}{{ $s['name'] }}
                                                            @if($s['price']) ({{ number_format($s['price'], 2, ',', '.') }} RON) @endif
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
                                                @foreach($allSuppliers as $id => $name)
                                                    <option value="{{ $id }}">{{ $name }}</option>
                                                @endforeach
                                            @endif
                                        </select>
                                        <button wire:click="saveAssignment({{ $item['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="saveAssignment({{ $item['id'] }})"
                                                class="ua-btn-alloc">
                                            <x-filament::icon icon="heroicon-o-check" style="width:0.875rem; height:0.875rem;"/>
                                            <span wire:loading.remove wire:target="saveAssignment({{ $item['id'] }})">Alocă</span>
                                            <span wire:loading wire:target="saveAssignment({{ $item['id'] }})">...</span>
                                        </button>
                                    </div>
                                    @if(empty($item['suggested']))
                                        <p style="font-size:0.75rem; color:#9ca3af; margin-top:0.25rem; display:flex; align-items:center; gap:0.25rem;">
                                            <x-filament::icon icon="heroicon-o-exclamation-circle" style="width:0.75rem; height:0.75rem;"/> Produs fără furnizori configurați
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ua-note">
            <p>
                <x-filament::icon icon="heroicon-o-information-circle" style="width:1rem; height:1rem; flex-shrink:0; margin-top:0.125rem; color:#2563eb;"/>
                După alocare, produsele apar automat în pagina <strong>Generează comandă</strong> a buyer-ului responsabil pentru furnizorul selectat.
                Furnizorii marcați cu <strong>★</strong> sunt cei preferați pentru produsul respectiv.
            </p>
        </div>
    @endif

</x-filament-panels::page>
