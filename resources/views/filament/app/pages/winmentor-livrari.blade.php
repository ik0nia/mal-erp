<x-filament-panels::page>
<style>
.wl-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;}
.wl-stat{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem;}
.wl-stat-icon{width:2.5rem;height:2.5rem;border-radius:.625rem;display:flex;align-items:center;justify-content:center;font-size:1.25rem;}
.wl-stat-icon--blue{background:#dbeafe;}
.wl-stat-icon--amber{background:#fef3c7;}
.wl-stat-icon--green{background:#d1fae5;}
.wl-stat-icon--purple{background:#ede9fe;}
.wl-stat-val{font-size:1.5rem;font-weight:700;color:#1f2937;}
.wl-stat-label{font-size:.75rem;color:#9ca3af;}
.wl-search{display:flex;gap:.75rem;margin-bottom:1rem;align-items:center;}
.wl-input{border:1px solid #d1d5db;border-radius:.5rem;padding:.5rem .75rem;font-size:.875rem;color:#111827;background:#fff;outline:none;width:320px;}
.wl-input:focus{border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15);}
.wl-card{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;overflow:hidden;margin-bottom:1rem;}
.wl-cmd-header{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;background:#f9fafb;border-bottom:1px solid #f3f4f6;cursor:pointer;transition:background .1s;}
.wl-cmd-header:hover{background:#f3f4f6;}
.wl-cmd-left{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;}
.wl-cmd-right{display:flex;align-items:center;gap:1rem;}
.wl-badge{display:inline-flex;padding:.175rem .625rem;border-radius:9999px;font-size:.75rem;font-weight:700;}
.wl-badge--blue{background:#dbeafe;color:#1d4ed8;}
.wl-badge--amber{background:#fef3c7;color:#92400e;}
.wl-badge--green{background:#d1fae5;color:#065f46;}
.wl-badge--gray{background:#f3f4f6;color:#6b7280;}
.wl-badge--red{background:#fee2e2;color:#991b1b;}
.wl-badge--purple{background:#ede9fe;color:#6d28d9;}
.wl-client{font-weight:600;color:#1f2937;font-size:.9rem;}
.wl-date{font-size:.75rem;color:#9ca3af;}
.wl-total{font-weight:700;color:#1f2937;font-size:.95rem;}
.wl-count{font-size:.75rem;color:#9ca3af;}
.wl-obs{padding:.5rem 1.25rem;background:#fffbeb;font-size:.8rem;color:#92400e;border-bottom:1px solid #fef3c7;display:flex;gap:.5rem;}
.wl-obs strong{color:#78350f;}
.wl-table{width:100%;border-collapse:collapse;font-size:.875rem;}
.wl-table th{padding:.5rem 1rem;text-align:left;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:1px solid #f3f4f6;white-space:nowrap;}
.wl-table td{padding:.5rem 1rem;border-bottom:1px solid #f9fafb;color:#374151;}
.wl-table tbody tr:hover td{background:#f9fafb;}
.wl-table .r{text-align:right;}
.wl-table .mono{font-family:ui-monospace,monospace;font-size:.75rem;color:#9ca3af;}
.wl-table .bold{font-weight:600;color:#111827;}
.wl-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4rem 2rem;color:#9ca3af;gap:.75rem;}
.wl-empty-icon{font-size:3rem;}
.wl-empty-text{font-size:.9rem;}
.wl-empty-sub{font-size:.8rem;color:#d1d5db;}
.wl-arrow{font-size:.75rem;color:#9ca3af;transition:transform .15s;user-select:none;}
.wl-tabs{display:flex;gap:0;margin-bottom:1.25rem;border-bottom:2px solid #e5e7eb;}
.wl-tab{padding:.625rem 1.25rem;font-size:.875rem;font-weight:500;color:#6b7280;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;display:flex;align-items:center;gap:.5rem;}
.wl-tab:hover{color:#374151;}
.wl-tab--active{color:#4f46e5;border-bottom-color:#4f46e5;font-weight:600;}
.wl-tab-count{font-size:.7rem;background:#e5e7eb;color:#6b7280;padding:.1rem .5rem;border-radius:9999px;font-weight:600;}
.wl-tab--active .wl-tab-count{background:#eef2ff;color:#4f46e5;}
.wl-tracking{padding:.375rem 1.25rem;background:#f0fdf4;font-size:.75rem;color:#166534;border-bottom:1px solid #bbf7d0;display:flex;gap:1.5rem;flex-wrap:wrap;}
.wl-tracking--gone{background:#fef2f2;color:#991b1b;border-bottom-color:#fecaca;}
.wl-tracking span{display:flex;align-items:center;gap:.25rem;}
.wl-tracking strong{font-weight:600;}
</style>

@if($error ?? false)
    <div style="border-radius:.75rem;border:1px solid #fca5a5;background:#fef2f2;padding:1rem;color:#991b1b;">{{ $error }}</div>
@else

{{-- Tabs --}}
<div class="wl-tabs">
    <div class="wl-tab {{ $tab === 'active' ? 'wl-tab--active' : '' }}" wire:click="$set('tab', 'active')">
        Live (Bridge)
        <span class="wl-tab-count">{{ $countLive ?? '?' }}</span>
    </div>
    <div class="wl-tab {{ $tab === 'istoric' ? 'wl-tab--active' : '' }}" wire:click="$set('tab', 'istoric')">
        Istoric salvat
        <span class="wl-tab-count">{{ $countIstoric ?? 0 }}</span>
    </div>
</div>

{{-- Stats --}}
<div class="wl-stats">
    <div class="wl-stat">
        <div class="wl-stat-icon wl-stat-icon--blue">🚚</div>
        <div>
            <div class="wl-stat-val">{{ $totalComenzi }}</div>
            <div class="wl-stat-label">{{ $tab === 'istoric' ? 'Comenzi în istoric' : 'Comenzi în livrare' }}</div>
        </div>
    </div>
    <div class="wl-stat">
        <div class="wl-stat-icon wl-stat-icon--amber">📦</div>
        <div>
            <div class="wl-stat-val">{{ $totalLinii }}</div>
            <div class="wl-stat-label">{{ $tab === 'istoric' ? 'Linii în istoric' : 'Produse de livrat' }}</div>
        </div>
    </div>
    <div class="wl-stat">
        <div class="wl-stat-icon wl-stat-icon--green">💰</div>
        <div>
            <div class="wl-stat-val">{{ $totalValoare }} <span style="font-size:.8rem;font-weight:400;color:#6b7280;">lei</span></div>
            <div class="wl-stat-label">Valoare totală</div>
        </div>
    </div>
</div>

@if($tab === 'istoric')
<div class="wl-stats">
    <div class="wl-stat">
        <div class="wl-stat-icon wl-stat-icon--purple">⏱</div>
        <div>
            <div class="wl-stat-val">{{ $avgDurataFmt ?? '—' }}</div>
            <div class="wl-stat-label">Timp mediu livrare</div>
        </div>
    </div>
    <div class="wl-stat">
        <div class="wl-stat-icon" style="background:#dcfce7;">💵</div>
        <div>
            <div class="wl-stat-val">{{ $totalCash ?? 0 }} <span style="font-size:.8rem;font-weight:400;color:#6b7280;">/ {{ $valoareCash ?? '0' }} lei</span></div>
            <div class="wl-stat-label">Comenzi cash</div>
        </div>
    </div>
    <div class="wl-stat">
        <div class="wl-stat-icon" style="background:#dbeafe;">💳</div>
        <div>
            <div class="wl-stat-val">{{ $totalCard ?? 0 }} <span style="font-size:.8rem;font-weight:400;color:#6b7280;">/ {{ $valoareCard ?? '0' }} lei</span></div>
            <div class="wl-stat-label">Comenzi card</div>
        </div>
    </div>
</div>
@endif

{{-- Search + Period filter --}}
<div class="wl-search">
    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Caută client, comandă, produs, observații..." class="wl-input" />
    @if($tab === 'istoric')
        <div style="display:flex;gap:2px;background:#f3f4f6;border-radius:.5rem;padding:2px;">
            @foreach(['azi' => 'Azi', 'saptamana' => 'Săptămâna', 'luna' => 'Luna', 'toate' => 'Toate'] as $val => $label)
                <button wire:click="$set('perioada', '{{ $val }}')"
                    style="padding:.375rem .75rem;font-size:.8rem;border:none;border-radius:.375rem;cursor:pointer;font-weight:{{ $perioada === $val ? '600' : '400' }};background:{{ $perioada === $val ? '#fff' : 'transparent' }};color:{{ $perioada === $val ? '#4f46e5' : '#6b7280' }};{{ $perioada === $val ? 'box-shadow:0 1px 2px rgba(0,0,0,.05);' : '' }}">
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
    <div class="wl-card" x-data="{ open: {{ $tab === 'active' ? 'true' : 'false' }} }" @if($tab === 'istoric' && ($cmd['is_active'] ?? true) === false) style="opacity:.7;" @endif>
        {{-- Header --}}
        <div class="wl-cmd-header" @click="open = !open">
            <div class="wl-cmd-left">
                <span class="wl-badge {{ ($tab === 'istoric' && !($cmd['is_active'] ?? true)) ? 'wl-badge--red' : 'wl-badge--blue' }}">
                    {{ $cmd['tip_doc'] }} {{ $cmd['nr_comanda'] }}
                </span>
                @if($tab === 'istoric')
                    @if($cmd['is_active'] ?? true)
                        <span class="wl-badge wl-badge--green">activă</span>
                    @else
                        <span class="wl-badge wl-badge--red">facturată</span>
                    @endif
                @endif
                <span class="wl-client">{{ $cmd['client'] }}</span>
                @if($cmd['is_sediu'] ?? false)
                    <span class="wl-badge wl-badge--purple" title="Livrare pe sediu {{ $cmd['part_id'] }}">sediu {{ $cmd['part_id'] }}</span>
                @endif
                @if(($cmd['metoda_plata'] ?? null) === 'cash')
                    <span class="wl-badge wl-badge--green">CASH</span>
                @elseif(($cmd['metoda_plata'] ?? null) === 'card')
                    <span class="wl-badge wl-badge--blue">CARD</span>
                @endif
                @if($cmd['durata_fmt'] ?? null)
                    <span class="wl-badge wl-badge--gray" title="Timp livrare">{{ $cmd['durata_fmt'] }}</span>
                @endif
                <span class="wl-date">{{ $cmd['data'] }}</span>
            </div>
            <div class="wl-cmd-right">
                <span class="wl-total">{{ $cmd['total_fmt'] }} lei</span>
                <span class="wl-badge wl-badge--gray">{{ $cmd['nr_linii'] }} {{ $cmd['nr_linii'] == 1 ? 'produs' : 'produse' }}</span>
                <span class="wl-arrow" x-text="open ? '▲' : '▼'"></span>
            </div>
        </div>

        {{-- Tracking temporal (doar pe istoric) --}}
        @if($tab === 'istoric')
            <div class="wl-tracking {{ ($cmd['is_active'] ?? true) ? '' : 'wl-tracking--gone' }}" x-show="open">
                @if($cmd['first_seen_at'] ?? null)
                    <span><strong>Detectată:</strong> {{ $cmd['first_seen_at'] }}</span>
                @endif
                @if($cmd['last_seen_at'] ?? null)
                    <span><strong>Ultima confirmare:</strong> {{ $cmd['last_seen_at'] }}</span>
                @endif
                @if($cmd['disappeared_at'] ?? null)
                    <span><strong>Dispărută:</strong> {{ $cmd['disappeared_at'] }}</span>
                @endif
            </div>
        @endif

        {{-- Observații --}}
        @if($cmd['observatii'])
            <div class="wl-obs" x-show="open">
                <strong>Livrare:</strong>
                <span>{{ $cmd['observatii'] }}</span>
            </div>
        @endif

        {{-- Linii --}}
        <div x-show="open">
            <table class="wl-table">
                <thead>
                    <tr>
                        <th style="width:40px;">Poz</th>
                        <th>Cod</th>
                        <th>Produs</th>
                        <th class="r">Cantitate</th>
                        <th class="r">Preț unit.</th>
                        <th class="r">Valoare</th>
                        @if($tab === 'istoric')
                            <th>Detectat</th>
                            <th>Dispărut</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($cmd['linii'] as $linie)
                        @php
                            $val = (float) str_replace(',', '.', $linie['pret']) * (float) str_replace(',', '.', $linie['cantitate']);
                        @endphp
                        <tr>
                            <td class="mono">{{ $linie['pozitie'] }}</td>
                            <td class="mono">{{ $linie['art_id'] }}</td>
                            <td>{{ $linie['produs'] }}</td>
                            <td class="r bold">{{ $linie['cantitate'] }}</td>
                            <td class="r" style="color:#6b7280;">{{ $linie['pret'] }}</td>
                            <td class="r bold">{{ number_format($val, 2, ',', '.') }}</td>
                            @if($tab === 'istoric')
                                <td class="mono" style="font-size:.7rem;">{{ $linie['first_seen_at'] ?? '—' }}</td>
                                <td class="mono" style="font-size:.7rem;{{ ($linie['disappeared_at'] ?? null) ? 'color:#991b1b;' : '' }}">{{ $linie['disappeared_at'] ?? '—' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@empty
    <div class="wl-empty">
        @if($tab === 'istoric')
            <div class="wl-empty-icon">📋</div>
            <div class="wl-empty-text">Nicio livrare în istoric</div>
            <div class="wl-empty-sub">Rulează <code>php artisan sync:winmentor-livrari</code> pentru prima sincronizare</div>
        @else
            <div class="wl-empty-icon">✅</div>
            <div class="wl-empty-text">Nicio comandă în livrare</div>
            <div class="wl-empty-sub">Toate comenzile CM au fost facturate de pe casele de marcat mobile</div>
        @endif
    </div>
@endforelse

@endif
</x-filament-panels::page>
