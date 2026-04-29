@extends('mail.layout', ['headerTitle' => 'Monitor Workeri', 'subject' => $subject])

@section('content')

<h2 style="margin:0 0 6px;font-size:20px;color:#B91C1C;font-weight:700;">
  &#9888;&nbsp; Alertă sistem
</h2>
<p style="margin:0 0 24px;font-size:14px;color:#6B7280;">
  Au fost detectate <strong>{{ count($issues) }}</strong> problem(e) la {{ now()->setTimezone('Europe/Bucharest')->format('d.m.Y H:i') }}.
</p>

@foreach($issues as $issue)
@php
  $isJob    = $issue['tip'] === 'JOB_EȘUAT';
  $bg       = $isJob ? '#FEF2F2' : '#FFFBEB';
  $border   = $isJob ? '#FCA5A5' : '#FCD34D';
  $label_color = $isJob ? '#B91C1C' : '#92400E';
  $icon     = $isJob ? '&#10060;' : '&#9646;';
  $tip_label = $isJob ? 'Job eșuat' : 'Worker înghețat';
@endphp
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:14px;border-radius:8px;border:1px solid {{ $border }};border-collapse:separate;">
  <tr>
    <td style="background:{{ $bg }};padding:14px 16px;border-radius:8px;">
      <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:{{ $label_color }};text-transform:uppercase;letter-spacing:0.5px;">
        {!! $icon !!} {{ $tip_label }}
      </p>
      <p style="margin:0 0 8px;font-size:15px;font-weight:700;color:#1A1A1A;">
        {{ $issue['label'] }}
      </p>
      <p style="margin:0;font-size:12px;color:#6B7280;font-family:monospace;word-break:break-all;line-height:1.5;">
        {{ Str::limit($issue['detaliu'], 220) }}
      </p>
      @if(!empty($issue['la']) && $issue['la'] !== 'N/A')
      <p style="margin:8px 0 0;font-size:11px;color:#9CA3AF;">&#128337; {{ $issue['la'] }}</p>
      @endif
    </td>
  </tr>
</table>
@endforeach

<div style="margin-top:28px;text-align:center;">
  <a href="https://erp.malinco.ro/admin"
     style="display:inline-block;background:#B91C1C;color:#FFFFFF;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:14px;font-weight:700;">
    Deschide panoul admin
  </a>
</div>

@endsection
