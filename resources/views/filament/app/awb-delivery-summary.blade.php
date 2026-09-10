<x-filament-widgets::widget>
    {{-- Sumar livrări Sameday — un singur card compact, toate metricile într-o bandă --}}
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px 16px;box-shadow:0 1px 2px rgba(0,0,0,.04)">
        <div style="display:flex;align-items:stretch;flex-wrap:wrap;gap:4px 0">

            @php
                $sep = 'border-left:1px solid #f3f4f6;';
                $cell = 'flex:1 1 110px;min-width:100px;padding:2px 14px;display:flex;flex-direction:column;justify-content:center;gap:1px;';
                $label = 'font-size:10.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;';
                $value = 'font-size:17px;font-weight:800;line-height:1.2;color:#111827;';
                $sub = 'font-size:10.5px;color:#9ca3af;white-space:nowrap;';
            @endphp

            <div style="{{ $cell }}">
                <span style="{{ $label }}">🚚 În livrare</span>
                <span style="{{ $value }}{{ $inDelivery > 0 ? 'color:#1d4ed8;' : '' }}">{{ $inDelivery }}</span>
                <span style="{{ $sub }}">{{ $oldestDays !== null ? 'cel mai vechi: ' . $oldestDays . ' ' . ($oldestDays == 1 ? 'zi' : 'zile') : 'niciun colet pe drum' }}</span>
            </div>

            <div style="{{ $cell }}{{ $sep }}">
                <span style="{{ $label }}">⏱ Medie livrare</span>
                <span style="{{ $value }}">{{ $avgText }}</span>
                <span style="{{ $sub }}">{{ $under48 !== null ? $under48 . '% sub 48h · 90 zile' : 'ultimele 90 zile' }}</span>
            </div>

            <div style="{{ $cell }}{{ $sep }}">
                <span style="{{ $label }}">✅ Livrate</span>
                <span style="{{ $value }}color:#047857;">{{ $delivered30 }}</span>
                <span style="{{ $sub }}">30 zile · {{ $delivered7 }} în ultimele 7</span>
            </div>

            <div style="{{ $cell }}{{ $sep }}">
                <span style="{{ $label }}">💰 Ramburs neîncasat</span>
                <span style="{{ $value }}{{ $codPendingC > 0 ? 'color:#b45309;' : 'color:#047857;' }}">{{ number_format($codPendingS, 0, ',', '.') }} lei</span>
                <span style="{{ $sub }}">{{ $codPendingC }} {{ $codPendingC == 1 ? 'colet livrat' : 'colete livrate' }}, banii pe drum</span>
            </div>

            <div style="{{ $cell }}{{ $sep }}">
                <span style="{{ $label }}">🏦 Ramburs încasat</span>
                <span style="{{ $value }}">{{ number_format($codCollected30, 0, ',', '.') }} lei</span>
                <span style="{{ $sub }}">transferat de Sameday · 30 zile</span>
            </div>

            <div style="{{ $cell }}{{ $sep }}">
                <span style="{{ $label }}">↩️ Retururi/refuzuri</span>
                <span style="{{ $value }}{{ $returns30 > 0 ? 'color:#b91c1c;' : '' }}">{{ $returns30 }}</span>
                <span style="{{ $sub }}">ultimele 30 zile</span>
            </div>

            <div style="{{ $cell }}{{ $sep }}">
                <span style="{{ $label }}">🔍 În urmărire</span>
                <span style="{{ $value }}">{{ $inTracking }}</span>
                <span style="{{ $sub }}">verificare automată la 30 min</span>
            </div>

        </div>
    </div>
</x-filament-widgets::widget>
