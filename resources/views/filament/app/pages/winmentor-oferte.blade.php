<x-filament-panels::page>
<style>
.wo-tabs{display:flex;gap:.5rem;margin-bottom:1.5rem;}
.wo-tab{padding:.5rem 1rem;border-radius:.5rem;font-size:.875rem;font-weight:500;cursor:pointer;border:1px solid #d1d5db;background:#fff;color:#6b7280;transition:all .15s;}
.wo-tab:hover{background:#f9fafb;}
.wo-tab--active{background:#4f46e5;color:#fff;border-color:#4f46e5;}
.wo-tab--active:hover{background:#4338ca;}
.wo-card{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;overflow:hidden;margin-bottom:1.5rem;}
.wo-card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding:.75rem 1.25rem;border-bottom:1px solid #f3f4f6;background:#f9fafb;}
.wo-card-title{font-size:.9rem;font-weight:600;color:#1f2937;}
.wo-card-sub{font-size:.75rem;color:#9ca3af;}
.wo-table{width:100%;border-collapse:collapse;font-size:.875rem;}
.wo-table th{padding:.5rem 1rem;text-align:left;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:1px solid #f3f4f6;white-space:nowrap;}
.wo-table td{padding:.5rem 1rem;border-bottom:1px solid #f9fafb;color:#374151;}
.wo-table tbody tr:hover td{background:#f9fafb;}
.wo-table .r{text-align:right;}
.wo-table .c{text-align:center;}
.wo-table .mono{font-family:ui-monospace,monospace;font-size:.75rem;color:#9ca3af;}
.wo-table .trunc{max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.wo-badge{display:inline-flex;padding:.125rem .5rem;border-radius:9999px;font-size:.75rem;font-weight:600;}
.wo-badge--blue{background:#dbeafe;color:#1d4ed8;}
.wo-badge--green{background:#d1fae5;color:#065f46;}
.wo-badge--red{background:#fee2e2;color:#991b1b;}
.wo-badge--amber{background:#fef3c7;color:#92400e;}
.wo-badge--purple{background:#ede9fe;color:#5b21b6;}
.wo-badge--gray{background:#f3f4f6;color:#4b5563;}
.wo-cmd-header{display:flex;align-items:center;justify-content:space-between;padding:.625rem 1.25rem;background:#f9fafb;border-bottom:1px solid #f3f4f6;cursor:pointer;}
.wo-cmd-header:hover{background:#f3f4f6;}
.wo-obs{padding:.375rem 1.25rem;background:#fffbeb;font-size:.75rem;color:#92400e;border-bottom:1px solid #fef3c7;}
.wo-empty{display:flex;align-items:center;justify-content:center;padding:3rem;color:#9ca3af;font-size:.875rem;flex-direction:column;gap:.5rem;}
.wo-filters{display:flex;gap:.75rem;margin-bottom:1rem;align-items:center;}
.wo-input{border:1px solid #d1d5db;border-radius:.5rem;padding:.375rem .75rem;font-size:.875rem;color:#111827;background:#fff;outline:none;}
.wo-input:focus{border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15);}
.wo-select{border:1px solid #d1d5db;border-radius:.5rem;padding:.375rem .75rem;font-size:.875rem;color:#111827;background:#fff;}
.wo-muted{opacity:.45;}
.wo-bold{font-weight:600;color:#111827;}
.wo-group-header{display:flex;align-items:center;justify-content:space-between;padding:.625rem 1.25rem;background:#f9fafb;cursor:pointer;border-radius:.75rem .75rem 0 0;}
.wo-group-header:hover{background:#f3f4f6;}
.wo-hint{font-size:.75rem;color:#9ca3af;text-align:center;padding:.75rem;}
</style>

@if($error ?? false)
    <div style="border-radius:.75rem;border:1px solid #fca5a5;background:#fef2f2;padding:1rem;color:#991b1b;">{{ $error }}</div>
@else

{{-- Luna selector + Tabs --}}
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;">
    <div class="wo-tabs" style="margin-bottom:0;">
        @foreach([
            'comenzi' => 'Comenzi Nefacturate',
            'oferte' => 'Prețuri Negociate',
            'conditii' => 'Condiții Comerciale',
            'discounturi' => 'Discounturi Articole',
        ] as $key => $label)
            <button wire:click="switchTab('{{ $key }}')" class="wo-tab {{ $tab === $key ? 'wo-tab--active' : '' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>
    <div style="display:flex;align-items:center;gap:.5rem;">
        <span style="font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;">Luna:</span>
        <select wire:model.live="luna" class="wo-select" style="min-width:130px;">
            @for($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}">{{ ['','Ianuarie','Februarie','Martie','Aprilie','Mai','Iunie','Iulie','August','Septembrie','Octombrie','Noiembrie','Decembrie'][$m] }}</option>
            @endfor
        </select>
        <select wire:model.live="an" class="wo-select" style="min-width:80px;">
            @for($y = 2024; $y <= (int)date('Y'); $y++)
                <option value="{{ $y }}">{{ $y }}</option>
            @endfor
        </select>
        <span style="font-size:.8rem;font-weight:600;color:#4f46e5;">{{ $lunaLabel ?? '' }}</span>
    </div>
</div>

{{-- ═══ TAB: COMENZI NEFACTURATE ═══ --}}
@if($tab === 'comenzi')
    <div class="wo-card">
        <div class="wo-card-header">
            <div>
                <span class="wo-card-title">Comenzi Nefacturate — {{ $lunaLabel ?? 'Luna Curentă' }}</span>
                <span class="wo-card-sub" style="margin-left:.75rem;">{{ $totalComenzi ?? 0 }} linii · {{ count($comenzi ?? []) }} comenzi</span>
            </div>
            <span class="wo-card-sub">sursa: /api/comenzi/nefacturate</span>
        </div>

        @forelse($comenzi ?? [] as $cmd)
            <div x-data="{ open: true }">
                <div class="wo-cmd-header" @click="open = !open">
                    <div style="display:flex;align-items:center;gap:.75rem;">
                        <span class="wo-badge wo-badge--blue">{{ $cmd['tip_doc'] }} {{ $cmd['nr_comanda'] }}</span>
                        <span style="font-weight:500;color:#374151;">{{ $cmd['client'] }}</span>
                        <span style="font-size:.75rem;color:#9ca3af;">{{ $cmd['data'] }}</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:1rem;">
                        <span class="wo-bold">{{ $cmd['total'] }} lei</span>
                        <span class="wo-card-sub">{{ $cmd['nr_linii'] }} {{ $cmd['nr_linii'] == 1 ? 'produs' : 'produse' }}</span>
                        <span style="font-size:.75rem;color:#9ca3af;" x-text="open ? '▲' : '▼'"></span>
                    </div>
                </div>

                @if($cmd['observatii'])
                    <div class="wo-obs" x-show="open">
                        <strong>Obs:</strong> {{ $cmd['observatii'] }}
                    </div>
                @endif

                <div x-show="open">
                    <table class="wo-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">Poz</th>
                                <th>Cod articol</th>
                                <th>Produs</th>
                                <th class="r">Cant.</th>
                                <th class="r">Preț unit.</th>
                                <th class="r">Valoare</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($cmd['linii'] as $linie)
                                @php
                                    $val = (float) str_replace(',', '.', $linie['pret']) * (float) str_replace(',', '.', $linie['cantitate']);
                                @endphp
                                <tr>
                                    <td style="color:#9ca3af;font-size:.75rem;">{{ $linie['pozitie'] }}</td>
                                    <td class="mono">{{ $linie['art_id'] }}</td>
                                    <td>{{ $linie['produs'] }}</td>
                                    <td class="r wo-bold">{{ $linie['cantitate'] }}</td>
                                    <td class="r" style="color:#6b7280;">{{ $linie['pret'] }}</td>
                                    <td class="r wo-bold">{{ number_format($val, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="wo-empty">
                <div style="font-size:2rem;">📋</div>
                Nicio comandă nefacturată în luna curentă.
            </div>
        @endforelse
    </div>

{{-- ═══ TAB: OFERTE (PREȚURI NEGOCIATE) ═══ --}}
@elseif($tab === 'oferte')
    <div class="erp-stat-grid" style="margin-bottom:1.5rem;">
        @foreach([
            ['label' => 'Total prețuri', 'value' => $oferteStats['total'] ?? 0, 'variant' => '', 'icon' => 'heroicon-o-tag'],
            ['label' => 'Active', 'value' => $oferteStats['active'] ?? 0, 'variant' => 'erp-stat--success', 'icon' => 'heroicon-o-check-circle'],
            ['label' => 'Parteneri', 'value' => $oferteStats['parteneri'] ?? 0, 'variant' => 'erp-stat--info', 'icon' => 'heroicon-o-user-group'],
            ['label' => 'Produse', 'value' => $oferteStats['produse'] ?? 0, 'variant' => 'erp-stat--info', 'icon' => 'heroicon-o-cube'],
        ] as $stat)
            <div class="erp-stat {{ $stat['variant'] }}">
                <div class="erp-stat-icon"><x-filament::icon :icon="$stat['icon']" /></div>
                <div class="erp-stat-body">
                    <p class="erp-stat-label">{{ $stat['label'] }}</p>
                    <p class="erp-stat-value">{{ number_format($stat['value']) }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="wo-filters">
        <input type="text" wire:model.live.debounce.500ms="searchOferte" placeholder="Caută client sau produs..." class="wo-input" style="width:300px;" />
        <select wire:model.live="filterActiv" class="wo-select">
            <option value="toate">Toate</option>
            <option value="active">Doar active</option>
            <option value="expirate">Doar expirate</option>
        </select>
        <span class="wo-card-sub">{{ count($oferte ?? []) }} rezultate</span>
    </div>

    <div class="wo-card">
        <table class="wo-table">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Produs</th>
                    <th class="r">Preț</th>
                    <th class="c">Cant. min</th>
                    <th>Început</th>
                    <th>Sfârșit</th>
                    <th class="c">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach(collect($oferte ?? [])->take(200) as $o)
                    <tr class="{{ !$o['activ'] ? 'wo-muted' : '' }}">
                        <td class="trunc" title="{{ $o['client'] }}">{{ $o['client'] }}</td>
                        <td class="trunc" title="{{ $o['produs'] }}">{{ $o['produs'] }}</td>
                        <td class="r wo-bold">{{ $o['pret'] }}</td>
                        <td class="c" style="color:#6b7280;">{{ $o['cantitate'] }}</td>
                        <td style="font-size:.75rem;color:#6b7280;">{{ $o['data_inceput'] }}</td>
                        <td style="font-size:.75rem;color:#6b7280;">{{ $o['data_sfarsit'] }}</td>
                        <td class="c">
                            @if($o['activ'])
                                <span class="wo-badge wo-badge--green">Activ</span>
                            @else
                                <span class="wo-badge wo-badge--gray">Expirat</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if(count($oferte ?? []) > 200)
            <div class="wo-hint">Se afișează primele 200 din {{ count($oferte) }}. Folosește căutarea pentru a filtra.</div>
        @endif
    </div>

{{-- ═══ TAB: CONDIȚII COMERCIALE ═══ --}}
@elseif($tab === 'conditii')
    <div class="wo-card">
        <div class="wo-card-header">
            <span class="wo-card-title">Condiții Comerciale per Client</span>
            <span class="wo-card-sub">{{ count($conditii ?? []) }} condiții · sursa: /api/oferte/clienti</span>
        </div>
        <table class="wo-table">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Produs</th>
                    <th class="c">Monedă</th>
                    <th>Condiție</th>
                </tr>
            </thead>
            <tbody>
                @forelse($conditii ?? [] as $c)
                    <tr>
                        <td>{{ $c['client'] }}</td>
                        <td>{{ $c['produs'] }}</td>
                        <td class="c" style="color:#6b7280;">{{ $c['moneda'] }}</td>
                        <td>
                            <span class="wo-badge wo-badge--amber" style="font-family:ui-monospace,monospace;">{{ $c['conditie'] }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="wo-empty">Nicio condiție comercială.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

{{-- ═══ TAB: DISCOUNTURI PE ARTICOLE ═══ --}}
@elseif($tab === 'discounturi')
    <p style="font-size:.875rem;color:#6b7280;margin-bottom:1rem;">Criterii de discount pe grupuri de articole — sursa: /api/discount/pe-articole</p>

    @forelse($discounturi ?? [] as $group)
        <div class="wo-card" x-data="{ open: true }">
            <div class="wo-group-header" @click="open = !open">
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <span class="wo-badge wo-badge--purple">{{ $group['criteriu'] }}</span>
                    <span style="font-size:.875rem;color:#6b7280;">{{ count($group['articole']) }} articole</span>
                </div>
                <span style="font-size:.75rem;color:#9ca3af;" x-text="open ? '▲' : '▼'"></span>
            </div>
            <div x-show="open">
                <table class="wo-table">
                    <tbody>
                        @foreach($group['articole'] as $art)
                            <tr>
                                <td class="mono" style="width:160px;">{{ $art['art_id'] }}</td>
                                <td>{{ $art['produs'] }}</td>
                                <td class="r" style="width:100px;">
                                    <span class="wo-badge {{ (float)$art['discount'] < 0 ? 'wo-badge--red' : 'wo-badge--green' }}">
                                        {{ $art['discount'] }}%
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="wo-card">
            <div class="wo-empty">Niciun criteriu de discount configurat.</div>
        </div>
    @endforelse
@endif

@endif
</x-filament-panels::page>
