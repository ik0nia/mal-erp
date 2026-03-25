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
  .report-title { font-size: 15px; font-weight: bold; color: #1a1a1a; }
  .report-meta { font-size: 9px; color: #6b7280; margin-top: 3px; }

  .summary { display: table; width: 100%; margin-bottom: 14px; }
  .summary-box { display: table-cell; padding: 8px 12px; border-radius: 4px; text-align: center; width: 25%; }
  .summary-box .num { font-size: 18px; font-weight: bold; }
  .summary-box .lbl { font-size: 8px; color: #6b7280; margin-top: 2px; }
  .box-total  { background: #f1f5f9; }
  .box-ok     { background: #dcfce7; }
  .box-disc   { background: #fef3c7; }
  .box-loss   { background: #fee2e2; }

  table { width: 100%; border-collapse: collapse; }
  thead tr { background: #1e3a5f; color: white; }
  thead th { padding: 5px 6px; text-align: left; font-size: 8px; font-weight: bold; }
  thead th.right { text-align: right; }
  tbody tr:nth-child(even) { background: #f8fafc; }
  tbody tr.loss { background: #fff1f2; }
  tbody td { padding: 4px 6px; font-size: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
  tbody td.right { text-align: right; font-family: DejaVu Sans Mono, monospace; }
  .plus  { color: #16a34a; font-weight: bold; }
  .minus { color: #dc2626; font-weight: bold; }
  .badge-loss { display: inline-block; padding: 1px 4px; border-radius: 3px; font-size: 7px; font-weight: bold; background: #fee2e2; color: #dc2626; }

  .section-title { font-size: 11px; font-weight: bold; color: #1e3a5f; margin: 12px 0 6px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }

  .footer { margin-top: 14px; padding-top: 8px; border-top: 1px solid #e2e8f0; display: table; width: 100%; }
  .footer-left  { display: table-cell; font-size: 8px; color: #9ca3af; }
  .footer-right { display: table-cell; text-align: right; font-size: 8px; color: #9ca3af; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <div class="header-left">
      <div class="report-title">Raport Discrepanțe Preț de Vânzare</div>
      <div class="report-meta">Sursă: LISTA PRODUSE PRET ACHIZITIE.xlsx (WinMentor) vs ERP</div>
    </div>
    <div class="header-right">
      <div class="report-meta">Generat: {{ now()->format('d.m.Y H:i') }}</div>
      <div class="report-meta">Toleranță aplicată: &lt; 0.05 RON sau &lt; 2%</div>
    </div>
  </div>

  <div class="summary">
    <div class="summary-box box-total">
      <div class="num">{{ $totalCompared }}</div>
      <div class="lbl">Produse comparate</div>
    </div>
    <div class="summary-box box-ok">
      <div class="num">{{ $totalOk }}</div>
      <div class="lbl">Prețuri OK</div>
    </div>
    <div class="summary-box box-disc">
      <div class="num">{{ $discrepancies->count() }}</div>
      <div class="lbl">Discrepanțe</div>
    </div>
    <div class="summary-box box-loss">
      <div class="num">{{ $lossCount }}</div>
      <div class="lbl">Vândute în pierdere</div>
    </div>
  </div>

  @php $losses = $discrepancies->where('is_loss', true); @endphp
  @if($losses->count())
  <div class="section-title">⚠ Produse vândute în pierdere ({{ $losses->count() }})</div>
  <table>
    <thead>
      <tr>
        <th>EAN</th>
        <th>Denumire</th>
        <th class="right">Preț DB</th>
        <th class="right">Breakeven</th>
        <th class="right">Pierdere/buc</th>
        <th class="right">Preț Achiz (G)</th>
      </tr>
    </thead>
    <tbody>
      @foreach($losses->sortBy('db_price') as $r)
      <tr class="loss">
        <td>{{ $r['ean'] }}</td>
        <td>{{ $r['name'] }}</td>
        <td class="right">{{ number_format($r['db_price'], 2) }} RON</td>
        <td class="right">{{ number_format($r['breakeven'], 2) }} RON</td>
        <td class="right"><span class="minus">-{{ number_format($r['breakeven'] - $r['db_price'], 2) }} RON</span></td>
        <td class="right">{{ number_format($r['excel_g'], 2) }} RON</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  <div class="section-title">Toate discrepanțele ({{ $discrepancies->count() }}) — sortate după diferență absolută</div>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>EAN</th>
        <th>Denumire</th>
        <th class="right">Preț Excel (H)</th>
        <th class="right">Preț DB</th>
        <th class="right">Diferență</th>
        <th class="right">%</th>
        <th class="right">Achiz (G)</th>
        <th class="right">Breakeven</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach($discrepancies as $i => $r)
      <tr class="{{ $r['is_loss'] ? 'loss' : '' }}">
        <td>{{ $i + 1 }}</td>
        <td>{{ $r['ean'] }}</td>
        <td>{{ $r['name'] }}</td>
        <td class="right">{{ number_format($r['excel_h'], 2) }}</td>
        <td class="right">{{ number_format($r['db_price'], 2) }}</td>
        <td class="right">
          @if($r['diff'] > 0)
            <span class="plus">+{{ number_format($r['diff'], 2) }}</span>
          @else
            <span class="minus">{{ number_format($r['diff'], 2) }}</span>
          @endif
        </td>
        <td class="right">
          @if($r['diff_pct'] > 0)
            <span class="plus">+{{ number_format($r['diff_pct'], 1) }}%</span>
          @else
            <span class="minus">{{ number_format($r['diff_pct'], 1) }}%</span>
          @endif
        </td>
        <td class="right">{{ number_format($r['excel_g'], 2) }}</td>
        <td class="right">{{ number_format($r['breakeven'], 2) }}</td>
        <td>@if($r['is_loss'])<span class="badge-loss">PIERDERE</span>@endif</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="footer">
    <div class="footer-left">Malinco ERP — Raport intern confidențial</div>
    <div class="footer-right">{{ now()->format('d.m.Y') }}</div>
  </div>

</div>
</body>
</html>
