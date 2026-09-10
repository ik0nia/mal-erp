<x-filament-panels::page>
@php
    $lei = fn ($n) => number_format((float) $n, 0, ',', '.');
    $delta = function ($now, $prev) {
        if ($prev <= 0) return null;
        return round(($now - $prev) / $prev * 100);
    };
    $dAzi = $delta($aziLei, $ieriLei);
    $dSapt = $delta($saptLei, $saptTrecutaLei);
    $card = 'background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)';
    $label = 'font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px';
    $big = 'font-size:26px;font-weight:800;color:#111827;line-height:1.1';
    $chip = fn ($d) => $d === null ? '' :
        '<span style="font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:8px;vertical-align:middle;' .
        ($d >= 0 ? 'background:#ecfdf3;color:#047857' : 'background:#fef2f2;color:#b91c1c') . '">' .
        ($d >= 0 ? '▲' : '▼') . ' ' . abs($d) . '%</span>';
@endphp

{{-- rând 1: banii --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
    <div style="{{ $card }}">
        <div style="{{ $label }}">Vânzări azi (WinMentor)</div>
        <div style="{{ $big }}">{{ $lei($aziLei) }} lei{!! $chip($dAzi) !!}</div>
        <div style="font-size:12px;color:#9ca3af;margin-top:3px">{{ $aziDoc }} documente · ieri: {{ $lei($ieriLei) }} lei</div>
    </div>
    <div style="{{ $card }}">
        <div style="{{ $label }}">Săptămâna curentă</div>
        <div style="{{ $big }}">{{ $lei($saptLei) }} lei{!! $chip($dSapt) !!}</div>
        <div style="font-size:12px;color:#9ca3af;margin-top:3px">săpt. trecută: {{ $lei($saptTrecutaLei) }} lei</div>
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
        <div style="font-size:12px;color:#9ca3af;margin-top:3px">medie livrare: {{ $avgLivrare ?? '—' }} ·
            @if ($codPendingC > 0)<span style="color:#b45309;font-weight:600">{{ $lei($codPendingS) }} lei ramburs pe drum</span>@else ramburs la zi ✓ @endif
        </div>
    </div>
</div>

{{-- rând 2: top produse + alerte stoc --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-top:14px">
    <div style="{{ $card }}">
        <div style="{{ $label }}">🏆 Top produse — ultimele 7 zile</div>
        <table style="width:100%;border-collapse:collapse;margin-top:6px">
            @forelse ($topProduse as $i => $p)
                <tr>
                    <td style="padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:12px;color:#9ca3af;width:18px">{{ $i + 1 }}</td>
                    <td style="padding:5px 6px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#1f2937">{{ \Illuminate\Support\Str::limit($p->den_articol, 46) }}</td>
                    <td style="padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:12.5px;color:#6b7280;text-align:right;white-space:nowrap">{{ $lei($p->buc) }} buc</td>
                    <td style="padding:5px 0 5px 10px;border-bottom:1px solid #f3f4f6;font-size:13px;font-weight:700;color:#111827;text-align:right;white-space:nowrap">{{ $lei($p->lei) }} lei</td>
                </tr>
            @empty
                <tr><td style="color:#9ca3af;font-size:13px;padding:8px 0">Fără vânzări încă.</td></tr>
            @endforelse
        </table>
    </div>
    <div style="{{ $card }}">
        <div style="{{ $label }}">⚠️ Se vând, dar stoc 0 în ERP — ultimele 14 zile</div>
        <table style="width:100%;border-collapse:collapse;margin-top:6px">
            @forelse ($alerteStoc as $p)
                <tr>
                    <td style="padding:5px 6px 5px 0;border-bottom:1px solid #f3f4f6;font-size:13px;color:#1f2937">{{ \Illuminate\Support\Str::limit($p->name, 52) }}</td>
                    <td style="padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:13px;font-weight:700;color:#b45309;text-align:right;white-space:nowrap">{{ $lei($p->buc_14z) }} buc vândute</td>
                </tr>
            @empty
                <tr><td style="color:#047857;font-size:13px;padding:8px 0">✓ Nimic critic — tot ce se vinde are stoc.</td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- rând 3: sistemul --}}
<div style="margin-top:14px;background:#111827;border-radius:14px;padding:12px 18px;display:flex;flex-wrap:wrap;gap:8px 28px;align-items:center">
    <span style="color:#9ca3af;font-size:12px">⚙️ Sistemul lucrează pentru tine:</span>
    <span style="color:#e5e7eb;font-size:12.5px"><b style="color:#fff">{{ number_format($emailsAi, 0, ',', '.') }}</b> emailuri furnizori analizate AI</span>
    <span style="color:#e5e7eb;font-size:12.5px">tracking curier <b style="color:#fff">automat</b> la 15 min</span>
    <span style="color:#e5e7eb;font-size:12.5px">sync site <b style="color:#fff">live</b>@if($ultimSync) · ultimul: {{ \Carbon\Carbon::parse($ultimSync)->diffForHumans() }}@endif</span>
</div>
</x-filament-panels::page>
