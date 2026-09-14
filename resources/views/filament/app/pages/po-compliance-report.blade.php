<x-filament-panels::page>
    @php
        $ov = $this->overview();
        $monthly = $this->monthlyWithoutPo();
        $stats = $this->getStats();
        $maxRec = max(array_map(fn($m)=>$m['receptii'], $monthly) ?: [1]);
    @endphp

    <div style="font-size:12px;color:#9aa5b1;margin-bottom:4px">Analiză de la începutul folosirii sistemului · {{ $ov['start']->locale('ro')->isoFormat('MMMM YYYY') }}</div>

    {{-- KPI hero — CANTITATIV (documente/proceduri) --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
        <div style="background:#fff;border:1px solid #eef2f7;border-radius:16px;padding:16px">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b1;font-weight:600">Recepții WinMentor</div>
            <div style="font-size:26px;font-weight:800;color:#111827">{{ number_format($ov['receptii'],0,',','.') }}</div>
            <div style="font-size:11px;color:#9aa5b1">documente de intrare</div>
        </div>
        <div style="background:linear-gradient(135deg,#fee2e2,#fff);border:1px solid #fecaca;border-radius:16px;padding:16px">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#b91c1c;font-weight:600">Recepții fără PO</div>
            <div style="font-size:28px;font-weight:800;color:#dc2626">{{ $ov['pctFaraCount'] }}%</div>
            <div style="font-size:11px;color:#b91c1c">{{ number_format(max($ov['receptii']-$ov['poReceived'],0),0,',','.') }} din {{ number_format($ov['receptii'],0,',','.') }} fără procedură</div>
        </div>
        <div style="background:#fff;border:1px solid #eef2f7;border-radius:16px;padding:16px">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b1;font-weight:600">Prin procedură (PO)</div>
            <div style="font-size:26px;font-weight:800;color:#059669">{{ $ov['poReceived'] }}</div>
            <div style="font-size:11px;color:#9aa5b1">PO-uri recepționate</div>
        </div>
        <div style="background:#fff;border:1px solid #eef2f7;border-radius:16px;padding:16px">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b1;font-weight:600">Abateri de flux</div>
            <div style="font-size:26px;font-weight:800;color:{{ $ov['anomalii'] > 0 ? '#b45309' : '#059669' }}">{{ $ov['anomalii'] }}</div>
            <div style="font-size:11px;color:#9aa5b1">PO-uri cu procedură nerespectată</div>
        </div>
    </div>

    {{-- Grafic recepții cu/fără PO pe luni (cantitativ) --}}
    <x-filament::section>
        <x-slot name="heading">Recepții cu / fără PO — pe lună (nr. documente)</x-slot>
        <x-slot name="description">Bara = nr. recepții WinMentor. Mov = câte au avut PO. Roșu = fără procedură. Procentul = % fără PO.</x-slot>
        <div style="display:flex;align-items:flex-end;gap:6px;height:190px;border-bottom:1px solid #eef2f7;padding-top:22px">
            @foreach ($monthly as $m)
                @php
                    $h = (int) round($m['receptii'] / $maxRec * 150);
                    $poH = $m['receptii'] > 0 ? (int) round(min($m['po_n'],$m['receptii']) / $m['receptii'] * $h) : 0;
                @endphp
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px;min-width:0">
                    <div style="font-size:10px;font-weight:700;color:#dc2626">{{ $m['pct_fara_count'] }}%</div>
                    <div title="{{ $m['ym'] }}: {{ $m['receptii'] }} recepții, {{ $m['po_n'] }} prin PO"
                         style="width:70%;max-width:34px;height:{{ max($h,3) }}px;background:#fee2e2;border-radius:5px 5px 0 0;display:flex;flex-direction:column;justify-content:flex-end">
                        <div style="height:{{ $poH }}px;background:linear-gradient(180deg,#8b5cf6,#6d28d9);border-radius:{{ $poH >= $h ? '5px 5px 0 0' : '0' }}"></div>
                    </div>
                    <div style="font-size:10px;color:#6b7280;white-space:nowrap">{{ $m['receptii'] }}</div>
                    <div style="font-size:9px;color:#9aa5b1;white-space:nowrap">{{ $m['label'] }}</div>
                </div>
            @endforeach
        </div>
        <div style="display:flex;gap:16px;margin-top:10px;font-size:11px;color:#6b7280">
            <span><span style="display:inline-block;width:10px;height:10px;background:#6d28d9;border-radius:2px"></span> prin PO</span>
            <span><span style="display:inline-block;width:10px;height:10px;background:#fee2e2;border-radius:2px"></span> fără PO</span>
        </div>
    </x-filament::section>

    {{-- Detaliere pe lună: ce documente / furnizori nu au avut PO (marfa a ajuns fără comandă) --}}
    @php $breakdown = $this->monthlySupplierBreakdown(); @endphp
    @if (! empty($breakdown))
        <x-filament::section>
            <x-slot name="heading">Detaliere pe lună — documente fără PO, per furnizor</x-slot>
            <x-slot name="description">Click pe o lună ca să vezi, pe furnizor, câte recepții (documente de intrare) au ajuns fără să se fi făcut comandă (PO) în prealabil — cazurile unde suspectăm că procedura nu a fost respectată.</x-slot>
            <div style="display:flex;flex-direction:column;gap:8px">
                @foreach ($breakdown as $mo)
                    <details style="border:1px solid #eef2f7;border-radius:12px;overflow:hidden;background:#fff">
                        <summary style="cursor:pointer;list-style:none;padding:12px 16px;display:flex;align-items:center;gap:12px;user-select:none">
                            <span style="font-size:11px;color:#9aa5b1">▸</span>
                            <span style="font-weight:700;color:#111827;text-transform:capitalize;flex:0 0 150px">{{ $mo['label'] }}</span>
                            <span style="flex:1;height:8px;border-radius:4px;background:#f3f4f6;overflow:hidden;min-width:0">
                                <span style="display:block;height:100%;width:{{ $mo['receptii'] > 0 ? round($mo['fara_po']/$mo['receptii']*100) : 0 }}%;background:linear-gradient(90deg,#f59e0b,#dc2626)"></span>
                            </span>
                            <span style="font-size:13px;font-weight:800;color:#dc2626;flex:0 0 auto">{{ $mo['fara_po'] }} <span style="color:#9aa5b1;font-weight:500">/ {{ $mo['receptii'] }} recepții fără PO</span></span>
                        </summary>
                        <div style="border-top:1px solid #f1f5f9;padding:4px 0">
                            <table style="width:100%;border-collapse:collapse;font-size:13px">
                                <thead>
                                    <tr style="color:#9aa5b1;font-size:11px;text-transform:uppercase;letter-spacing:.03em">
                                        <th style="text-align:left;padding:8px 16px;font-weight:600">Furnizor</th>
                                        <th style="text-align:left;padding:8px 12px;font-weight:600">Responsabil</th>
                                        <th style="text-align:right;padding:8px 12px;font-weight:600">Fără PO</th>
                                        <th style="text-align:right;padding:8px 16px;font-weight:600">Total recepții</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($mo['suppliers'] as $s)
                                        <tr style="border-top:1px solid #f8fafc">
                                            <td style="padding:8px 16px;color:#374151;font-weight:500">{{ ucwords(mb_strtolower($s['furnizor'])) }}</td>
                                            <td style="padding:8px 12px;color:{{ $s['responsabil'] ? '#6b7280' : '#dc2626' }}">👤 {{ $s['responsabil'] ?? 'neatribuit' }}</td>
                                            <td style="padding:8px 12px;text-align:right;font-weight:800;color:#dc2626">{{ $s['fara_po'] }}</td>
                                            <td style="padding:8px 16px;text-align:right;color:#9aa5b1">{{ $s['receptii'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Per cumpărător + tip abatere --}}
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
            <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;font-size:12px;color:#9aa5b1">
                @foreach ($stats['byType'] as $type => $count)
                    <span>{{ $type }}: <strong style="color:#374151">{{ $count }}</strong></span>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Top furnizori la care se cumpără fără PO --}}
    @php $topSup = $this->topSuppliersWithoutPo(); @endphp
    @if (! empty($topSup))
        <x-filament::section>
            <x-slot name="heading">Top furnizori — cumpărare fără PO</x-slot>
            <x-slot name="description">Furnizori cu cele mai multe recepții WinMentor fără comandă (PO) în sistem. Roșu = recepții fără procedură, verde = cu PO.</x-slot>
            <div style="display:flex;flex-direction:column;gap:10px">
                @foreach ($topSup as $s)
                    @php $poW = $s['receptii'] > 0 ? round(min($s['po'],$s['receptii']) / $s['receptii'] * 100) : 0; @endphp
                    <div style="display:flex;align-items:center;gap:12px">
                        <div style="flex:0 0 210px;min-width:0">
                            <div style="font-size:13px;font-weight:600;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="{{ $s['furnizor'] }}">{{ ucwords(mb_strtolower($s['furnizor'])) }}</div>
                            <div style="font-size:10px;color:{{ $s['responsabil'] ? '#6b7280' : '#dc2626' }};white-space:nowrap;overflow:hidden;text-overflow:ellipsis">👤 {{ $s['responsabil'] ?? 'neatribuit' }}</div>
                        </div>
                        <div style="flex:1;height:22px;border-radius:6px;overflow:hidden;display:flex;background:#f3f4f6;min-width:0">
                            @if ($poW > 0)
                                <div style="width:{{ $poW }}%;background:linear-gradient(90deg,#10b981,#059669)"></div>
                            @endif
                            <div style="flex:1;background:{{ $s['pct'] >= 90 ? 'linear-gradient(90deg,#ef4444,#dc2626)' : 'linear-gradient(90deg,#f59e0b,#d97706)' }}"></div>
                        </div>
                        <div style="flex:0 0 72px;text-align:right;font-size:13px;font-weight:800;color:{{ $s['pct'] >= 90 ? '#dc2626' : '#d97706' }}">{{ $s['pct'] }}%</div>
                        <div style="flex:0 0 120px;text-align:right;font-size:11px;color:#9aa5b1;white-space:nowrap">{{ $s['fara_po'] }} din {{ $s['receptii'] }} recepții</div>
                    </div>
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
