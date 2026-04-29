@extends('mail.layout', ['headerTitle' => 'Alerte Prețuri Achiziție', 'subject' => $subject])

@section('content')

<h2 style="margin:0 0 6px;font-size:20px;color:#B91C1C;font-weight:700;">
  &#128202;&nbsp; Alerte prețuri achiziție
</h2>
<p style="margin:0 0 28px;font-size:14px;color:#6B7280;">
  Bună ziua, <strong>{{ $recipientName }}</strong>.
  Au fost detectate modificări de prețuri de achiziție pentru care prețul de vânzare nu a fost actualizat.
</p>

{{-- ── SPIKES ── --}}
@if(!empty($spikes))
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:28px;border-radius:8px;overflow:hidden;border:1px solid #FCA5A5;border-collapse:separate;">
  <tr>
    <td colspan="5" style="background:#B91C1C;padding:10px 14px;">
      <span style="font-size:13px;font-weight:700;color:#FFFFFF;">
        &#128200; Creșteri preț achiziție &mdash; {{ count($spikes) }} produs(e)
      </span>
    </td>
  </tr>
  <tr style="background:#FEF2F2;">
    <th align="left"  style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #FCA5A5;">Produs</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #FCA5A5;">Achiz. vechi</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #FCA5A5;">Achiz. nou</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #FCA5A5;">Vânzare</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #FCA5A5;">Marjă</th>
  </tr>
  @foreach(array_slice($spikes, 0, 25) as $r)
  @php $marginColor = ($r['margin'] !== null && $r['margin'] < 10) ? '#B91C1C' : '#059669'; @endphp
  <tr style="border-bottom:1px solid #FEE2E2;">
    <td style="padding:10px 10px;">
      <div style="font-size:13px;color:#1A1A1A;font-weight:600;">{{ Str::limit($r['name'], 40) }}</div>
      <div style="font-size:11px;color:#9CA3AF;font-family:monospace;">{{ $r['sku'] }} &middot; {{ Str::limit($r['supplier'], 22) }}</div>
    </td>
    <td align="right" style="padding:10px 10px;font-size:12px;color:#9CA3AF;white-space:nowrap;">{{ $r['old_price'] }}&nbsp;RON</td>
    <td align="right" style="padding:10px 10px;white-space:nowrap;">
      <div style="font-size:13px;color:#B91C1C;font-weight:700;">{{ $r['new_price'] }}&nbsp;RON</div>
      <div style="font-size:11px;color:#B91C1C;">+{{ number_format($r['pct'], 1) }}%</div>
    </td>
    <td align="right" style="padding:10px 10px;font-size:12px;color:#374151;white-space:nowrap;">{{ $r['sell_price'] }}&nbsp;RON</td>
    <td align="right" style="padding:10px 10px;font-size:14px;font-weight:700;color:{{ $marginColor }};white-space:nowrap;">
      {{ $r['margin'] !== null ? $r['margin'].'%' : '—' }}
    </td>
  </tr>
  @endforeach
  @if(count($spikes) > 25)
  <tr><td colspan="5" align="center" style="padding:10px;font-size:12px;color:#9CA3AF;">&hellip; și {{ count($spikes) - 25 }} mai multe</td></tr>
  @endif
</table>
@endif

