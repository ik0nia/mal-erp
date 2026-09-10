{{-- Timeline tracking Sameday — randat în modalul «Istoric» de pe secțiunea AWB --}}
<div style="max-height:60vh;overflow-y:auto">
    @if (!empty($error))
        <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c">
            {{ $error }}
        </div>
    @else
        @php
            $summary = $tracking['summary'] ?? [];
            $history = $tracking['history'] ?? [];
        @endphp

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px">
            @if (!empty($summary['delivered_at']))
                <span style="background:#ecfdf3;border:1px solid #a7f3d0;color:#047857;border-radius:999px;padding:4px 12px;font-size:13px;font-weight:700">✓ Livrat: {{ $summary['delivered_at'] }}</span>
            @endif
            @if ($awb->picked_up_at && $awb->delivered_at)
                @php $ore = $awb->picked_up_at->diffInHours($awb->delivered_at); @endphp
                <span style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:999px;padding:4px 12px;font-size:13px;font-weight:700">⏱ Durată livrare: {{ $ore < 48 ? $ore.' ore' : number_format($ore/24, 1, ',', '').' zile' }}</span>
            @endif
            @if ((float) $awb->cod_amount > 0)
                <span style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:999px;padding:4px 12px;font-size:13px;font-weight:700">Ramburs: {{ number_format((float) $awb->cod_amount, 2) }} RON</span>
            @endif
        </div>

        @if (empty($history))
            <p style="color:#6b7280">Fără evenimente încă.</p>
        @else
            <div style="position:relative;padding-left:22px">
                <div style="position:absolute;left:7px;top:6px;bottom:6px;width:2px;background:#e5e7eb"></div>
                @foreach ($history as $i => $event)
                    <div style="position:relative;padding:0 0 14px 0">
                        <span style="position:absolute;left:-22px;top:3px;width:12px;height:12px;border-radius:50%;{{ $i === 0 ? 'background:#c8102e;box-shadow:0 0 0 3px #fde3e5' : 'background:#d1d5db' }}"></span>
                        <div style="font-weight:{{ $i === 0 ? '700' : '600' }};font-size:13.5px;color:{{ $i === 0 ? '#111827' : '#374151' }}">
                            {{ $event['label'] ?? '?' }}
                        </div>
                        <div style="font-size:12px;color:#6b7280">
                            {{ $event['date'] ?? '' }}
                            @if (!empty($event['county'])) · {{ $event['county'] }} @endif
                            @if (!empty($event['transit'])) · {{ $event['transit'] }} @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
