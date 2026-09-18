@php $d = $this->getData(); $kpi = $d['kpi']; @endphp

<x-filament-panels::page>
<div style="max-width:1100px;">

  {{-- Salut --}}
  <div style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
    <div>
      <div style="font-size:24px;font-weight:800;color:#111827;line-height:1.2;">
        {{ $this->getSalut() }}, {{ $this->getPrenume() }} 👋
      </div>
      <div style="font-size:13px;color:#6b7280;margin-top:3px;text-transform:capitalize;">
        {{ \Illuminate\Support\Carbon::now()->translatedFormat('l, j F Y') }}
      </div>
    </div>
    <div style="font-size:11px;color:#9ca3af;">actualizat {{ $d['generat_la'] }}</div>
  </div>

  {{-- KPI strip --}}
  @php
    $cards = [
      ['Vânzări azi', number_format($kpi['vanzari_azi'], 0, ',', '.') . ' lei', 'cu TVA', '#dc2626'],
      ['Comenzi în procesare', $kpi['comenzi'], 'online, de onorat', '#1d4ed8'],
      ['PO în lucru', $kpi['po_in_lucru'], 'comenzi furnizor deschise', '#0f766e'],
      ['Necesare de procesat', $kpi['necesare'], 'cereri gata de comandat', '#b45309'],
    ];
  @endphp
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:22px;">
    @foreach($cards as [$lbl, $val, $sub, $col])
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;border-top:3px solid {{ $col }};box-shadow:0 1px 2px rgba(0,0,0,.03);">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">{{ $lbl }}</div>
        <div style="font-size:26px;font-weight:800;color:#111827;margin-top:4px;line-height:1;">{{ $val }}</div>
        <div style="font-size:11px;color:#9ca3af;margin-top:5px;">{{ $sub }}</div>
      </div>
    @endforeach
  </div>

  {{-- Ce-ți cere atenția azi --}}
  <div style="font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#374151;margin:0 0 10px;">
    Ce-ți cere atenția azi
  </div>

  @if(count($d['actiuni_active']) === 0)
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:22px;text-align:center;color:#15803d;font-weight:600;">
      🎉 Ești la zi — nimic urgent în acest moment.
    </div>
  @else
    <div style="display:flex;flex-direction:column;gap:10px;">
      @foreach($d['actiuni_active'] as $a)
        <div style="display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid {{ $a['color'] }};border-radius:12px;padding:13px 16px;box-shadow:0 1px 2px rgba(0,0,0,.03);">
          <div style="font-size:22px;line-height:1;flex:0 0 auto;">{{ $a['icon'] }}</div>
          <div style="flex:0 0 auto;min-width:52px;text-align:center;">
            <div style="font-size:28px;font-weight:800;color:{{ $a['color'] }};line-height:1;">{{ $a['count'] }}</div>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:15px;font-weight:700;color:#111827;">{{ $a['label'] }}</div>
            <div style="font-size:12px;color:#6b7280;margin-top:2px;">{{ $a['sub'] }}</div>
          </div>
          @if($a['url'])
            <a href="{{ $a['url'] }}"
               style="flex:0 0 auto;background:{{ $a['color'] }};color:#fff;font-size:13px;font-weight:700;text-decoration:none;padding:8px 16px;border-radius:8px;white-space:nowrap;">
              Rezolvă →
            </a>
          @endif
        </div>
      @endforeach
    </div>
  @endif

  {{-- Liniște (cozile goale) --}}
  @if(count($d['actiuni_goale']))
    <div style="margin-top:16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
      <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;">La zi:</span>
      @foreach($d['actiuni_goale'] as $a)
        <span style="display:inline-flex;align-items:center;gap:5px;background:#f3f4f6;color:#6b7280;font-size:12px;padding:3px 10px;border-radius:999px;">
          <span style="color:#22c55e;">✓</span> {{ $a['label'] }}
        </span>
      @endforeach
    </div>
  @endif

  {{-- Sănătate sistem --}}
  <div style="margin-top:22px;padding-top:14px;border-top:1px solid #eef0f2;font-size:12px;color:#6b7280;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
    @if($d['erori'] > 0)
      <span style="display:inline-flex;align-items:center;gap:6px;background:#fef2f2;color:#b91c1c;font-weight:700;padding:4px 12px;border-radius:999px;">
        🐞 {{ $d['erori'] }} erori sistem deschise
      </span>
    @else
      <span style="display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;color:#15803d;font-weight:600;padding:4px 12px;border-radius:999px;">
        ✓ sistem fără erori deschise
      </span>
    @endif
    <span style="color:#9ca3af;">Pilot — vizibil doar pentru tine deocamdată, ca „Puls".</span>
  </div>

</div>
</x-filament-panels::page>
