<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1a1a1a; line-height: 1.4; }
  .page { padding: 18px 25px; }

  .header { display: table; width: 100%; border-bottom: 2px solid #dc2626; padding-bottom: 10px; margin-bottom: 14px; }
  .header-left { display: table-cell; vertical-align: middle; }
  .header-right { display: table-cell; text-align: right; vertical-align: middle; }
  .logo { height: 32px; width: auto; display: block; }
  .company-sub { font-size: 8px; color: #6b7280; margin-top: 3px; }
  .report-title { font-size: 15px; font-weight: bold; color: #1a1a1a; }
  .report-meta { font-size: 8px; color: #6b7280; margin-top: 3px; }

  .stats { display: table; width: 100%; margin-bottom: 12px; border-collapse: separate; border-spacing: 6px 0; }
  .stat-box { display: table-cell; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 6px 10px; text-align: center; width: 20%; }
  .stat-val { font-size: 16px; font-weight: bold; color: #dc2626; }
  .stat-lbl { font-size: 7px; color: #6b7280; margin-top: 1px; }

  table { width: 100%; border-collapse: collapse; }
  thead tr { background: #dc2626; color: white; }
  thead th { padding: 5px 6px; text-align: left; font-size: 8px; font-weight: bold; }
  tbody tr:nth-child(even) { background: #f8fafc; }
  tbody td { padding: 4px 6px; font-size: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
  .muted { color: #9ca3af; font-style: italic; }
  .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7px; font-weight: bold; }
  .badge-publish { background: #dcfce7; color: #16a34a; }
  .badge-draft   { background: #f1f5f9; color: #64748b; }
  .badge-other   { background: #fef3c7; color: #b45309; }
  .text-right { text-align: right; }

  .section-title { font-size: 10px; font-weight: bold; color: #dc2626; margin: 10px 0 4px; border-bottom: 1px solid #fca5a5; padding-bottom: 2px; }

  .footer { margin-top: 12px; padding-top: 8px; border-top: 1px solid #e2e8f0; display: table; width: 100%; }
  .footer-left { display: table-cell; font-size: 7px; color: #9ca3af; }
  .footer-right { display: table-cell; text-align: right; font-size: 7px; color: #9ca3af; }
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
      <div class="report-title">Produse discontinued fără furnizor</div>
      <div class="report-meta">Generat: {{ now()->format('d.m.Y H:i') }}</div>
    </div>
  </div>

  {{-- Stats --}}
  <div class="stats">
    <div class="stat-box">
      <div class="stat-val">{{ $products->count() }}</div>
      <div class="stat-lbl">Total produse</div>
    </div>
    <div class="stat-box">
      <div class="stat-val">{{ $products->where('status', 'publish')->count() }}</div>
      <div class="stat-lbl">Publicate pe site</div>
    </div>
    <div class="stat-box">
      <div class="stat-val">{{ $products->where('stoc', '>', 0)->count() }}</div>
      <div class="stat-lbl">Cu stoc fizic rămas</div>
    </div>
    <div class="stat-box">
      <div class="stat-val">{{ number_format($products->sum('valoare'), 0, ',', '.') }}</div>
      <div class="stat-lbl">Valoare stoc rămas (RON)</div>
    </div>
    <div class="stat-box">
      <div class="stat-val">{{ number_format($products->sum('stoc'), 0, '.', '') }}</div>
      <div class="stat-lbl">Cantitate totală (buc)</div>
    </div>
  </div>

  {{-- Produse cu stoc --}}
  @php $withStock = $products->where('stoc', '>', 0); @endphp
  @if($withStock->isNotEmpty())
  <div class="section-title">Produse cu stoc fizic rămas — de lichidat ({{ $withStock->count() }})</div>
  <table>
    <thead>
      <tr>
        <th style="width:3%">#</th>
        <th style="width:14%">SKU</th>
        <th style="width:38%">Denumire</th>
        <th style="width:10%">Brand</th>
        <th style="width:8%; text-align:right">Stoc</th>
        <th style="width:9%; text-align:right">Preț (RON)</th>
        <th style="width:10%; text-align:right">Valoare (RON)</th>
        <th style="width:8%; text-align:center">Status</th>
      </tr>
    </thead>
    <tbody>
      @foreach($withStock as $i => $p)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td style="font-family: monospace">{{ $p->sku }}</td>
        <td>{{ $p->name }}</td>
        <td>{{ $p->brand ?: '—' }}</td>
        <td class="text-right">{{ number_format($p->stoc, 0, '.', '') }}</td>
        <td class="text-right">{{ number_format($p->price, 2, ',', '.') }}</td>
        <td class="text-right">{{ number_format($p->valoare, 2, ',', '.') }}</td>
        <td style="text-align:center">
          <span class="badge badge-{{ $p->status === 'publish' ? 'publish' : ($p->status === 'draft' ? 'draft' : 'other') }}">
            {{ $p->status }}
          </span>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  {{-- Produse fără stoc --}}
  @php $noStock = $products->where('stoc', '<=', 0); @endphp
  @if($noStock->isNotEmpty())
  <div class="section-title">Produse fără stoc — pot fi arhivate ({{ $noStock->count() }})</div>
  <table>
    <thead>
      <tr>
        <th style="width:3%">#</th>
        <th style="width:14%">SKU</th>
        <th style="width:52%">Denumire</th>
        <th style="width:10%">Brand</th>
        <th style="width:9%; text-align:right">Preț (RON)</th>
        <th style="width:12%; text-align:center">Status</th>
      </tr>
    </thead>
    <tbody>
      @foreach($noStock as $i => $p)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td style="font-family: monospace">{{ $p->sku }}</td>
        <td>{{ $p->name }}</td>
        <td>{{ $p->brand ?: '—' }}</td>
        <td class="text-right">{{ number_format($p->price, 2, ',', '.') }}</td>
        <td style="text-align:center">
          <span class="badge badge-{{ $p->status === 'publish' ? 'publish' : ($p->status === 'draft' ? 'draft' : 'other') }}">
            {{ $p->status }}
          </span>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  <div class="footer">
    <div class="footer-left">ERP Malinco — Produse discontinued fără furnizor activ</div>
    <div class="footer-right">{{ now()->format('d.m.Y') }}</div>
  </div>

</div>
</body>
</html>
