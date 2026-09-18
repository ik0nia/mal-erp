{{-- PILOT: info din serviciul unic de recomandare (acoperire, sezon, unitate achiziție) --}}
@if(!empty($product->calc_engine))
    @php
        $sp = isset($product->calc_season) ? (int) round(((float) $product->calc_season - 1) * 100) : 0;
        $sTxt = $sp > 5 ? '+' . $sp . '% sezon' : ($sp < -5 ? $sp . '% sezon' : 'sezon ~0');
        $sCol = $sp > 5 ? '#15803d' : ($sp < -5 ? '#b45309' : '#9ca3af');
    @endphp
    <span style="display:inline-flex;flex-wrap:wrap;gap:7px;margin-top:3px;font-size:0.68rem;align-items:center;line-height:1.4;">
        <span style="color:#6b7280;" title="Acoperire = lead {{ $product->calc_lead }}z + ciclu comandă">📅 {{ $product->calc_cover }}z</span>
        <span style="color:{{ $sCol }};font-weight:600;" title="Sezon pe fereastra de livrare ×{{ number_format((float) $product->calc_season, 2) }}">🗓 {{ $sTxt }}</span>
        @if(!empty($product->calc_purchase_uom) && !empty($product->calc_purchase_qty))
            <span style="color:#1d4ed8;font-weight:700;" title="În unitatea de achiziție">≈ {{ $product->calc_purchase_qty }} {{ $product->calc_purchase_uom }}</span>
        @endif
        @if(isset($product->calc_confidence) && $product->calc_confidence < 0.7)
            <span style="color:#b45309;" title="Recomandare cu date parțiale">⚠ încredere {{ (int) round($product->calc_confidence * 100) }}%</span>
        @endif
        @if(!empty($product->calc_flags) && in_array('suprastoc', $product->calc_flags))
            <span style="background:#fef3c7;color:#b45309;padding:0 6px;border-radius:999px;font-weight:700;">suprastoc</span>
        @endif
    </span>
@endif
