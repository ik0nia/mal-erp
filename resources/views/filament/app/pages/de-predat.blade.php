<x-filament-panels::page>
<style>
    .dp-bar{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;}
    .dp-chip{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:.4rem .9rem;cursor:pointer;font-size:.8rem;font-weight:600;color:#374151;}
    .dp-chip--on{border-color:#0e7490;background:#ecfeff;color:#0e7490;}
    .dp-card{background:#fff;border:1px solid #e5e7eb;border-radius:.7rem;margin-bottom:.6rem;overflow:hidden;}
    .dp-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.65rem .9rem;background:#fffbeb;border-bottom:1px solid #fef3c7;}
    .dp-head-l{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;}
    .dp-badge{font-size:.7rem;font-weight:800;padding:.15rem .55rem;border-radius:9999px;background:#e0e7ff;color:#3730a3;}
    .dp-client{font-weight:700;font-size:.92rem;}
    .dp-pill{font-size:.65rem;font-weight:700;padding:.1rem .5rem;border-radius:9999px;}
    .dp-magazin{background:#d1fae5;color:#065f46;}
    .dp-depozit{background:#fef3c7;color:#92400e;}
    .dp-livrare{background:#dbeafe;color:#1d4ed8;}
    .dp-table{width:100%;border-collapse:collapse;font-size:.85rem;}
    .dp-table th{padding:.4rem .9rem;text-align:left;font-size:.68rem;text-transform:uppercase;color:#9ca3af;}
    .dp-table td{padding:.4rem .9rem;border-top:1px solid #f8fafc;}
    .dp-table .r{text-align:right;}
    .dp-done{background:#16a34a;border:none;color:#fff;border-radius:.5rem;padding:.45rem .9rem;font-weight:700;font-size:.82rem;cursor:pointer;}
    .dp-check{width:2rem;height:2rem;border:1px solid #bbf7d0;background:#f0fdf4;color:#16a34a;border-radius:.45rem;font-size:.95rem;font-weight:800;cursor:pointer;}
    .dp-check:hover{background:#16a34a;color:#fff;}
    .dp-empty{text-align:center;color:#9ca3af;padding:3rem;}
</style>

@php
    $tipLabels = ['BON' => 'Bon', 'F' => 'Factură', 'AE' => 'Aviz'];
    $chips = ['' => 'Toate', 'depozit' => '📦 Depozit', 'livrare' => '🚚 Livrare'];
@endphp

<div class="dp-bar">
    @foreach($chips as $val => $label)
        <div class="dp-chip {{ $sursaSel === $val ? 'dp-chip--on' : '' }}" wire:click="$set('sursa', '{{ $val }}')">{{ $label }}</div>
    @endforeach
</div>

@forelse($documente as $doc)
    <div class="dp-card">
        <div class="dp-head">
            <div class="dp-head-l">
                <span class="dp-pill dp-{{ $doc['sursa'] }}" style="font-size:.7rem;">{{ strtoupper($doc['sursa']) }}</span>
                <span class="dp-badge">{{ $tipLabels[$doc['tip_doc']] ?? $doc['tip_doc'] }} #{{ $doc['nr_doc'] }}</span>
                <span class="dp-client">{{ $doc['client'] }}</span>
                <span style="font-size:.72rem;color:#9ca3af;">{{ $doc['nr_linii'] }} produse · {{ number_format($doc['total'],2,',','.') }} lei</span>
            </div>
            <button class="dp-done" wire:click="marcheazaPredat('{{ $doc['tip_doc'] }}', '{{ $doc['doc_id'] }}', '{{ $doc['sursa'] }}')">✓ {{ $doc['sursa']==='livrare' ? 'Livrat' : 'Predat / Încărcat' }}</button>
        </div>
        <table class="dp-table">
            <thead><tr><th>Produs</th><th class="r">Rămas</th><th class="r">Predă</th></tr></thead>
            <tbody>
                @foreach($doc['lines'] as $line)
                    @php $rest = rtrim(rtrim(number_format($line['rest'],3,',','.'),'0'),','); @endphp
                    <tr x-data="{ q: {{ $line['rest'] }} }">
                        <td>{{ $line['produs'] }} <span style="color:#9ca3af;font-size:.7rem;">{{ $line['art_id'] }}</span></td>
                        <td class="r">{{ $rest }}@if($line['cant_predata'] > 0) <span style="color:#9ca3af;font-size:.7rem;">din {{ rtrim(rtrim(number_format($line['cant_alocata'],3,',','.'),'0'),',') }}</span>@endif</td>
                        <td class="r">
                            <div style="display:flex;gap:.3rem;justify-content:flex-end;align-items:center;">
                                <input type="number" min="0" step="0.01" x-model.number="q" style="width:4rem;border:1px solid #d1d5db;border-radius:.4rem;padding:.25rem;text-align:center;">
                                <button class="dp-check" style="background:#0e7490;color:#fff;border-color:#0e7490;width:auto;padding:.25rem .6rem;" title="Predă cantitatea"
                                        @click="$wire.predaPartial('{{ $doc['tip_doc'] }}','{{ $doc['doc_id'] }}','{{ $line['pozitie'] }}','{{ $doc['sursa'] }}', q||0)">Predă</button>
                                <button class="dp-check" title="Predă tot restul"
                                        wire:click="marcheazaLinie('{{ $doc['tip_doc'] }}','{{ $doc['doc_id'] }}','{{ $line['pozitie'] }}','{{ $doc['sursa'] }}')">✓</button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@empty
    <div class="dp-empty">Nimic de predat {{ $sursaSel ? 'pe sursa aleasă' : 'momentan' }}. Confirmă documente în „Dispecerizare vânzări".</div>
@endforelse
</x-filament-panels::page>