{{-- ── DROPS ── --}}
@if(!empty($drops))
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:28px;border-radius:8px;overflow:hidden;border:1px solid #A7F3D0;border-collapse:separate;">
  <tr>
    <td colspan="5" style="background:#065F46;padding:10px 14px;">
      <span style="font-size:13px;font-weight:700;color:#FFFFFF;">
        &#128201; Scăderi preț achiziție &mdash; {{ count($drops) }} produs(e)
      </span>
    </td>
  </tr>
  <tr style="background:#F0FDF4;">
    <th align="left"  style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #A7F3D0;">Produs</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #A7F3D0;">Achiz. vechi</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #A7F3D0;">Achiz. nou</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #A7F3D0;">Vânzare</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #A7F3D0;">Marjă</th>
  </tr>
  @foreach(array_slice($drops, 0, 25) as $r)
  @php $marginColor = ($r['margin'] !== null && $r['margin'] < 10) ? '#B91C1C' : '#059669'; @endphp
  <tr style="border-bottom:1px solid #D1FAE5;">
    <td style="padding:10px 10px;">
      <div style="font-size:13px;color:#1A1A1A;font-weight:600;">{{ Str::limit($r['name'], 40) }}</div>
      <div style="font-size:11px;color:#9CA3AF;font-family:monospace;">{{ $r['sku'] }} &middot; {{ Str::limit($r['supplier'], 22) }}</div>
    </td>
    <td align="right" style="padding:10px 10px;font-size:12px;color:#9CA3AF;white-space:nowrap;">{{ $r['old_price'] }}&nbsp;RON</td>
    <td align="right" style="padding:10px 10px;white-space:nowrap;">
      <div style="font-size:13px;color:#059669;font-weight:700;">{{ $r['new_price'] }}&nbsp;RON</div>
      <div style="font-size:11px;color:#059669;">-{{ number_format($r['pct'], 1) }}%</div>
    </td>
    <td align="right" style="padding:10px 10px;font-size:12px;color:#374151;white-space:nowrap;">{{ $r['sell_price'] }}&nbsp;RON</td>
    <td align="right" style="padding:10px 10px;font-size:14px;font-weight:700;color:{{ $marginColor }};white-space:nowrap;">
      {{ $r['margin'] !== null ? $r['margin'].'%' : '—' }}
    </td>
  </tr>
  @endforeach
  @if(count($drops) > 25)
  <tr><td colspan="5" align="center" style="padding:10px;font-size:12px;color:#9CA3AF;">&hellip; și {{ count($drops) - 25 }} mai multe</td></tr>
  @endif
</table>
@endif

{{-- ── LOW MARGIN ── --}}
@if(!empty($margins))
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:28px;border-radius:8px;overflow:hidden;border:1px solid #FCD34D;border-collapse:separate;">
  <tr>
    <td colspan="4" style="background:#92400E;padding:10px 14px;">
      <span style="font-size:13px;font-weight:700;color:#FFFFFF;">
        &#9888; Marjă sub prag &mdash; {{ count($margins) }} produs(e)
      </span>
    </td>
  </tr>
  <tr style="background:#FFFBEB;">
    <th align="left"  style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #FCD34D;">Produs</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #FCD34D;">Achiziție</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #FCD34D;">Vânzare</th>
    <th align="right" style="padding:8px 10px;font-size:11px;color:#9CA3AF;border-bottom:1px solid #FCD34D;">Marjă</th>
  </tr>
  @foreach(array_slice($margins, 0, 25) as $r)
  @php $marginColor = $r['margin'] < 0 ? '#7F1D1D' : '#92400E'; @endphp
  <tr style="border-bottom:1px solid #FEF3C7;">
    <td style="padding:10px 10px;">
      <div style="font-size:13px;color:#1A1A1A;font-weight:600;">{{ Str::limit($r['name'], 44) }}</div>
      <div style="font-size:11px;color:#9CA3AF;font-family:monospace;">{{ $r['sku'] }} &middot; {{ Str::limit($r['supplier'], 22) }}</div>
    </td>
    <td align="right" style="padding:10px 10px;font-size:12px;color:#374151;white-space:nowrap;">{{ $r['purchase_price'] }}&nbsp;RON</td>
    <td align="right" style="padding:10px 10px;font-size:12px;color:#374151;white-space:nowrap;">{{ $r['sell_price'] }}&nbsp;RON</td>
    <td align="right" style="padding:10px 10px;font-size:15px;font-weight:700;color:{{ $marginColor }};white-space:nowrap;">
      {{ $r['margin'] }}%
    </td>
  </tr>
  @endforeach
  @if(count($margins) > 25)
  <tr><td colspan="4" align="center" style="padding:10px;font-size:12px;color:#9CA3AF;">&hellip; și {{ count($margins) - 25 }} mai multe</td></tr>
  @endif
</table>
@endif

<div style="text-align:center;margin-top:28px;">
  <a href="https://erp.malinco.ro/app/produse"
     style="display:inline-block;background:#B91C1C;color:#FFFFFF;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:14px;font-weight:700;">
    Actualizează prețurile de vânzare
  </a>
</div>

@endsection
