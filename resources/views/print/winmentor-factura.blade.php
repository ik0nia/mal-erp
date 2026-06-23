<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title>{{ $doc->tip_doc }} Nr. {{ $doc->nr_factura }}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color: #1f2937; font-size: 12px; margin: 0; padding: 24px; background: #f3f4f6; }
  .sheet { max-width: 800px; margin: 0 auto; background: #fff; padding: 32px 36px; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
  .toolbar { max-width: 800px; margin: 0 auto 12px; text-align: right; }
  .btn { display: inline-flex; align-items: center; gap: 6px; background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 8px 16px; font-size: 13px; cursor: pointer; text-decoration: none; }
  .btn:hover { background: #1d4ed8; }
  .head { text-align: center; border-bottom: 3px solid #b91c1c; padding-bottom: 16px; margin-bottom: 22px; }
  .head .logo { height: 56px; width: auto; margin-bottom: 10px; }
  .head h1 { margin: 0; font-size: 24px; font-weight: 800; color: #b91c1c; letter-spacing: .5px; }
  .head .doc-date { font-size: 12px; color: #6b7280; margin-top: 4px; }
  .parties { display: flex; gap: 24px; margin-bottom: 20px; }
  .party { flex: 1; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; }
  .party h3 { margin: 0 0 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #9ca3af; }
  .party .nm { font-size: 13px; font-weight: 700; color: #111827; }
  .party .ln { font-size: 11px; color: #4b5563; line-height: 1.55; }
  table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  table.items th { background: #b91c1c; color: #fff; font-size: 9.5px; text-transform: uppercase; letter-spacing: .03em; padding: 7px 8px; text-align: left; line-height: 1.25; }
  table.items th .sub { font-weight: 400; opacity: .82; font-size: 8.5px; }
  table.items th.r, table.items td.r { text-align: right; }
  table.items td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; font-size: 11px; }
  table.items tbody tr:nth-child(even) { background: #fafafa; }
  .sku { font-size: 10px; color: #9ca3af; }
  .totals { display: flex; justify-content: flex-end; margin-bottom: 18px; }
  .totals table { border-collapse: collapse; min-width: 280px; }
  .totals td { padding: 6px 12px; font-size: 12px; }
  .totals td.lbl { color: #6b7280; text-align: right; }
  .totals td.val { text-align: right; font-weight: 600; }
  .totals tr.grand td { border-top: 2px solid #b91c1c; font-size: 15px; font-weight: 800; color: #b91c1c; padding-top: 8px; }
  .obs { border-left: 4px solid #f59e0b; background: #fffbeb; padding: 10px 14px; font-size: 11.5px; color: #78350f; margin-bottom: 18px; }
  .obs .lbl { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #92400e; font-weight: 700; margin-bottom: 3px; }
  .foot { border-top: 1px solid #e5e7eb; padding-top: 12px; font-size: 10.5px; color: #6b7280; display: flex; justify-content: space-between; }
  @media print {
    body { background: #fff; padding: 0; }
    .sheet { box-shadow: none; max-width: 100%; padding: 0; }
    .toolbar { display: none; }
    table.items th, .head h1, .totals tr.grand td, .head { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { margin: 1.2cm; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <button type="button" class="btn" onclick="window.print()">&#128424; Printează</button>
</div>

<div class="sheet">

  <div class="head">
    <img src="{{ asset('malinco-logo.png') }}" alt="Malinco" class="logo">
    <h1>{{ mb_strtoupper($doc->tip_doc, 'UTF-8') }} Nr. {{ $doc->nr_factura }}</h1>
    <div class="doc-date">Data: {{ $doc->date_str }}</div>
  </div>

  <div class="parties">
    <div class="party">
      <h3>Furnizor</h3>
      <div class="nm">{{ $company?->company_name ?: 'Malinco Prodex S.R.L.' }}</div>
      <div class="ln">
        @if($company?->company_vat_number)CUI: {{ $company->company_is_vat_payer ? 'RO' : '' }}{{ $company->company_vat_number }}<br>@endif
        @if($company?->company_registration_number){{ $company->company_registration_number }}<br>@endif
        @if($company?->address){{ $company->address }}@endif
      </div>
    </div>
    <div class="party">
      <h3>Client</h3>
      <div class="nm">{{ $doc->partner_name }}</div>
      <div class="ln">
        @if($doc->partner_cui)CUI/CNP: {{ $doc->partner_cui }}<br>@endif
        @if($doc->partner_addr){{ $doc->partner_addr }}<br>@endif
        @if($doc->partner_loc){{ $doc->partner_loc }}@if($doc->partner_judet), {{ $doc->partner_judet }}@endif<br>@endif
        @if($doc->partner_phone)Tel: {{ $doc->partner_phone }}@endif
      </div>
    </div>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th style="width:24px;">#</th>
        <th>Produs</th>
        <th class="r" style="width:54px;">Cant.</th>
        <th style="width:38px;">UM</th>
        <th class="r" style="width:78px;">Preț unit.<br><span class="sub">fără TVA</span></th>
        <th class="r" style="width:82px;">Valoare<br><span class="sub">fără TVA</span></th>
        <th class="r" style="width:74px;">TVA<br><span class="sub">21%</span></th>
        <th class="r" style="width:86px;">Total<br><span class="sub">cu TVA</span></th>
      </tr>
    </thead>
    <tbody>
      @foreach($lines as $i => $l)
        @php
          $val = (float) $l->total;
          $tva = round($val * 0.21, 2);
          $totCuTva = round($val * 1.21, 2);
        @endphp
        <tr>
          <td>{{ $i + 1 }}</td>
          <td>
            {{ $l->product_name ?: '—' }}
            @if($l->sku)<div class="sku">{{ $l->sku }}</div>@endif
          </td>
          <td class="r">{{ rtrim(rtrim(number_format((float) $l->cantitate, 3, ',', '.'), '0'), ',') }}</td>
          <td>{{ $l->uom }}</td>
          <td class="r">{{ number_format((float) $l->pret, 2, ',', '.') }}</td>
          <td class="r">{{ number_format($val, 2, ',', '.') }}</td>
          <td class="r">{{ number_format($tva, 2, ',', '.') }}</td>
          <td class="r">{{ number_format($totCuTva, 2, ',', '.') }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="totals">
    <table>
      <tr><td class="lbl">Total fără TVA</td><td class="val">{{ number_format((float) $doc->total, 2, ',', '.') }} RON</td></tr>
      <tr><td class="lbl">TVA (21%)</td><td class="val">{{ number_format((float) $doc->tva_estimat, 2, ',', '.') }} RON</td></tr>
      <tr class="grand"><td class="lbl">TOTAL</td><td class="val">{{ number_format((float) $doc->total_cu_tva, 2, ',', '.') }} RON</td></tr>
    </table>
  </div>

  @if($doc->observatii)
    <div class="obs">
      <div class="lbl">Observații</div>
      {{ $doc->observatii }}
    </div>
  @endif

  <div class="foot">
    <div>
      @if($doc->agent_name)Agent: {{ $doc->agent_name }}<br>@endif
      Document informativ — factura fiscală oficială este cea din WinMentor.
    </div>
    <div style="text-align:right;">
      Generat: {{ now()->format('d.m.Y H:i') }}<br>
      ERP Malinco
    </div>
  </div>

</div>

<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
</body>
</html>
