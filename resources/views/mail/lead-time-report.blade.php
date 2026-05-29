@extends('mail.layout')
@section('content')

<h2 style="margin:0 0 4px;font-size:20px;color:#B91C1C;">Raport Lead Time Recepție</h2>
<p style="margin:0 0 20px;font-size:13px;color:#6B7280;">Generat {{ now()->setTimezone('Europe/Bucharest')->format('d.m.Y H:i') }} &mdash; {{ $total }} PO-uri recepționate</p>

{{-- Sumar --}}
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
  <tr>
    <td style="background:#F5F0E8;border-radius:10px;padding:16px 20px;text-align:center;">
      <div style="font-size:11px;color:#9CA3AF;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Media generală</div>
      <div style="font-size:28px;font-weight:700;color:#1A1A1A;">{{ $avgDays }} zile</div>
    </td>
    <td width="12"></td>
    <td style="background:#FEF2F2;border-radius:10px;padding:16px 20px;text-align:center;">
      <div style="font-size:11px;color:#9CA3AF;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Suspecte (&lt;2h)</div>
      <div style="font-size:28px;font-weight:700;color:#B91C1C;">{{ $suspectCount }}</div>
    </td>
    <td width="12"></td>
    <td style="background:#F0FDF4;border-radius:10px;padding:16px 20px;text-align:center;">
      <div style="font-size:11px;color:#9CA3AF;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Max lead time</div>
      <div style="font-size:28px;font-weight:700;color:#15803D;">{{ $maxDays }} zile</div>
    </td>
  </tr>
</table>

{{-- Suspecte --}}
@if($suspect->count())
<h3 style="margin:0 0 10px;font-size:14px;color:#B91C1C;">⚠️ Suspecte — recepționate în sub 2 ore</h3>
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:24px;font-size:12px;">
  <tr style="background:#FEF2F2;">
    <th style="padding:8px 10px;text-align:left;color:#6B7280;font-weight:600;border-bottom:2px solid #FECACA;">PO</th>
    <th style="padding:8px 10px;text-align:left;color:#6B7280;font-weight:600;border-bottom:2px solid #FECACA;">Furnizor</th>
    <th style="padding:8px 10px;text-align:center;color:#6B7280;font-weight:600;border-bottom:2px solid #FECACA;">Trimis</th>
    <th style="padding:8px 10px;text-align:center;color:#6B7280;font-weight:600;border-bottom:2px solid #FECACA;">Recepționat</th>
    <th style="padding:8px 10px;text-align:center;color:#6B7280;font-weight:600;border-bottom:2px solid #FECACA;">Durată</th>
  </tr>
  @foreach($suspect as $r)
  <tr style="border-bottom:1px solid #FEE2E2;{{ $loop->even ? 'background:#FFF7F7' : '' }}">
    <td style="padding:8px 10px;font-weight:600;color:#B91C1C;">{{ $r['number'] }}</td>
    <td style="padding:8px 10px;color:#374151;">{{ $r['supplier'] }}</td>
    <td style="padding:8px 10px;text-align:center;color:#374151;">{{ $r['sent'] }}</td>
    <td style="padding:8px 10px;text-align:center;color:#374151;">{{ $r['received'] }}</td>
    <td style="padding:8px 10px;text-align:center;font-weight:700;color:#B91C1C;">{{ $r['minutes'] }} min</td>
  </tr>
  @endforeach
</table>
@endif

{{-- Toate PO-urile --}}
<h3 style="margin:0 0 10px;font-size:14px;color:#1A1A1A;">Toate PO-urile recepționate</h3>
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:11px;">
  <tr style="background:#F5F0E8;">
    <th style="padding:7px 8px;text-align:left;color:#6B7280;font-weight:600;border-bottom:2px solid #E8DFD0;">PO</th>
    <th style="padding:7px 8px;text-align:left;color:#6B7280;font-weight:600;border-bottom:2px solid #E8DFD0;">Furnizor</th>
    <th style="padding:7px 8px;text-align:center;color:#6B7280;font-weight:600;border-bottom:2px solid #E8DFD0;">Trimis</th>
    <th style="padding:7px 8px;text-align:center;color:#6B7280;font-weight:600;border-bottom:2px solid #E8DFD0;">Recepționat</th>
    <th style="padding:7px 8px;text-align:center;color:#6B7280;font-weight:600;border-bottom:2px solid #E8DFD0;">Zile</th>
  </tr>
  @foreach($items as $r)
  <tr style="border-bottom:1px solid #E8DFD0;{{ $loop->even ? 'background:#FAF8F5' : '' }}{{ $r['minutes'] < 120 ? 'background:#FFF7F7 !important' : '' }}">
    <td style="padding:6px 8px;font-weight:600;color:{{ $r['minutes'] < 120 ? '#B91C1C' : '#374151' }}">{{ $r['number'] }}</td>
    <td style="padding:6px 8px;color:#374151;">{{ $r['supplier'] }}</td>
    <td style="padding:6px 8px;text-align:center;color:#6B7280;">{{ $r['sent'] }}</td>
    <td style="padding:6px 8px;text-align:center;color:#6B7280;">{{ $r['received'] }}</td>
    <td style="padding:6px 8px;text-align:center;font-weight:700;color:{{ $r['days'] > 7 ? '#B91C1C' : ($r['minutes'] < 120 ? '#D97706' : '#15803D') }}">
      {{ $r['days'] }} z {{ $r['minutes'] < 120 ? '⚠️' : '' }}
    </td>
  </tr>
  @endforeach
  <tr style="background:#F5F0E8;border-top:2px solid #E8DFD0;">
    <td colspan="4" style="padding:8px;font-weight:700;color:#1A1A1A;">TOTAL: {{ $total }} PO-uri &mdash; Media: {{ $avgDays }} zile</td>
    <td style="padding:8px;text-align:center;font-weight:700;color:#1A1A1A;">{{ $avgDays }} z</td>
  </tr>
</table>

@endsection
