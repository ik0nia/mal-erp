<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.4; }
  .page { padding: 18px 25px; }

  .header { display: table; width: 100%; border-bottom: 2px solid #dc2626; padding-bottom: 10px; margin-bottom: 14px; }
  .header-left { display: table-cell; vertical-align: middle; }
  .header-right { display: table-cell; text-align: right; vertical-align: middle; }
  .logo { height: 32px; width: auto; display: block; }
  .company-sub { font-size: 9px; color: #6b7280; margin-top: 3px; }
  .report-title { font-size: 16px; font-weight: bold; color: #1a1a1a; }
  .report-meta { font-size: 9px; color: #6b7280; margin-top: 3px; }

  table { width: 100%; border-collapse: collapse; }
  thead tr { background: #dc2626; color: white; }
  thead th { padding: 6px 8px; text-align: left; font-size: 9px; font-weight: bold; }
  tbody tr:nth-child(even) { background: #f8fafc; }
  tbody td { padding: 5px 8px; font-size: 9px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
  .muted { color: #9ca3af; font-style: italic; }
  .no-buyer { color: #dc2626; }
  .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 8px; font-weight: bold; }
  .badge-high { background: #fee2e2; color: #dc2626; }
  .badge-mid  { background: #fef3c7; color: #b45309; }
  .badge-low  { background: #f1f5f9; color: #64748b; }

  .footer { margin-top: 14px; padding-top: 8px; border-top: 1px solid #e2e8f0; display: table; width: 100%; }
  .footer-left { display: table-cell; font-size: 8px; color: #9ca3af; }
  .footer-right { display: table-cell; text-align: right; font-size: 8px; color: #9ca3af; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <div class="header-left">
      <img src="file://{{ storage_path('app/public/malinco-logo.png') }}" alt="Malinco" class="logo">
      <div class="company-sub">SC Malinco Prodex SRL</div>
    </div>
    <div class="header-right">
      <div class="report-title">Furnizori fără persoane de contact</div>
      <div class="report-meta">{{ $suppliers->count() }} furnizori activi &nbsp;|&nbsp; Generat: {{ now()->format('d.m.Y H:i') }}</div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:3%">#</th>
        <th style="width:28%">Furnizor</th>
        <th style="width:22%">Website</th>
        <th style="width:16%">Email furnizor</th>
        <th style="width:12%">Telefon</th>
        <th style="width:7%; text-align:center">Produse</th>
        <th style="width:12%">Buyer responsabil</th>
      </tr>
    </thead>
    <tbody>
      @foreach($suppliers as $i => $supplier)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>{{ $supplier->name }}</td>
        <td>
          @if($supplier->website_url)
            <span style="color:#2563eb">{{ $supplier->website_url }}</span>
          @else
            <span class="muted">—</span>
          @endif
        </td>
        <td>{{ $supplier->email ?: '—' }}</td>
        <td>{{ $supplier->phone ?: '—' }}</td>
        <td style="text-align:center">
          @php $cnt = $supplier->products_count; @endphp
          @if($cnt >= 100)
            <span class="badge badge-high">{{ $cnt }}</span>
          @elseif($cnt >= 20)
            <span class="badge badge-mid">{{ $cnt }}</span>
          @else
            <span class="badge badge-low">{{ $cnt }}</span>
          @endif
        </td>
        <td>
          @if($supplier->buyers->isNotEmpty())
            {{ $supplier->buyers->pluck('name')->implode(', ') }}
          @elseif($supplier->buyer)
            {{ $supplier->buyer->name }}
          @else
            <span class="no-buyer">Neatribuit</span>
          @endif
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="footer">
    <div class="footer-left">ERP Malinco — Raport furnizori fără contact &nbsp;|&nbsp; Afișați doar furnizorii activi, sortați după nr. produse</div>
    <div class="footer-right">{{ now()->format('d.m.Y') }}</div>
  </div>

</div>
</body>
</html>
