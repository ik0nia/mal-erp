<x-filament-panels::page>
<style>
.wc-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
.wc-search{display:flex;gap:.75rem;margin-bottom:1rem;align-items:center;flex-wrap:wrap;}
.wc-input{border:1px solid #d1d5db;border-radius:.5rem;padding:.5rem .75rem;font-size:.875rem;color:#111827;background:#fff;outline:none;width:320px;}
.wc-input:focus{border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15);}
.wc-card{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;overflow:hidden;margin-bottom:1rem;}
.wc-cmd-header{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;background:#f9fafb;border-bottom:1px solid #f3f4f6;cursor:pointer;transition:background .1s;}
.wc-cmd-header:hover{background:#f3f4f6;}
.wc-cmd-left{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;}
.wc-cmd-right{display:flex;align-items:center;gap:1rem;}
.wc-badge{display:inline-flex;padding:.175rem .625rem;border-radius:9999px;font-size:.75rem;font-weight:700;}
.wc-badge--blue{background:#dbeafe;color:#1d4ed8;}
.wc-badge--amber{background:#fef3c7;color:#92400e;}
.wc-badge--green{background:#d1fae5;color:#065f46;}
.wc-badge--gray{background:#f3f4f6;color:#6b7280;}
.wc-badge--red{background:#fee2e2;color:#991b1b;}
.wc-badge--purple{background:#ede9fe;color:#6d28d9;}
.wc-client{font-weight:600;color:#1f2937;font-size:.9rem;}
.wc-date{font-size:.75rem;color:#9ca3af;}
.wc-total{font-weight:700;color:#1f2937;font-size:.95rem;}
.wc-obs{padding:.5rem 1.25rem;background:#fffbeb;font-size:.8rem;color:#92400e;border-bottom:1px solid #fef3c7;display:flex;gap:.5rem;}
.wc-doc{padding:.5rem 1.25rem;background:#eef2ff;font-size:.8rem;color:#3730a3;border-bottom:1px solid #e0e7ff;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;}
.wc-table{width:100%;border-collapse:collapse;font-size:.875rem;}
.wc-table th{padding:.5rem 1rem;text-align:left;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:1px solid #f3f4f6;white-space:nowrap;}
.wc-table td{padding:.5rem 1rem;border-bottom:1px solid #f9fafb;color:#374151;}
.wc-table tbody tr:hover td{background:#f9fafb;}
.wc-table .r{text-align:right;}
.wc-table .mono{font-family:ui-monospace,monospace;font-size:.75rem;color:#9ca3af;}
.wc-table .bold{font-weight:600;color:#111827;}
.wc-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4rem 2rem;color:#9ca3af;gap:.75rem;}
.wc-empty-icon{font-size:3rem;}
.wc-empty-text{font-size:.9rem;}
.wc-empty-sub{font-size:.8rem;color:#d1d5db;}
.wc-arrow{font-size:.75rem;color:#9ca3af;transition:transform .15s;user-select:none;}
.wc-tabs{display:flex;gap:0;margin-bottom:1.25rem;border-bottom:2px solid #e5e7eb;}
.wc-tab{padding:.625rem 1.25rem;font-size:.875rem;font-weight:500;color:#6b7280;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;display:flex;align-items:center;gap:.5rem;}
.wc-tab:hover{color:#374151;}
.wc-tab--active{color:#4f46e5;border-bottom-color:#4f46e5;font-weight:600;}
.wc-tab-count{font-size:.7rem;background:#e5e7eb;color:#6b7280;padding:.1rem .5rem;border-radius:9999px;font-weight:600;}
.wc-tab--active .wc-tab-count{background:#eef2ff;color:#4f46e5;}
.wc-tracking{padding:.375rem 1.25rem;background:#f0fdf4;font-size:.75rem;color:#166534;border-bottom:1px solid #bbf7d0;display:flex;gap:1.5rem;flex-wrap:wrap;}
.wc-tracking--gone{background:#fef2f2;color:#991b1b;border-bottom-color:#fecaca;}
.wc-pill{padding:.375rem .75rem;font-size:.8rem;border:none;border-radius:.375rem;cursor:pointer;}
</style>

@if($error ?? false)
    <div style="border-radius:.75rem;border:1px solid #fca5a5;background:#fef2f2;padding:1rem;color:#991b1b;">{{ $error }}</div>
@else

{{-- Tabs --}}
<div class="wc-tabs">
    <div class="wc-tab {{ $tab === 'active' ? 'wc-tab--active' : '' }}" wire:click="$set('tab', 'active')">
        Deschise (live)
        <span class="wc-tab-count">{{ $countLive ?? '?' }}</span>
    </div>
    <div class="wc-tab {{ $tab === 'istoric' ? 'wc-tab--active' : '' }}" wire:click="$set('tab', 'istoric')">
        Istoric salvat
        <span class="wc-tab-count">{{ $countIstoric ?? 0 }}</span>
    </div>
</div>

{{-- Stats --}}
<div class="wc-stats">
    <div class="erp-stat erp-stat--info">
        <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-clipboard-document-list" /></div>
        <div class="erp-stat-body">
            <p class="erp-stat-label">{{ $tab === 'istoric' ? 'Comenzi în istoric' : 'Comenzi deschise' }}</p>
            <p class="erp-stat-value">{{ $totalComenzi }}</p>
        </div>
    </div>
    <div class="erp-stat">
        <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-cube" /></div>
        <div class="erp-stat-body">
            <p class="erp-stat-label">Linii produse</p>
            <p class="erp-stat-value">{{ $totalLinii }}</p>
        </div>
    </div>
    @if($tab === 'istoric')
    <div class="erp-stat erp-stat--warning">
        <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-clock" /></div>
        <div class="erp-stat-body">
            <p class="erp-stat-label">Încă deschise</p>
            <p class="erp-stat-value">{{ $totalDeschise ?? 0 }}</p>
        </div>
    </div>
    <div class="erp-stat erp-stat--success">
        <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-document-check" /></div>
        <div class="erp-stat-body">
            <p class="erp-stat-label">Facturate / livrate</p>
            <p class="erp-stat-value">{{ $totalFacturate ?? 0 }}</p>
        </div>
    </div>
    @else
    <div class="erp-stat erp-stat--success">
        <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-banknotes" /></div>
        <div class="erp-stat-body">
            <p class="erp-stat-label">Valoare totală</p>
            <p class="erp-stat-value">{{ $totalValoare }} <span style="font-size:.8rem;font-weight:400;color:#6b7280;">lei</span></p>
        </div>
    </div>
    @endif
</div>

{{-- Search + filtre --}}
<div class="wc-search">
    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Caută client, comandă, produs, factură..." class="wc-input" />
    @if($tab === 'istoric')
        <div style="display:flex;gap:2px;background:#f3f4f6;border-radius:.5rem;padding:2px;">
            @foreach(['azi' => 'Azi', 'saptamana' => 'Săptămâna', 'luna' => 'Luna', 'toate' => 'Toate'] as $val => $label)
                <button wire:click="$set('perioada', '{{ $val }}')" class="wc-pill"
                    style="font-weight:{{ $perioada === $val ? '600' : '400' }};background:{{ $perioada === $val ? '#fff' : 'transparent' }};color:{{ $perioada === $val ? '#4f46e5' : '#6b7280' }};{{ $perioada === $val ? 'box-shadow:0 1px 2px rgba(0,0,0,.05);' : '' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <div style="display:flex;gap:2px;background:#f3f4f6;border-radius:.5rem;padding:2px;">
            @foreach(['toate' => 'Toate', 'deschise' => 'Deschise', 'facturate' => 'Facturate'] as $val => $label)
                <button wire:click="$set('stare', '{{ $val }}')" class="wc-pill"
                    style="font-weight:{{ $stare === $val ? '600' : '400' }};background:{{ $stare === $val ? '#fff' : 'transparent' }};color:{{ $stare === $val ? '#4f46e5' : '#6b7280' }};{{ $stare === $val ? 'box-shadow:0 1px 2px rgba(0,0,0,.05);' : '' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    @endif
    @if($search)
        <span style="font-size:.8rem;color:#6b7280;">{{ $totalComenzi }} rezultate</span>
    @endif
</div>

{{-- Comenzi --}}
@forelse($comenzi ?? [] as $cmd)
    <div class="wc-card" x-data="{ open: {{ $tab === 'active' ? 'true' : 'false' }} }" @if($tab === 'istoric' && !($cmd['is_deschisa'] ?? true)) style="opacity:.75;" @endif>
        {{-- Header --}}
        <div class="wc-cmd-header" @click="open = !open">
            <div class="wc-cmd-left">
                <span class="wc-badge wc-badge--blue">{{ $cmd['serie'] }} {{ $cmd['nr_comanda'] }}</span>
                @if($tab === 'istoric')
                    @if($cmd['is_deschisa'] ?? true)
                        <span class="wc-badge wc-badge--green">deschisă</span>
                    @else
                        <span class="wc-badge wc-badge--red">facturată</span>
                    @endif
                @endif
                <span class="wc-client">{{ $cmd['client'] }}</span>
                @if($tab === 'istoric' && !($cmd['is_deschisa'] ?? true) && count($cmd['docs'] ?? []))
                    @foreach($cmd['docs'] as $doc)
                        <span class="wc-badge wc-badge--purple" title="{{ $doc['estimat'] ? 'Potrivire euristică (probabil)' : 'Document atribuit' }}">
                            {{ $doc['tip'] === 'F' ? 'Factură' : ($doc['tip'] === 'AE' ? 'Aviz' : $doc['tip']) }}
                            {{ $doc['serie'] ?: $doc['nr'] }}{{ $doc['estimat'] ? ' ~' : '' }}
                        </span>
                    @endforeach
                @endif
                <span class="wc-date">{{ $cmd['data'] }}</span>
            </div>
            <div class="wc-cmd-right">
                <span class="wc-total">{{ $cmd['total_fmt'] }} lei</span>
                <span class="wc-badge wc-badge--gray">{{ $cmd['nr_linii'] }} {{ $cmd['nr_linii'] == 1 ? 'produs' : 'produse' }}</span>
                <span class="wc-arrow" x-text="open ? '▲' : '▼'"></span>
            </div>
        </div>

        {{-- Tracking temporal (istoric) --}}
        @if($tab === 'istoric')
            <div class="wc-tracking {{ ($cmd['is_deschisa'] ?? true) ? '' : 'wc-tracking--gone' }}" x-show="open">
                @if($cmd['first_seen_at'] ?? null)<span><strong>Detectată:</strong> {{ $cmd['first_seen_at'] }}</span>@endif
                @if($cmd['last_seen_at'] ?? null)<span><strong>Ultima confirmare:</strong> {{ $cmd['last_seen_at'] }}</span>@endif
                @if($cmd['disappeared_at'] ?? null)<span><strong>Facturată/închisă:</strong> {{ $cmd['disappeared_at'] }}</span>@endif
            </div>
            @if(!($cmd['is_deschisa'] ?? true) && count($cmd['docs'] ?? []))
                <div class="wc-doc" x-show="open">
                    <strong>Document facturare:</strong>
                    @foreach($cmd['docs'] as $doc)
                        <span class="wc-badge wc-badge--purple">
                            {{ $doc['tip'] === 'F' ? 'Factură' : ($doc['tip'] === 'AE' ? 'Aviz' : ($doc['tip'] ?: 'Doc')) }}
                            {{ $doc['serie'] ?: $doc['nr'] }} @if($doc['data']) · {{ $doc['data'] }} @endif
                        </span>
                    @endforeach
                    @if(collect($cmd['docs'])->contains('estimat', true))
                        <span style="font-size:.7rem;color:#6b7280;">~ potrivire probabilă (WinMentor nu leagă direct comanda de factură)</span>
                    @endif
                </div>
            @elseif(!($cmd['is_deschisa'] ?? true))
                <div class="wc-doc" x-show="open" style="background:#fffbeb;color:#92400e;border-color:#fef3c7;">
                    <strong>Document facturare:</strong> nedeterminat — nu am găsit o potrivire în vânzările lunii.
                </div>
            @endif
        @endif

        {{-- Observații --}}
        @if($cmd['observatii'])
            <div class="wc-obs" x-show="open"><strong>Notă:</strong> <span>{{ $cmd['observatii'] }}</span></div>
        @endif

        {{-- Linii --}}
        <div x-show="open">
            <table class="wc-table">
                <thead>
                    <tr>
                        <th style="width:40px;">Poz</th>
                        <th>Cod</th>
                        <th>Produs</th>
                        <th class="r">Cantitate</th>
                        <th class="r">Preț unit.</th>
                        <th class="r">Valoare</th>
                        @if($tab === 'istoric')<th>Document</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($cmd['linii'] as $linie)
                        @php $val = (float) str_replace(',', '.', $linie['pret']) * (float) str_replace(',', '.', $linie['cantitate']); @endphp
                        <tr>
                            <td class="mono">{{ $linie['pozitie'] }}</td>
                            <td class="mono">{{ $linie['art_id'] }}</td>
                            <td>{{ $linie['produs'] }}</td>
                            <td class="r bold">{{ $linie['cantitate'] }}</td>
                            <td class="r" style="color:#6b7280;">{{ $linie['pret'] }}</td>
                            <td class="r bold">{{ number_format($val, 2, ',', '.') }}</td>
                            @if($tab === 'istoric')
                                <td class="mono" style="font-size:.7rem;">
                                    @if($linie['factura_nr'] ?? null)
                                        {{ $linie['factura_tip'] === 'F' ? 'F' : ($linie['factura_tip'] === 'AE' ? 'Aviz' : $linie['factura_tip']) }}
                                        {{ $linie['factura_serie'] ?: $linie['factura_nr'] }}{{ ($linie['factura_estimat'] ?? false) ? ' ~' : '' }}
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@empty
    <div class="wc-empty">
        @if($tab === 'istoric')
            <div class="wc-empty-icon">📋</div>
            <div class="wc-empty-text">Nicio comandă în istoric</div>
            <div class="wc-empty-sub">Rulează <code>php artisan sync:winmentor-comenzi</code> pentru prima sincronizare</div>
        @else
            <div class="wc-empty-icon">✅</div>
            <div class="wc-empty-text">Nicio comandă deschisă</div>
            <div class="wc-empty-sub">Toate comenzile clienți (non-CM) au fost facturate</div>
        @endif
    </div>
@endforelse

@endif
</x-filament-panels::page>
