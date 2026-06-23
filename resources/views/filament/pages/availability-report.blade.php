<x-filament-panels::page>

    {{-- Selector lună --}}
    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.5rem;">
        <span style="font-size:0.875rem; font-weight:500; color:#374151;">Luna:</span>
        <select wire:model.live="month"
            style="padding:0.4rem 0.75rem; border-radius:0.5rem; border:1px solid #d1d5db; font-size:0.875rem; background:#fff;">
            @foreach($this->getMonthOptions() as $val => $label)
                <option value="{{ $val }}">{{ ucfirst($label) }}</option>
            @endforeach
        </select>
    </div>

    @php $report = $this->getReport(); @endphp

    <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:1rem; margin-bottom:1rem;">
        @foreach($report as $target => $r)
            <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.1); padding:1.25rem; border:1px solid #e5e7eb;">
                <p style="font-size:0.8rem; color:#6b7280; margin:0 0 0.5rem; font-weight:600;">{{ $r['label'] }}</p>

                @if($r['total'] === 0)
                    <p style="font-size:1.1rem; color:#9ca3af; margin:0;">Fără date pentru această lună</p>
                @else
                    <p style="font-size:2.2rem; font-weight:800; margin:0; color:{{ $r['pct'] >= 99 ? '#16a34a' : ($r['pct'] >= 95 ? '#d97706' : '#dc2626') }};">
                        {{ number_format($r['pct'], 3) }}%
                    </p>
                    <p style="font-size:0.75rem; color:#6b7280; margin:0.25rem 0 0.75rem;">
                        {{ number_format($r['up']) }} / {{ number_format($r['total']) }} sonde reușite
                        @if($r['avg_ms']) · răspuns mediu {{ $r['avg_ms'] }}ms @endif
                        @if($r['down'] > 0) · <span style="color:#dc2626;">{{ $r['down'] }} eșuate</span> @endif
                    </p>

                    @if($r['credit'])
                        <span style="display:inline-block; padding:0.25rem 0.7rem; border-radius:9999px; font-size:0.8rem; font-weight:600;
                            background:{{ $r['credit']['color'] === 'success' ? '#dcfce7' : ($r['credit']['color'] === 'danger' ? '#fee2e2' : '#fef3c7') }};
                            color:{{ $r['credit']['color'] === 'success' ? '#166534' : ($r['credit']['color'] === 'danger' ? '#991b1b' : '#92400e') }};">
                            {{ $r['credit']['label'] }}
                            @if($r['credit']['credit'] > 0) — credit datorat: {{ $r['credit']['credit'] }}% din abonamentul lunar de găzduire @endif
                        </span>
                    @else
                        <span style="font-size:0.75rem; color:#9ca3af;">(informativ — fără credit asociat)</span>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    {{-- Incidente (sonde eșuate) --}}
    @foreach($report as $target => $r)
        @if($r['incidents']->isNotEmpty())
            <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.1); padding:1rem 1.25rem; border:1px solid #e5e7eb; margin-bottom:1rem;">
                <p style="font-size:0.8rem; font-weight:600; color:#374151; margin:0 0 0.5rem;">Ultimele indisponibilități — {{ $r['label'] }}</p>
                <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
                    <thead><tr style="text-align:left; color:#6b7280;">
                        <th style="padding:0.3rem 0.5rem;">Moment</th>
                        <th style="padding:0.3rem 0.5rem;">Durată probă</th>
                        <th style="padding:0.3rem 0.5rem;">Eroare</th>
                    </tr></thead>
                    <tbody>
                    @foreach($r['incidents'] as $inc)
                        <tr style="border-top:1px solid #f3f4f6;">
                            <td style="padding:0.3rem 0.5rem;">{{ $inc->checked_at->format('d.m.Y H:i') }}</td>
                            <td style="padding:0.3rem 0.5rem;">{{ $inc->response_ms }}ms</td>
                            <td style="padding:0.3rem 0.5rem; color:#dc2626;">{{ $inc->error ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach

    <p style="font-size:0.75rem; color:#9ca3af; margin-top:0.5rem;">
        Sonde la fiecare 5 minute. Disponibilitatea țintă pentru „Aplicație" este 99%/lună (contract Art. 8.9); pragurile de credit: sub 99% → 5%, sub 97% → 10%, sub 95% → 20% din componenta lunară de găzduire. Nota: sonda rulează pe acest server; o cădere totală de infrastructură nu este capturată (recomandat și un monitor extern).
    </p>

</x-filament-panels::page>
