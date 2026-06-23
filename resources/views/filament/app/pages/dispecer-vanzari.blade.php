<x-filament-panels::page>
<style>
    .dv-bar{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem;}
    .dv-chip{display:flex;flex-direction:column;align-items:center;gap:.1rem;background:#fff;border:1px solid #e5e7eb;border-radius:.6rem;padding:.4rem .9rem;cursor:pointer;min-width:5rem;}
    .dv-chip--on{border-color:#0e7490;background:#ecfeff;}
    .dv-chip-n{font-size:1.25rem;font-weight:800;line-height:1;color:#0f172a;}
    .dv-chip-l{font-size:.7rem;color:#6b7280;}
    .dv-card{background:#fff;border:1px solid #e5e7eb;border-radius:.7rem;margin-bottom:.6rem;overflow:hidden;}
    .dv-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.6rem .9rem;cursor:pointer;}
    .dv-head-l{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;}
    .dv-badge{font-size:.7rem;font-weight:800;padding:.15rem .55rem;border-radius:9999px;}
    .dv-badge--BON{background:#dcfce7;color:#065f46;}.dv-badge--F{background:#dbeafe;color:#1d4ed8;}.dv-badge--AE{background:#fef3c7;color:#92400e;}
    .dv-client{font-weight:600;font-size:.9rem;}
    .dv-pill{font-size:.65rem;font-weight:700;padding:.1rem .5rem;border-radius:9999px;}
    .src-magazin{background:#d1fae5;color:#065f46;}.src-depozit{background:#fef3c7;color:#92400e;}.src-livrare{background:#dbeafe;color:#1d4ed8;}.src-null{background:#f3f4f6;color:#9ca3af;}
    .dv-tot{font-weight:700;}
    .dv-line{padding:.55rem .9rem;border-top:1px solid #f1f5f9;}
    .dv-line-top{display:flex;justify-content:space-between;gap:1rem;margin-bottom:.35rem;}
    .dv-line-name{font-weight:600;font-size:.88rem;}
    .dv-sum{display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:.4rem;}
    .dv-quick{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;}
    .dv-btn{border:1px solid #e5e7eb;background:#fff;border-radius:.5rem;padding:.35rem .8rem;font-size:.78rem;font-weight:700;color:#374151;cursor:pointer;}
    .dv-btn.on-magazin{background:#10b981;border-color:#10b981;color:#fff;}.dv-btn.on-depozit{background:#f59e0b;border-color:#f59e0b;color:#fff;}.dv-btn.on-livrare{background:#3b82f6;border-color:#3b82f6;color:#fff;}
    .dv-split{display:flex;gap:.5rem;align-items:center;margin-top:.5rem;flex-wrap:wrap;}
    .dv-split label{font-size:.75rem;display:flex;align-items:center;gap:.25rem;}
    .dv-split input{width:4rem;border:1px solid #d1d5db;border-radius:.4rem;padding:.3rem;text-align:center;}
    .dv-confirm{margin:.6rem .9rem;background:#16a34a;border:none;color:#fff;border-radius:.5rem;padding:.5rem 1rem;font-weight:700;cursor:pointer;}
    .dv-empty{text-align:center;color:#9ca3af;padding:3rem;}
    [x-cloak]{display:none;}
</style>

@php
    $tipChips = ['' => ['Toate', $sumarTip['total']], 'F' => ['Facturi', $sumarTip['F']], 'AE' => ['Avize', $sumarTip['AE']], 'BON' => ['Bonuri', $sumarTip['BON']]];
    $srcChips = ['' => ['Toate', $sumar['total']], 'neallocat' => ['Neallocat', $sumar['neallocat']], 'magazin' => ['Magazin', $sumar['magazin']], 'depozit' => ['Depozit', $sumar['depozit']], 'livrare' => ['Livrare', $sumar['livrare']]];
    $tipLabels = ['BON' => 'Bon', 'F' => 'Factură', 'AE' => 'Aviz'];
    $fmt = fn($n) => rtrim(rtrim(number_format((float)$n,3,',','.'),'0'),',');
@endphp

<div class="dv-bar">
    @foreach($srcChips as $val => [$label, $n])
        <div class="dv-chip {{ $filtruSel === $val ? 'dv-chip--on' : '' }}" wire:click="$set('filtru', '{{ $val }}')"><span class="dv-chip-n">{{ $n }}</span><span class="dv-chip-l">{{ $label }}</span></div>
    @endforeach
</div>
<div class="dv-bar">
    @foreach($tipChips as $val => [$label, $n])
        <div class="dv-chip {{ $tipSel === $val ? 'dv-chip--on' : '' }}" wire:click="$set('tip', '{{ $val }}')"><span class="dv-chip-n">{{ $n }}</span><span class="dv-chip-l">{{ $label }}</span></div>
    @endforeach
</div>

<input type="text" wire:model.live.debounce.400ms="search" placeholder="Caută client, produs, document..."
       style="width:100%;max-width:420px;border:1px solid #d1d5db;border-radius:.5rem;padding:.5rem .8rem;margin-bottom:1rem;">

@forelse($documente as $doc)
    <div class="dv-card" x-data="{ open:false }">
        <div class="dv-head" @click="open=!open">
            <div class="dv-head-l">
                <span class="dv-badge dv-badge--{{ $doc['tip_doc'] }}">{{ $tipLabels[$doc['tip_doc']] ?? $doc['tip_doc'] }} #{{ $doc['nr_doc'] }}</span>
                <span class="dv-client">{{ $doc['client'] }}</span>
                @if($doc['confirmat'])<span class="dv-pill" style="background:#dcfce7;color:#166534;">✓ trimis</span>@endif
                @if($doc['rest_total'] > 0.001)<span class="dv-pill src-null">neallocat</span>@endif
                <span style="font-size:.72rem;color:#9ca3af;">{{ $doc['nr_linii'] }} linii</span>
            </div>
            <div style="display:flex;align-items:center;gap:.75rem;">
                <span class="dv-tot">{{ number_format($doc['total'], 2, ',', '.') }} lei</span>
                <span x-text="open ? '▲' : '▼'" style="color:#9ca3af;font-size:.75rem;"></span>
            </div>
        </div>

        <div x-show="open" x-cloak>
            @foreach($doc['lines'] as $line)
                @php
                    $aloc = $line['alocari'] ?? [];
                    $onlyS = count($aloc) === 1 ? array_key_first($aloc) : null;
                    $whole = ($onlyS && abs($aloc[$onlyS]['cant'] - $line['cantitate']) < 0.001) ? $onlyS : null;
                    $tot = (float) $line['cantitate'];
                @endphp
                <div class="dv-line" x-data="{ split:false, m:{{ $aloc['magazin']['cant'] ?? 0 }}, d:{{ $aloc['depozit']['cant'] ?? 0 }}, l:{{ $aloc['livrare']['cant'] ?? 0 }} }">
                    <div class="dv-line-top">
                        <span class="dv-line-name">{{ $line['produs'] }} <span style="color:#9ca3af;font-size:.72rem;">{{ $line['art_id'] }}</span></span>
                        <span style="font-size:.75rem;color:#6b7280;white-space:nowrap;">{{ $fmt($tot) }} buc</span>
                    </div>
                    <div class="dv-sum">
                        @forelse($aloc as $s => $a)<span class="dv-pill src-{{ $s }}">{{ $s }} {{ $fmt($a['cant']) }}</span>@empty<span class="dv-pill src-null">neallocat</span>@endforelse
                    </div>
                    <div class="dv-quick">
                        <button class="dv-btn {{ $whole==='magazin'?'on-magazin':'' }}" wire:click="setQuick('{{ $doc['tip_doc'] }}','{{ $doc['doc_id'] }}','{{ $line['pozitie'] }}','magazin',{{ $tot }})">🏬 Magazin</button>
                        <button class="dv-btn {{ $whole==='depozit'?'on-depozit':'' }}" wire:click="setQuick('{{ $doc['tip_doc'] }}','{{ $doc['doc_id'] }}','{{ $line['pozitie'] }}','depozit',{{ $tot }})">📦 Depozit</button>
                        <button class="dv-btn {{ $whole==='livrare'?'on-livrare':'' }}" wire:click="setQuick('{{ $doc['tip_doc'] }}','{{ $doc['doc_id'] }}','{{ $line['pozitie'] }}','livrare',{{ $tot }})">🚚 Livrare</button>
                        <button class="dv-btn" @click="split=!split">✂ împarte</button>
                    </div>
                    <div class="dv-split" x-show="split" x-cloak>
                        <label>🏬 <input type="number" min="0" step="0.01" x-model.number="m"></label>
                        <label>📦 <input type="number" min="0" step="0.01" x-model.number="d"></label>
                        <label>🚚 <input type="number" min="0" step="0.01" x-model.number="l"></label>
                        <button class="dv-btn" style="background:#0e7490;color:#fff;border-color:#0e7490;"
                                @click="$wire.salveazaSplit('{{ $doc['tip_doc'] }}','{{ $doc['doc_id'] }}','{{ $line['pozitie'] }}', m||0, d||0, l||0); split=false">Salvează</button>
                    </div>
                </div>
            @endforeach

            <button class="dv-confirm" wire:click="confirmaDoc('{{ $doc['tip_doc'] }}','{{ $doc['doc_id'] }}')">✓ Confirmă predarea</button>
        </div>
    </div>
@empty
    <div class="dv-empty">Nicio vânzare {{ $filtruSel || $tipSel ? 'pe filtrele alese' : 'azi' }}.</div>
@endforelse
</x-filament-panels::page>
