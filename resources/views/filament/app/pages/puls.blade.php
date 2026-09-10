<x-filament-panels::page>
<div wire:poll.120s>
@php
    $lei = fn ($n) => number_format((float) $n, 0, ',', '.');
    $delta = fn ($now, $prev) => $prev <= 0 ? null : (int) round(($now - $prev) / $prev * 100);
    $card = 'background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)';
    $label = 'font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px';
    $big = 'font-size:25px;font-weight:800;color:#111827;line-height:1.1';
    $chip = fn ($d) => $d === null ? '' :
        '<span style="font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:8px;vertical-align:middle;' .
        ($d >= 0 ? 'background:#ecfdf3;color:#047857' : 'background:#fef2f2;color:#b91c1c') . '">' .
        ($d >= 0 ? '▲' : '▼') . ' ' . abs($d) . '%</span>';
    $btnP = fn ($activ) => 'padding:4px 12px;font-size:12px;font-weight:600;border-radius:999px;cursor:pointer;border:1px solid ' .
        ($activ ? '#c8102e;background:#c8102e;color:#fff' : '#e5e7eb;background:#fff;color:#6b7280');
    $maxZi = max(array_values($zileGrafic)) ?: 1;
    $statusColor = ['processing' => 'background:#fffbeb;color:#b45309', 'completed' => 'background:#ecfdf3;color:#047857',
        'cancelled' => 'background:#f3f4f6;color:#6b7280', 'refunded' => 'background:#fef2f2;color:#b91c1c'];
@endphp

