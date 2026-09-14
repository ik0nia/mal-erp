<x-filament-panels::page>
    @php
        $ov = $this->overview();
        $monthly = $this->monthlyWithoutPo();
        $stats = $this->getStats();
        $fmtK = fn ($v) => $v >= 1000000 ? number_format($v/1000000,1,',','.').'M' : ($v >= 1000 ? number_format($v/1000,0,',','.').'k' : number_format($v,0,',','.'));
        $maxIntr = max(array_map(fn($m)=>$m['intrari'], $monthly) ?: [1]);
    @endphp

    {{-- KPI hero --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
        <div style="background:#fff;border:1px solid #eef2f7;border-radius:16px;padding:16px">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b1;font-weight:600">Achiziții {{ $ov['an'] }}</div>
            <div style="font-size:24px;font-weight:800;color:#111827">{{ $fmtK($ov['intrariVal']) }} lei</div>
            <div style="font-size:11px;color:#9aa5b1">intrări WinMentor</div>
        </div>
        <div style="background:linear-gradient(135deg,#fee2e2,#fff);border:1px solid #fecaca;border-radius:16px;padding:16px">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#b91c1c;font-weight:600">Fără PO deloc</div>
            <div style="font-size:28px;font-weight:800;color:#dc2626">{{ $ov['pctFaraPo'] }}%</div>
            <div style="font-size:11px;color:#b91c1c">din valoarea achizițiilor</div>
        </div>
        <div style="background:#fff;border:1px solid #eef2f7;border-radius:16px;padding:16px">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b1;font-weight:600">PO recepționate {{ $ov['an'] }}</div>
            <div style="font-size:24px;font-weight:800;color:#111827">{{ $ov['poReceived'] }}</div>
            <div style="font-size:11px;color:#9aa5b1">{{ $fmtK($ov['poVal']) }} lei prin PO</div>
        </div>
        <div style="background:#fff;border:1px solid #eef2f7;border-radius:16px;padding:16px">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b1;font-weight:600">Abateri de flux</div>
            <div style="font-size:24px;font-weight:800;color:{{ $ov['anomalii'] > 0 ? '#b45309' : '#059669' }}">{{ $ov['anomalii'] }}</div>
            <div style="font-size:11px;color:#9aa5b1">PO-uri cu procedură nerespectată</div>
        </div>
    </div>

    {{-- Grafic % fără PO pe luni --}}
    <x-filament::section>
        <x-slot name="heading">Achiziții fără PO — evoluție lunară</x-slot>
        <x-slot name="description">Bara = valoarea achizițiilor (intrări). Porțiunea mov = ce a trecut prin PO. Restul = fără PO.</x-slot>
        <div style="display:flex;align-items:flex-end;gap:6px;height:180px;border-bottom:1px solid #eef2f7;padding-top:20px">
            @foreach ($monthly as $m)
                @php
                    $h = (int) round($m['intrari'] / $maxIntr * 140);
                    $poH = $m['intrari'] > 0 ? (int) round($m['po'] / $m['intrari'] * $h) : 0;
                @endphp
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px;min-width:0">
                    <div style="font-size:10px;font-weight:700;color:#dc2626">{{ $m['pct_fara'] }}%</div>
                    <div title="{{ $m['ym'] }}: {{ number_format($m['intrari'],0,',','.') }} lei intrări, {{ number_format($m['po'],0,',','.') }} prin PO"
                         style="width:70%;max-width:34px;height:{{ max($h,3) }}px;background:#fee2e2;border-radius:5px 5px 0 0;position:relative;display:flex;flex-direction:column;justify-content:flex-end">
                        <div style="height:{{ $poH }}px;background:linear-gradient(180deg,#8b5cf6,#6d28d9);border-radius:{{ $poH >= $h ? '5px 5px 0 0' : '0' }}"></div>
                    </div>
                    <div style="font-size:9px;color:#6b7280;white-space:nowrap">{{ $m['label'] }}</div>
                </div>
            @endforeach
        </div>
        <div style="display:flex;gap:16px;margin-top:10px;font-size:11px;color:#6b7280">
            <span><span style="display:inline-block;width:10px;height:10px;background:#6d28d9;border-radius:2px"></span> prin PO</span>
            <span><span style="display:inline-block;width:10px;height:10px;background:#fee2e2;border-radius:2px"></span> fără PO</span>
        </div>
    </x-filament::section>

    {{-- Per cumpărător --}}
    @if (! empty($stats['byBuyer']))
        <x-filament::section>
            <x-slot name="heading">Abateri de flux per cumpărător</x-slot>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                @foreach ($stats['byBuyer'] as $name => $count)
                    <span style="display:inline-flex;align-items:center;gap:6px;background:#f9fafb;border:1px solid #eceff3;border-radius:9999px;padding:5px 14px;font-size:13px">
                        <strong>{{ $name }}</strong>
                        <span style="background:{{ $count >= 5 ? '#fee2e2' : '#eef2f7' }};color:{{ $count >= 5 ? '#b91c1c' : '#3e4c59' }};border-radius:9999px;padding:0 8px;font-weight:700">{{ $count }}</span>
                    </span>
                @endforeach
            </div>
            <div style="display:flex;gap:16px;margin-top:12px;font-size:12px;color:#9aa5b1">
                @foreach ($stats['byType'] as $type => $count)
                    <span>{{ $type }}: <strong style="color:#374151">{{ $count }}</strong></span>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Tabel abateri --}}
    <x-filament::section>
        <x-slot name="heading">PO-uri cu abateri de procedură</x-slot>
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
