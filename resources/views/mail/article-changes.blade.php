@extends('mail.layout', ['headerTitle' => 'Modificări Articole WinMentor', 'subject' => $subject])

@section('content')

@php
  $skuChanges  = array_values(array_filter($changes, fn($c) => $c['tip'] === 'SKU'));
  $nameChanges = array_values(array_filter($changes, fn($c) => $c['tip'] === 'DENUMIRE'));
  $withErrors  = array_values(array_filter($changes, fn($c) => !empty($c['woo_error'])));
  $newInWm         = $newInWmCapped ?? $newInWm ?? [];
  $newInWmOverflow = $newInWmOverflow ?? 0;
@endphp

<h2 style="margin:0 0 6px;font-size:20px;color:#B91C1C;font-weight:700;">
  &#128260;&nbsp; Modificări detectate în WinMentor
</h2>
@php $appliedCount = count($changes) - count($withErrors); @endphp
<p style="margin:0 0 28px;font-size:14px;color:#6B7280;">
  <strong>{{ $appliedCount }}</strong> modificare(i) aplicate automat în ERP și WooCommerce{{ count($withErrors) ? ', ' . count($withErrors) . ' NEAPLICATE din cauza erorilor de mai jos' : '' }}.
</p>

{{-- SKU changes --}}
@if(!empty($skuChanges))
<p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:1px;">
  SKU-uri schimbate ({{ count($skuChanges) }})
</p>
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:28px;border-radius:8px;overflow:hidden;border:1px solid #E8DFD0;border-collapse:separate;">
  <tr style="background:#F5F0E8;">
    <th align="left" style="padding:9px 12px;font-size:11px;color:#6B7280;border-bottom:1px solid #E8DFD0;">Produs</th>
    <th align="left" style="padding:9px 12px;font-size:11px;color:#6B7280;border-bottom:1px solid #E8DFD0;">SKU vechi</th>
    <th align="left" style="padding:9px 12px;font-size:11px;color:#6B7280;border-bottom:1px solid #E8DFD0;">SKU nou</th>
  </tr>
  @foreach($skuChanges as $c)
  <tr>
    <td style="padding:10px 12px;font-size:13px;color:#1A1A1A;border-bottom:1px solid #F3F4F6;">
      {{ Str::limit($c['denumire'], 48) }}
    </td>
    <td style="padding:10px 12px;font-size:12px;font-family:monospace;color:#9CA3AF;text-decoration:line-through;border-bottom:1px solid #F3F4F6;">
      {{ $c['sku_vechi'] }}
    </td>
    <td style="padding:10px 12px;font-size:12px;font-family:monospace;color:#059669;font-weight:700;border-bottom:1px solid #F3F4F6;">
      {{ $c['sku_nou'] }}
      @if(!empty($c['duplicat']))
      <span style="color:#B91C1C;font-family:sans-serif;font-weight:700;">— NEAPLICAT (SKU duplicat)</span>
      @endif
      @if(!empty($c['auto_merge']))
      <br><span style="color:#2563EB;font-family:sans-serif;font-weight:400;font-size:11px;">{{ $c['auto_merge'] }}</span>
      @endif
    </td>
  </tr>
  @endforeach
</table>
@endif

{{-- Name changes --}}
@if(!empty($nameChanges))
<p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:1px;">
  Denumiri schimbate ({{ count($nameChanges) }})
</p>
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:28px;border-radius:8px;overflow:hidden;border:1px solid #E8DFD0;border-collapse:separate;">
  <tr style="background:#F5F0E8;">
    <th align="left" style="padding:9px 12px;font-size:11px;color:#6B7280;border-bottom:1px solid #E8DFD0;">SKU</th>
    <th align="left" style="padding:9px 12px;font-size:11px;color:#6B7280;border-bottom:1px solid #E8DFD0;">Denumire veche</th>
    <th align="left" style="padding:9px 12px;font-size:11px;color:#6B7280;border-bottom:1px solid #E8DFD0;">Denumire nouă</th>
  </tr>
  @foreach($nameChanges as $c)
  <tr>
    <td style="padding:10px 12px;font-size:11px;font-family:monospace;color:#6B7280;border-bottom:1px solid #F3F4F6;">
      {{ $c['sku'] }}
    </td>
    <td style="padding:10px 12px;font-size:13px;color:#9CA3AF;text-decoration:line-through;border-bottom:1px solid #F3F4F6;">
      {{ Str::limit($c['vechi'], 44) }}
    </td>
    <td style="padding:10px 12px;font-size:13px;color:#1A1A1A;font-weight:600;border-bottom:1px solid #F3F4F6;">
      {{ Str::limit($c['nou'], 44) }}
    </td>
  </tr>
  @endforeach
</table>
@endif

{{-- WooCommerce errors --}}
@if(!empty($withErrors))
<div style="background:#FEF2F2;border:1px solid #FCA5A5;border-radius:8px;padding:14px 16px;margin-bottom:20px;">
  <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#B91C1C;">
    &#9888; Erori la sincronizare WooCommerce ({{ count($withErrors) }})
  </p>
  @foreach($withErrors as $c)
  <p style="margin:4px 0;font-size:12px;color:#7F1D1D;font-family:monospace;">
    {{ $c['sku'] ?? ($c['sku_vechi'] ?? '') }}: {{ Str::limit($c['woo_error'], 120) }}
  </p>
  @endforeach
</div>
@endif

{{-- New WinMentor articles missing from ERP --}}
@if(!empty($newInWm))
<p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:1px;">
  Articole noi în WinMentor, lipsă din ERP ({{ count($newInWm) }})
</p>
<div style="background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;padding:4px 0;margin-bottom:28px;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
    <tr style="background:#FEF3C7;">
      <th align="left" style="padding:9px 12px;font-size:11px;color:#92400E;border-bottom:1px solid #FCD34D;">SKU</th>
      <th align="left" style="padding:9px 12px;font-size:11px;color:#92400E;border-bottom:1px solid #FCD34D;">Denumire WinMentor</th>
    </tr>
    @foreach($newInWm as $a)
    <tr>
      <td style="padding:10px 12px;font-size:11px;font-family:monospace;color:#92400E;border-bottom:1px solid #FEF3C7;">{{ $a['sku'] }}</td>
      <td style="padding:10px 12px;font-size:13px;color:#1A1A1A;font-weight:600;border-bottom:1px solid #FEF3C7;">{{ $a['denumire'] }}</td>
    </tr>
    @endforeach
  </table>
</div>
@if($newInWmOverflow > 0)
<p style="margin:8px 0 0;font-size:12px;color:#92400E;font-style:italic;padding:0 12px 10px;">
  ...și încă {{ $newInWmOverflow }} articole. Verificați WinMentor pentru lista completă.
</p>
@endif
<p style="margin:0 0 20px;font-size:13px;color:#92400E;line-height:1.6;">
  &#9888; Aceste produse (clasa 1, gestiune MP) există în WinMentor dar nu au fișă în ERP. Creați-le manual sau importați-le.
</p>
@endif

<p style="margin:0;font-size:13px;color:#6B7280;line-height:1.6;">
  Dacă vreo modificare este eronată, corectați manual în ERP și verificați originea în WinMentor.
</p>

@endsection
