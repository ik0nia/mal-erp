@extends('mail.layout', ['subject' => 'Cerere asociere EAN', 'headerTitle' => 'Cerere asociere EAN'])

@section('content')

<h2 style="margin:0 0 20px;font-size:18px;color:#B91C1C;">Nouă cerere de asociere EAN</h2>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
  <tr>
    <td style="padding:8px 0;color:#6B7280;font-size:13px;width:140px;">EAN scanat:</td>
    <td style="padding:8px 0;font-size:14px;font-family:monospace;font-weight:bold;">{{ $request->ean }}</td>
  </tr>
  @if($request->product)
  <tr>
    <td style="padding:8px 0;color:#6B7280;font-size:13px;">Produs propus:</td>
    <td style="padding:8px 0;font-size:14px;">{{ $request->product->name }}</td>
  </tr>
  <tr>
    <td style="padding:8px 0;color:#6B7280;font-size:13px;">SKU curent:</td>
    <td style="padding:8px 0;font-size:14px;font-family:monospace;">{{ $request->product->sku ?? '—' }}</td>
  </tr>
  @else
  <tr>
    <td style="padding:8px 0;color:#6B7280;font-size:13px;">Produs propus:</td>
    <td style="padding:8px 0;font-size:14px;color:#9CA3AF;">Neasociat</td>
  </tr>
  @endif
  <tr>
    <td style="padding:8px 0;color:#6B7280;font-size:13px;">Solicitat de:</td>
    <td style="padding:8px 0;font-size:14px;">{{ $request->requestedBy?->name ?? 'Necunoscut' }}</td>
  </tr>
  @if($request->notes)
  <tr>
    <td style="padding:8px 0;color:#6B7280;font-size:13px;">Note:</td>
    <td style="padding:8px 0;font-size:14px;">{{ $request->notes }}</td>
  </tr>
  @endif
  <tr>
    <td style="padding:8px 0;color:#6B7280;font-size:13px;">Data:</td>
    <td style="padding:8px 0;font-size:14px;">{{ $request->created_at->setTimezone('Europe/Bucharest')->format('d.m.Y H:i') }}</td>
  </tr>
</table>

<div style="text-align:center;margin-top:24px;">
  <a href="https://erp.malinco.ro/app/ean-association-requests"
     style="display:inline-block;padding:10px 28px;background:#B91C1C;color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:14px;font-weight:bold;">
    Vezi cererile în ERP
  </a>
</div>

@endsection