<style>
    .puls-bar { transition: opacity .15s; cursor: pointer; }
    .puls-bar:hover { opacity: .65; }
    .puls-row { cursor: pointer; transition: background .12s; }
    .puls-row:hover td { background: #fef7f7 !important; }
    @keyframes pulsDot { 0%,100% { opacity: 1 } 50% { opacity: .3 } }
    .puls-live { animation: pulsDot 2s infinite; }
    [wire\:loading] { transition: opacity .2s; }
</style>

{{-- antet live --}}
<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
    <span style="display:inline-flex;align-items:center;gap:6px;background:#ecfdf3;border:1px solid #a7f3d0;color:#047857;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:999px">
        <span class="puls-live" style="width:7px;height:7px;border-radius:50%;background:#10b981;display:inline-block"></span> LIVE — date reale, refresh automat
    </span>
    <span wire:loading style="font-size:11.5px;color:#c8102e;font-weight:600">se actualizează…</span>
</div>

{{-- rând 1: banii --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:14px">
    <div style="{{ $card }}">
        <div style="{{ $label }}">Vânzări azi (WinMentor)</div>
        <div style="{{ $big }}">{{ $lei($aziLei) }} lei{!! $chip($delta($aziLei, $ieriLei)) !!}</div>
        <div style="font-size:12px;color:#9ca3af;margin-top:3px">{{ $aziDoc }} documente · ieri: {{ $lei($ieriLei) }} lei</div>
        <div style="font-size:11px;color:#6b7280;margin-top:4px;display:flex;gap:10px;flex-wrap:wrap">
            <span>🧾 bonuri <b>{{ $lei($aziTipuri['S'] ?? 0) }}</b></span>
            <span>🚚 avize <b>{{ $lei($aziTipuri['AE'] ?? 0) }}</b></span>
            <span>📄 facturi <b>{{ $lei($aziTipuri['F'] ?? 0) }}</b></span>
        </div>
    </div>
    <div style="{{ $card }}">
        <div style="{{ $label }}">💵 Încasat (bani intrați)</div>
        <div style="{{ $big }}">{{ $lei($incasariAzi) }} lei</div>
        <div style="font-size:12px;color:#9ca3af;margin-top:3px">azi · luna curentă: <b style="color:#374151">{{ $lei($incasariLuna) }} lei</b></div>
    </div>
    <div style="{{ $card }}">
        <div style="{{ $label }}">Săptămâna curentă</div>
        <div style="{{ $big }}">{{ $lei($saptLei) }} lei{!! $chip($delta($saptLei, $saptTrecutaLei)) !!}</div>
        <div style="font-size:12px;color:#9ca3af;margin-top:3px">săpt. trecută (întreagă): {{ $lei($saptTrecutaLei) }} lei</div>
    </div>
    <div style="{{ $card }}">
        <div style="{{ $label }}">Luna curentă</div>
        <div style="{{ $big }}">{{ $lei($lunaLei) }} lei{!! $chip($delta($lunaLei, $lunaTrecutaLaZiLei)) !!}</div>
        <div style="font-size:12px;color:#9ca3af;margin-top:3px">luna trecută la aceeași zi: {{ $lei($lunaTrecutaLaZiLei) }} lei</div>
    </div>
    <div style="{{ $card }}">
        <div style="{{ $label }}">Online azi</div>
        <div style="{{ $big }}">{{ $onlineAziC }} <span style="font-size:15px;color:#6b7280">comenzi</span></div>
        <div style="font-size:12px;color:#9ca3af;margin-top:3px">{{ $lei($onlineAziLei) }} lei ·
            <a href="{{ \App\Filament\App\Resources\WooOrderResource::getUrl('index') }}" style="color:#c8102e;font-weight:600">{{ $procesare }} în procesare →</a>
        </div>
    </div>
    <div style="{{ $card }}">
        <div style="{{ $label }}">Livrări Sameday</div>
        <div style="{{ $big }}">{{ $inLivrare }} <span style="font-size:15px;color:#6b7280">pe drum</span></div>
        <div style="font-size:12px;color:#9ca3af;margin-top:3px">medie: {{ $avgLivrare ?? '—' }} ·
            @if ($codPendingC > 0)<span style="color:#b45309;font-weight:600">{{ $lei($codPendingS) }} lei ramburs pe drum</span>@else ramburs la zi ✓ @endif
        </div>
    </div>
</div>

{{-- rând 2: grafic 30 zile, clickabil --}}
<div style="{{ $card }};margin-top:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div style="{{ $label }};margin:0">📈 Vânzări pe zi — ultimele 30 de zile <span style="text-transform:none;color:#9ca3af">(click pe o zi pentru detaliu)</span></div>
        <div style="font-size:11.5px;color:#9ca3af">vârf: {{ $lei($maxZi) }} lei</div>
    </div>
    @php
        // media mobilă pe 7 zile — puncte pentru polilinia de tendință
        $vals = array_values($zileGrafic);
        $ma = [];
        foreach ($vals as $i => $v) {
            $win = array_slice($vals, max(0, $i - 6), min(7, $i + 1));
            $ma[] = array_sum($win) / max(1, count($win));
        }
        $n = count($vals);
        $pts = collect($ma)->map(fn ($v, $i) => round(($i + 0.5) / $n * 1000, 1) . ',' . round(100 - min(100, $v / $maxZi * 100), 1))->implode(' ');
    @endphp
    <div style="position:relative;margin-top:10px">
        <div style="display:flex;align-items:flex-end;gap:3px;height:110px">
            @foreach ($zileGrafic as $zi => $val)
                @php
                    $h = max(3, (int) round($val / $maxZi * 100));
                    $e = \Carbon\Carbon::parse($zi);
                    $weekend = $e->isWeekend();
                    $activa = $ziSelectata === $zi;
                @endphp
                <div wire:click="selecteazaZi('{{ $zi }}')" class="puls-bar" title="{{ $e->format('d.m') }} — {{ $lei($val) }} lei"
                    style="flex:1;height:{{ $h }}%;border-radius:3px 3px 0 0;{{ $activa ? 'background:#c8102e' : ($weekend ? 'background:#f3cfd4' : 'background:#e88f9b') }}"></div>
            @endforeach
        </div>
        {{-- tendința: medie mobilă 7 zile --}}
        <svg viewBox="0 0 1000 100" preserveAspectRatio="none" style="position:absolute;inset:0;width:100%;height:110px;pointer-events:none">
            <polyline points="{{ $pts }}" fill="none" stroke="#111827" stroke-width="2" stroke-dasharray="5,4" opacity=".55" vector-effect="non-scaling-stroke"/>
        </svg>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:10.5px;color:#9ca3af;margin-top:4px">
        <span>{{ \Carbon\Carbon::parse(array_key_first($zileGrafic))->format('d.m') }}</span>
        <span>azi</span>
    </div>

    @if ($ziSelectata && $detaliuZi)
        <div style="margin-top:12px;border-top:1px dashed #e5e7eb;padding-top:12px">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div style="font-size:13px;font-weight:700;color:#111827">
                    {{ \Carbon\Carbon::parse($ziSelectata)->translatedFormat('l, d.m.Y') }} —
                    {{ $lei($detaliuZi['total']->lei ?? 0) }} lei · {{ $detaliuZi['total']->documente ?? 0 }} documente
                </div>
                <button wire:click="selecteazaZi(null)" style="font-size:11.5px;color:#6b7280;background:none;border:none;cursor:pointer">✕ închide</button>
            </div>
            <table style="width:100%;border-collapse:collapse;margin-top:6px">
                @foreach ($detaliuZi['topProduse'] as $p)
                    <tr>
                        <td style="padding:4px 6px 4px 0;border-bottom:1px solid #f3f4f6;font-size:12.5px;color:#1f2937">{{ \Illuminate\Support\Str::limit($p->den_articol, 60) }}</td>
                        <td style="padding:4px 0;border-bottom:1px solid #f3f4f6;font-size:12px;color:#6b7280;text-align:right;white-space:nowrap">{{ $lei($p->buc) }} buc</td>
                        <td style="padding:4px 0 4px 10px;border-bottom:1px solid #f3f4f6;font-size:12.5px;font-weight:700;text-align:right;white-space:nowrap">{{ $lei($p->lei) }} lei</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
</div>

{{-- rând 3: topuri cu perioadă comutabilă --}}
<div style="display:flex;align-items:center;gap:8px;margin-top:16px;flex-wrap:wrap">
    <span style="{{ $label }};margin:0">Perioada topurilor:</span>
    @foreach ([7 => '7 zile', 30 => '30 zile', 90 => '90 zile'] as $z => $txt)
        <button wire:click="setPerioada({{ $z }})" style="{{ $btnP($perioada === $z) }}">{{ $txt }}</button>
    @endforeach
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:14px;margin-top:10px">
    <div style="{{ $card }}">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="{{ $label }};margin:0">🏆 Top produse — {{ $perioada }} zile</div>
            <div style="display:flex;gap:6px">
                <button wire:click="setSortTop('lei')" style="{{ $btnP($sortTop === 'lei') }}">după lei</button>
                <button wire:click="setSortTop('buc')" style="{{ $btnP($sortTop === 'buc') }}">după buc</button>
            </div>
        </div>
        <table style="width:100%;border-collapse:collapse;margin-top:8px">
            <tr>
                <th style="font-size:10px;color:#9ca3af;text-align:left;font-weight:600;padding-bottom:2px"></th>
                <th style="font-size:10px;color:#9ca3af;text-align:left;font-weight:600">PRODUS</th>
                <th style="font-size:10px;color:#9ca3af;text-align:right;font-weight:600">VÂNDUT</th>
                <th style="font-size:10px;color:#9ca3af;text-align:right;font-weight:600">VALOARE</th>
                <th style="font-size:10px;color:#9ca3af;text-align:right;font-weight:600">STOC ACUM</th>
            </tr>
            @forelse ($topProduse as $i => $p)
                <tr>
                    <td style="padding:5px 4px 5px 0;border-bottom:1px solid #f3f4f6;font-size:12px;color:#9ca3af">{{ $i + 1 }}</td>
                    <td style="padding:5px 6px;border-bottom:1px solid #f3f4f6;font-size:12.5px;color:#1f2937">{{ \Illuminate\Support\Str::limit($p->den_articol, 42) }}</td>
                    <td style="padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:12px;color:#6b7280;text-align:right;white-space:nowrap">{{ $lei($p->buc) }}</td>
                    <td style="padding:5px 0 5px 8px;border-bottom:1px solid #f3f4f6;font-size:12.5px;font-weight:700;text-align:right;white-space:nowrap">{{ $lei($p->lei) }}</td>
                    <td style="padding:5px 0 5px 8px;border-bottom:1px solid #f3f4f6;text-align:right;white-space:nowrap">
                        @if ($p->stoc === null)
                            <span style="font-size:11px;color:#9ca3af">—</span>
                        @elseif ((float) $p->stoc <= 0)
                            <span style="font-size:11.5px;font-weight:700;color:#b91c1c;background:#fef2f2;padding:1px 7px;border-radius:999px">0 ⚠</span>
                        @elseif ((float) $p->stoc < (float) $p->buc / max($perioada, 1) * 7)
                            <span style="font-size:11.5px;font-weight:700;color:#b45309;background:#fffbeb;padding:1px 7px;border-radius:999px">{{ $lei($p->stoc) }} ↓</span>
                        @else
                            <span style="font-size:11.5px;font-weight:700;color:#047857">{{ $lei($p->stoc) }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="color:#9ca3af;font-size:13px;padding:8px 0">Fără vânzări în perioadă.</td></tr>
            @endforelse
        </table>
        <div style="font-size:10.5px;color:#9ca3af;margin-top:6px">Stoc = total ERP pe toate locațiile, în timp real. ⚠ = zero · ↓ = sub media de vânzare pe o săptămână.</div>
    </div>

    <div style="{{ $card }}">
        <div style="{{ $label }}">🤝 Top clienți — {{ $perioada }} zile <span style="text-transform:none;color:#9ca3af">(click pentru facturi)</span></div>
        <table style="width:100%;border-collapse:collapse;margin-top:8px">
            @forelse ($topClienti as $i => $c)
                <tr wire:click="selecteazaClient('{{ addslashes($c->denumire) }}')" class="puls-row">
                    <td style="padding:5px 4px 5px 0;border-bottom:1px solid #f3f4f6;font-size:12px;color:#9ca3af;width:18px">{{ $i + 1 }}</td>
                    <td style="padding:5px 6px;border-bottom:1px solid #f3f4f6;font-size:12.5px;color:#1f2937;{{ $clientSelectat === $c->denumire ? 'font-weight:700;color:#c8102e' : '' }}">{{ \Illuminate\Support\Str::limit($c->denumire, 40) }}</td>
                    <td style="padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:12px;color:#6b7280;text-align:right;white-space:nowrap">{{ $c->facturi }} fact.</td>
                    <td style="padding:5px 0 5px 8px;border-bottom:1px solid #f3f4f6;font-size:12.5px;font-weight:700;text-align:right;white-space:nowrap">{{ $lei($c->lei) }} lei</td>
                </tr>
                @if ($clientSelectat === $c->denumire && $detaliuClient !== null)
                    <tr><td colspan="4" style="padding:6px 0 10px;border-bottom:1px solid #f3f4f6">
                        <div style="background:#f9fafb;border-radius:8px;padding:8px 10px">
                            <div style="font-size:11px;color:#6b7280;margin-bottom:4px">Ultimele facturi (90 zile):</div>
                            @forelse ($detaliuClient as $f)
                                <div style="display:flex;justify-content:space-between;font-size:12px;padding:2px 0">
                                    <span style="color:#374151">{{ $f->data_fact }} · fact. {{ $f->nr_factura }} <span style="color:#9ca3af">({{ $f->linii }} linii)</span></span>
                                    <b>{{ $lei($f->lei) }} lei</b>
                                </div>
                            @empty
                                <div style="font-size:12px;color:#9ca3af">Fără facturi în 90 zile.</div>
                            @endforelse
                        </div>
                    </td></tr>
                @endif
            @empty
                <tr><td style="color:#9ca3af;font-size:13px;padding:8px 0">Fără clienți PJ în perioadă.</td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- rând 4: alerte + comenzi online recente --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:14px;margin-top:14px">
    <div style="{{ $card }}">
        <div style="{{ $label }}">⚠️ Se vând, dar stoc 0 în ERP — 14 zile</div>
        <table style="width:100%;border-collapse:collapse;margin-top:6px">
            @forelse ($alerteStoc as $p)
                <tr>
                    <td style="padding:5px 6px 5px 0;border-bottom:1px solid #f3f4f6;font-size:12.5px">
                        <a href="{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $p->pid]) }}" style="color:#1f2937">{{ \Illuminate\Support\Str::limit($p->name, 44) }}</a>
                    </td>
                    <td style="padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:12px;color:#6b7280;text-align:right;white-space:nowrap">{{ $lei($p->buc_14z) }} buc</td>
                    <td style="padding:5px 0 5px 8px;border-bottom:1px solid #f3f4f6;font-size:12.5px;font-weight:700;color:#b45309;text-align:right;white-space:nowrap">{{ $lei($p->lei_14z) }} lei</td>
                </tr>
            @empty
                <tr><td style="color:#047857;font-size:13px;padding:8px 0">✓ Nimic critic — tot ce se vinde are stoc.</td></tr>
            @endforelse
        </table>
    </div>

    <div style="{{ $card }}">
        <div style="{{ $label }}">🛒 Ultimele comenzi online</div>
        <table style="width:100%;border-collapse:collapse;margin-top:6px">
            @forelse ($comenziRecente as $o)
                @php
                    $b = is_string($o->billing) ? json_decode($o->billing, true) : (array) $o->billing;
                    $client = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: ($b['company'] ?? '—');
                @endphp
                <tr class="puls-row" onclick="window.location='{{ \App\Filament\App\Resources\WooOrderResource::getUrl('view', ['record' => $o->id]) }}'">
                    <td style="padding:5px 6px 5px 0;border-bottom:1px solid #f3f4f6;font-size:12.5px;font-weight:600;color:#1f2937;white-space:nowrap">#{{ $o->number }}</td>
                    <td style="padding:5px 6px;border-bottom:1px solid #f3f4f6;font-size:12.5px;color:#374151">{{ \Illuminate\Support\Str::limit($client, 24) }}</td>
                    <td style="padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:11px;color:#9ca3af;white-space:nowrap">{{ $o->order_date ? \Carbon\Carbon::parse($o->order_date)->format('d.m H:i') : '' }}</td>
                    <td style="padding:5px 0 5px 6px;border-bottom:1px solid #f3f4f6;text-align:right"><span style="font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:999px;{{ $statusColor[$o->status] ?? 'background:#f3f4f6;color:#6b7280' }}">{{ $o->status }}</span></td>
                    <td style="padding:5px 0 5px 8px;border-bottom:1px solid #f3f4f6;font-size:12.5px;font-weight:700;text-align:right;white-space:nowrap">{{ $lei($o->total) }} lei</td>
                </tr>
            @empty
                <tr><td style="color:#9ca3af;font-size:13px;padding:8px 0">Nicio comandă încă.</td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- grafic lunar: anul curent vs anul trecut --}}
@php
    $anCurent = now()->year;
    $anTrecut = $anCurent - 1;
    $maxLuna = 1;
    foreach ($luniAn as $g) { $maxLuna = max($maxLuna, $g[$anCurent] ?? 0, $g[$anTrecut] ?? 0); }
    $numeLuni = ['ian','feb','mar','apr','mai','iun','iul','aug','sep','oct','nov','dec'];
@endphp
<div style="{{ $card }};margin-top:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div style="{{ $label }};margin:0">📊 Vânzări lunare — {{ $anCurent }} vs {{ $anTrecut }}</div>
        <div style="display:flex;gap:14px;align-items:center;font-size:11.5px;color:#6b7280">
            <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#c8102e;vertical-align:-1px"></span> {{ $anCurent }}: <b>{{ $lei($ytd) }} lei</b> la zi{!! $chip($delta($ytd, $ytdTrecut)) !!}</span>
            <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#d1d5db;vertical-align:-1px"></span> {{ $anTrecut }} la aceeași zi: <b>{{ $lei($ytdTrecut) }} lei</b></span>
        </div>
    </div>
    <div style="position:relative;margin-top:12px">
        <div style="display:flex;align-items:flex-end;gap:10px;height:130px">
            @for ($l = 1; $l <= 12; $l++)
                @php
                    $vC = $luniAn[$l][$anCurent] ?? 0;
                    $vT = $luniAn[$l][$anTrecut] ?? 0;
                    $hC = $vC > 0 ? max(3, (int) round($vC / $maxLuna * 100)) : 0;
                    $hT = $vT > 0 ? max(3, (int) round($vT / $maxLuna * 100)) : 0;
                    $lunaViitoare = $l > now()->month;
                    $activa = $lunaSelectata === $l;
                @endphp
                @php $pc = (! $lunaViitoare && $vC > 0) ? $delta($vC, $vT) : null; @endphp
                <div wire:click="selecteazaLuna({{ $l }})" class="puls-bar" style="flex:1;display:flex;flex-direction:column;height:100%;justify-content:flex-end;{{ $activa ? 'background:#fef7f7;border-radius:6px' : '' }}">
                    <div style="height:13px;text-align:center;font-size:9.5px;font-weight:700;{{ $pc === null ? '' : ($pc >= 0 ? 'color:#047857' : 'color:#b91c1c') }}">
                        {{ $pc === null ? '' : (($pc >= 0 ? '+' : '−') . abs($pc) . '%') }}
                    </div>
                    <div style="display:flex;align-items:flex-end;gap:2px;flex:1">
                        <div title="{{ $numeLuni[$l-1] }} {{ $anTrecut }} — {{ $lei($vT) }} lei" style="flex:1;height:{{ $hT }}%;background:#d1d5db;border-radius:3px 3px 0 0"></div>
                        <div title="{{ $numeLuni[$l-1] }} {{ $anCurent }} — {{ $lei($vC) }} lei" style="flex:1;height:{{ $hC }}%;background:{{ $l === (int) now()->month ? '#c8102e' : '#e0637a' }};border-radius:3px 3px 0 0;{{ $lunaViitoare ? 'opacity:.25' : '' }}"></div>
                    </div>
                    <div style="text-align:center;font-size:10px;color:{{ $activa ? '#c8102e' : ($l === (int) now()->month ? '#c8102e' : '#9ca3af') }};font-weight:{{ $activa || $l === (int) now()->month ? '700' : '400' }};margin-top:3px">{{ $numeLuni[$l-1] }}</div>
                </div>
            @endfor
        </div>
    </div>

    @if ($lunaSelectata && $detaliuLuna)
        @php
            $lc = (float) ($detaliuLuna['curent']->lei ?? 0);
            $lt = (float) ($detaliuLuna['trecut']->lei ?? 0);
        @endphp
        <div style="margin-top:12px;border-top:1px dashed #e5e7eb;padding-top:12px">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div style="font-size:13.5px;font-weight:700;color:#111827;text-transform:capitalize">{{ $numeLuni[$lunaSelectata-1] }} — comparație pe ani</div>
                <button wire:click="selecteazaLuna(null)" style="font-size:11.5px;color:#6b7280;background:none;border:none;cursor:pointer">✕ închide</button>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:8px">
                <div style="background:#fef7f7;border:1px solid #f3cfd4;border-radius:10px;padding:10px 14px">
                    <div style="font-size:11px;color:#c8102e;font-weight:700">{{ $numeLuni[$lunaSelectata-1] }} {{ $anCurent }}</div>
                    <div style="font-size:20px;font-weight:800;color:#111827">{{ $lei($lc) }} lei{!! $chip($delta($lc, $lt)) !!}</div>
                    <div style="font-size:11.5px;color:#9ca3af">{{ $detaliuLuna['curent']->documente ?? 0 }} documente</div>
                </div>
                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px">
                    <div style="font-size:11px;color:#6b7280;font-weight:700">{{ $numeLuni[$lunaSelectata-1] }} {{ $anTrecut }}</div>
                    <div style="font-size:20px;font-weight:800;color:#374151">{{ $lei($lt) }} lei</div>
                    <div style="font-size:11.5px;color:#9ca3af">{{ $detaliuLuna['trecut']->documente ?? 0 }} documente</div>
                </div>
                <div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px">
                    <div style="font-size:11px;color:#6b7280;font-weight:700">DIFERENȚA</div>
                    <div style="font-size:20px;font-weight:800;color:{{ $lc >= $lt ? '#047857' : '#b91c1c' }}">{{ $lc >= $lt ? '+' : '−' }}{{ $lei(abs($lc - $lt)) }} lei</div>
                    <div style="font-size:11.5px;color:#9ca3af">{{ $anCurent }} față de {{ $anTrecut }}</div>
                </div>
            </div>
            @if (count($detaliuLuna['topProduse']))
                <div style="font-size:11px;color:#6b7280;margin:10px 0 2px;font-weight:600">Top produse {{ $numeLuni[$lunaSelectata-1] }} {{ $anCurent }}:</div>
                <table style="width:100%;border-collapse:collapse">
                    @foreach ($detaliuLuna['topProduse'] as $p)
                        <tr>
                            <td style="padding:4px 6px 4px 0;border-bottom:1px solid #f3f4f6;font-size:12.5px;color:#1f2937">{{ \Illuminate\Support\Str::limit($p->den_articol, 60) }}</td>
                            <td style="padding:4px 0;border-bottom:1px solid #f3f4f6;font-size:12px;color:#6b7280;text-align:right;white-space:nowrap">{{ $lei($p->buc) }} buc</td>
                            <td style="padding:4px 0 4px 10px;border-bottom:1px solid #f3f4f6;font-size:12.5px;font-weight:700;text-align:right;white-space:nowrap">{{ $lei($p->lei) }} lei</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endif
</div>

{{-- tendința lunară pe ultimii 4 ani --}}
@php
    $aniTrend = range($anCurent - 3, $anCurent);
    $culoriTrend = [$anCurent => ['#c8102e', 3, ''], $anCurent - 1 => ['#374151', 2, ''], $anCurent - 2 => ['#9ca3af', 2, '6,4'], $anCurent - 3 => ['#d4d4d8', 2, '6,4']];
    $maxTrend = 1;
    foreach ($luniAn as $g) foreach ($aniTrend as $a) $maxTrend = max($maxTrend, $g[$a] ?? 0);
    $liniiTrend = [];
    $totaluriTrend = [];
    foreach ($aniTrend as $a) {
        $pts = [];
        $totaluriTrend[$a] = 0;
        for ($l = 1; $l <= 12; $l++) {
            $v = $luniAn[$l][$a] ?? 0;
            $totaluriTrend[$a] += $v;
            if ($v > 0 && ! ($a === $anCurent && $l > (int) now()->month)) {
                $pts[] = round(($l - 0.5) / 12 * 1000, 1) . ',' . round(100 - min(100, $v / $maxTrend * 100), 1);
            }
        }
        $liniiTrend[$a] = implode(' ', $pts);
    }
@endphp
<div style="{{ $card }};margin-top:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div style="{{ $label }};margin:0">📉 Tendința lunară — ultimii 4 ani</div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:11.5px;color:#6b7280">
            @foreach (array_reverse($aniTrend) as $a)
                <span><span style="display:inline-block;width:14px;height:3px;border-radius:2px;background:{{ $culoriTrend[$a][0] }};vertical-align:3px"></span>
                    <b style="color:{{ $a === $anCurent ? '#c8102e' : '#374151' }}">{{ $a }}</b> · {{ number_format($totaluriTrend[$a] / 1000000, 1, ',', '') }} mil.{{ $a === $anCurent ? ' (la zi)' : '' }}</span>
            @endforeach
        </div>
    </div>
    <div style="position:relative;height:170px;margin-top:12px">
        {{-- gridlines orizontale cu valori --}}
        @foreach ([0.25, 0.5, 0.75] as $g)
            <div style="position:absolute;left:0;right:0;top:{{ $g * 100 }}%;border-top:1px dashed #f3f4f6"></div>
            <span style="position:absolute;right:2px;top:calc({{ $g * 100 }}% - 14px);font-size:9.5px;color:#d1d5db">{{ number_format($maxTrend * (1 - $g) / 1000000, 1, ',', '') }} mil.</span>
        @endforeach
        <svg viewBox="0 0 1000 100" preserveAspectRatio="none" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none">
            @foreach ($aniTrend as $a)
                <polyline points="{{ $liniiTrend[$a] }}" fill="none" stroke="{{ $culoriTrend[$a][0] }}" stroke-width="{{ $culoriTrend[$a][1] }}"
                    @if($culoriTrend[$a][2]) stroke-dasharray="{{ $culoriTrend[$a][2] }}" @endif
                    opacity="{{ $a === $anCurent ? '.9' : '.65' }}" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
            @endforeach
        </svg>
        {{-- puncte cu tooltip pe anul curent --}}
        @for ($l = 1; $l <= (int) now()->month; $l++)
            @php $v = $luniAn[$l][$anCurent] ?? 0; @endphp
            @if ($v > 0)
                <div title="{{ $numeLuni[$l-1] }} {{ $anCurent }} — {{ $lei($v) }} lei"
                    style="position:absolute;left:calc({{ round(($l - 0.5) / 12 * 100, 2) }}% - 4px);top:calc({{ round(100 - min(100, $v / $maxTrend * 100), 1) }}% - 4px);width:8px;height:8px;border-radius:50%;background:#c8102e;border:2px solid #fff;box-shadow:0 0 0 1px #c8102e"></div>
            @endif
        @endfor
    </div>
    <div style="display:flex;margin-top:6px">
        @for ($l = 1; $l <= 12; $l++)
            <span style="flex:1;text-align:center;font-size:10px;color:{{ $l === (int) now()->month ? '#c8102e' : '#9ca3af' }};font-weight:{{ $l === (int) now()->month ? '700' : '400' }}">{{ $numeLuni[$l-1] }}</span>
        @endfor
    </div>
</div>

{{-- bara de sistem --}}
<div style="margin-top:14px;background:#111827;border-radius:14px;padding:12px 18px;display:flex;flex-wrap:wrap;gap:8px 28px;align-items:center">
    <span style="color:#9ca3af;font-size:12px">⚙️ Sistemul lucrează pentru tine:</span>
    <span style="color:#e5e7eb;font-size:12.5px"><b style="color:#fff">{{ number_format($produsePublicate, 0, ',', '.') }}</b> produse live pe site</span>
    <span style="color:#e5e7eb;font-size:12.5px"><b style="color:#fff">{{ $preturiAzi }}</b> prețuri actualizate azi</span>
    <span style="color:#e5e7eb;font-size:12.5px">tracking curier <b style="color:#fff">automat</b> la 15 min</span>
    <span style="color:#e5e7eb;font-size:12.5px">sync site <b style="color:#fff">live</b>@if($ultimSync) · ultimul: {{ \Carbon\Carbon::parse($ultimSync)->diffForHumans() }}@endif</span>
</div>
</div>
</x-filament-panels::page>
