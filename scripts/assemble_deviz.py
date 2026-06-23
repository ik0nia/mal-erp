#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import json

found_rows = open('/tmp/deviz_found.html', encoding='utf-8').read()
mod_rows = open('/tmp/deviz_modrows.html', encoding='utf-8').read()
cards = open('/tmp/deviz_cards.html', encoding='utf-8').read()
t = json.load(open('/tmp/deviz_totals.json'))
f = t['fmt']

CSS = '''
    * { box-sizing: border-box; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #F5F0E8; color: #2c2c2c; font-size: 14px; line-height: 1.5; }
    .page { max-width: 980px; margin: 40px auto; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.10); }
    .header { background: #B81C24; color: #fff; padding: 32px 40px 28px; display: flex; align-items: center; justify-content: space-between; }
    .header-logo { font-size: 26px; font-weight: 800; letter-spacing: 1px; }
    .header-logo span { color: #ffcdd0; }
    .header-meta { text-align: right; font-size: 13px; opacity: .9; }
    .header-meta strong { display: block; font-size: 20px; font-weight: 700; margin-bottom: 2px; }
    .info-row { background: #f9f4ec; border-bottom: 1px solid #e8dfd0; padding: 16px 40px; display: flex; gap: 40px; font-size: 13px; flex-wrap: wrap; }
    .info-block strong { display: block; color: #888; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 2px; }
    .intro-section { padding: 32px 40px 8px; }
    .intro-lead { font-size: 14.5px; line-height: 1.75; color: #333; margin-bottom: 22px; border-left: 4px solid #B81C24; padding-left: 16px; }
    /* price structure */
    .pstruct { display:flex; gap:16px; margin-bottom:26px; flex-wrap:wrap; }
    .pstruct .pbox { flex:1; min-width:200px; border:1px solid #e8dfd0; border-radius:8px; padding:16px 18px; background:#fdfaf6; }
    .pstruct .pbox.found { border:2px solid #B81C24; background:#fdf3f3; }
    .pstruct .pbox .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#888; font-weight:700; }
    .pstruct .pbox.found .lbl { color:#B81C24; }
    .pstruct .pbox .amt { font-size:24px; font-weight:800; color:#1a1a1a; margin-top:4px; }
    .pstruct .pbox .sub { font-size:12px; color:#777; margin-top:2px; }
    .oblig { background:#B81C24; color:#fff; font-size:9px; font-weight:700; padding:1px 8px; border-radius:20px; letter-spacing:.5px; margin-left:6px; vertical-align:middle; }
    .modules-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 26px; }
    .module-card { border: 1px solid #e8dfd0; border-radius: 6px; padding: 14px 16px; background: #fdfaf6; }
    .module-card.foundation { border:2px solid #B81C24; background:#fdf3f3; grid-column: 1 / -1; }
    .module-card-title { font-size: 13px; font-weight: 700; color: #B81C24; margin-bottom: 7px; display: flex; align-items: center; gap: 8px; }
    .module-card-title .icon { width:22px; height:22px; background:#B81C24; border-radius:4px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:12px; color:#fff; font-weight:800; }
    .module-card p { font-size: 12px; color: #444; line-height: 1.55; margin-bottom: 8px; }
    .module-pills { display: flex; flex-wrap: wrap; gap: 5px; }
    .pill { font-size: 10.5px; padding: 3px 9px; border-radius: 20px; font-weight: 600; }
    .pill-green { background: #d4edda; color: #155724; }
    .pill-blue { background: #d1ecf1; color: #0c5460; }
    .pill-red { background: #f8d7da; color: #842029; }
    .intro-divider { border: none; border-top: 1px solid #e8dfd0; margin: 4px 0 0; }
    table { width: 100%; border-collapse: collapse; }
    thead th { background: #2c2c2c; color: #fff; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: .6px; font-weight: 600; }
    thead th:first-child { padding-left: 40px; }
    thead th:last-child { padding-right: 40px; text-align: right; }
    thead th.right { text-align: right; }
    thead th.center { text-align: center; }
    tbody tr:nth-child(even) { background: #faf8f5; }
    td { padding: 9px 12px; border-bottom: 1px solid #ede8df; vertical-align: top; }
    td:first-child { padding-left: 40px; }
    td:last-child { padding-right: 40px; text-align: right; font-weight: 600; }
    td.right { text-align: right; }
    td.center { text-align: center; }
    .item-name { font-weight: 600; color: #1a1a1a; }
    .item-desc { font-size: 12px; color: #666; margin-top: 3px; }
    .badge { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 20px; text-transform: uppercase; letter-spacing: .4px; }
    .badge-done { background: #d4edda; color: #155724; }
    tr.cat-row td { background: #f0ebe1; border-left: 4px solid #B81C24; padding: 8px 12px 8px 36px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #B81C24; border-bottom: none; }
    tr.cat-row.mandatory td { background: #B81C24; color: #fff; border-left-color: #7a1218; }
    tr.subtotal-row td { background:#2c2c2c; color:#fff; font-weight:700; font-size:12px; padding:7px 12px; text-align:right; border-bottom:2px solid #fff; }
    tr.subtotal-row td:first-child { padding-left:40px; text-align:left; text-transform:uppercase; letter-spacing:.4px; }
    tr.subtotal-row td:last-child { padding-right:40px; color:#ffcdd0; }
    .totals { padding: 22px 40px 28px; display: flex; justify-content: flex-end; }
    .totals-box { min-width: 360px; }
    .totals-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #ede8df; font-size: 14px; }
    .totals-row.grand { border-bottom: none; border-top: 2px solid #B81C24; margin-top: 6px; padding-top: 12px; font-size: 18px; font-weight: 800; color: #B81C24; }
    .totals-row .label { color: #555; }
    .note { background: #f9f4ec; border-top: 1px solid #e8dfd0; padding: 18px 40px; font-size: 12px; color: #666; line-height: 1.7; }
    .note strong { color: #333; }
    .footer { background: #B81C24; color: rgba(255,255,255,.8); text-align: center; padding: 12px 40px; font-size: 11px; }
    .footer strong { color: #fff; }
    @page { size: A4 portrait; margin: 10mm; }
    @media print {
      * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
      body { background: #fff; font-size: 9.5px; }
      .page { box-shadow: none; margin: 0; max-width: none; width: 100%; border-radius: 0; overflow: visible !important; }
      .header { padding: 16px 24px 12px; }
      .header-logo { font-size: 22px; }
      .intro-section { padding: 14px 24px 4px; }
      .info-row { padding: 9px 24px; gap: 22px; }
      .note, .totals { padding-left: 24px; padding-right: 24px; }
      .intro-lead { margin-bottom: 12px; font-size: 11px; }
      .pstruct { margin-bottom: 14px; }
      .pstruct, .pbox { break-inside: avoid; }
      .modules-grid, .intro-divider { display: none !important; }
      thead { display: table-header-group; }
      td { padding: 4px 10px; }
      td:first-child { padding-left: 24px; }
      td:last-child { padding-right: 24px; }
      tr.cat-row td { padding: 5px 10px 5px 24px; }
      .item-name { font-size: 10px; }
      .item-desc { font-size: 8.5px; line-height: 1.28; margin-top: 1px; }
      td { padding: 3px 10px; }
      tr { break-inside: avoid; }
      tr.cat-row, tr.cat-row td { break-after: avoid; }
      tr.subtotal-row, tr.subtotal-row td { break-before: avoid; }
      .totals, .note { break-inside: avoid; }
      .footer { padding: 9px 24px; }
    }
    @media (max-width: 760px){ .modules-grid{ grid-template-columns:1fr; } }
'''

LEAD = ('Platforma ERP Malinco centralizează într-un singur sistem toate operațiunile companiei: achiziții, stocuri, '
        'comenzi online, relația cu furnizorii, integrarea contabilă WinMentor și raportarea de business. Prezentul deviz '
        f'este structurat pe o <strong>fundație de arhitectură obligatorie</strong> și <strong>{t["n_modules"]} module funcționale</strong>, '
        'fiecare cu prețul evidențiat separat. Orele reflectă efortul real de dezvoltare livrat (analiză, implementare, testare, '
        'documentație). Fundația de infrastructură este <strong>necesară pentru funcționarea oricărui modul</strong> și se facturează o singură dată.')

html = f'''<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Deviz Lucrări — ERP Malinco</title>
<style>{CSS}</style>
</head>
<body>
<div class="page">

  <div class="header">
    <div>
      <div class="header-logo">MALINCO<span>.RO</span></div>
      <div style="font-size:13px; opacity:.85; margin-top:4px;">Platformă ERP Internă — Deviz Lucrări</div>
    </div>
    <div class="header-meta">
      <strong>DEVIZ NR. 2026-002</strong>
      Data emiterii: 22 Iunie 2026<br>
      Perioadă: Feb 2026 – Iun 2026
    </div>
  </div>

  <div class="info-row">
    <div class="info-block"><strong>Beneficiar</strong> Malinco Prodex S.R.L.<br>erp.malinco.ro</div>
    <div class="info-block"><strong>Prestator</strong> Ikonia Agency S.R.L.<br>Laravel / Filament / WooCommerce</div>
    <div class="info-block"><strong>Rata oră</strong> 70 EUR / oră<br>(Senior Full-Stack)</div>
    <div class="info-block"><strong>Stack tehnic</strong> PHP 8.3 · Laravel 13 · Filament 4 · Go<br>MySQL · Redis · WinMentor · AI</div>
  </div>

  <div class="intro-section">
    <p class="intro-lead">{LEAD}</p>

    <div class="pstruct">
      <div class="pbox found">
        <div class="lbl">Fundație obligatorie <span class="oblig">OBLIGATORIU</span></div>
        <div class="amt">{f['found_eur']} €</div>
        <div class="sub">{t['found_h']}h · arhitectura fără de care niciun modul nu funcționează</div>
      </div>
      <div class="pbox">
        <div class="lbl">Platformă integrată</div>
        <div class="amt">{t['n_modules']} module</div>
        <div class="sub">sistem unitar · modulele partajează date și fluxuri</div>
      </div>
      <div class="pbox">
        <div class="lbl">Total platformă (fără TVA)</div>
        <div class="amt" style="color:#B81C24;">{f['subtotal']} €</div>
        <div class="sub">{t['total_h']}h · {f['total_tva']} € cu TVA 21%</div>
      </div>
    </div>

    <div style="background:#fdf3f3;border-left:4px solid #B81C24;border-radius:4px;padding:12px 16px;margin-bottom:22px;font-size:13px;color:#7a1218;">
      <strong>Platformă integrată — vândută ca întreg.</strong> Modulele de mai jos nu sunt componente independente: ele partajează aceleași date și fluxuri de business (ex: comandă online → necesar → comandă furnizor → recepție → stoc → preț pe site). Defalcarea pe module arată unde s-a investit efortul, dar platforma funcționează doar ca sistem unitar și se livrează complet — nu à la carte.
    </div>

    <div class="modules-grid">
{cards}
    </div>
    <hr class="intro-divider">
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:46%">Descriere lucrare</th>
        <th class="center" style="width:10%">Ore</th>
        <th class="right" style="width:14%">Tarif (EUR)</th>
        <th class="right" style="width:16%">Total (EUR)</th>
        <th class="right" style="width:14%">Status</th>
      </tr>
    </thead>
    <tbody>
{found_rows}

{mod_rows}
    </tbody>
  </table>

  <div class="totals">
    <div class="totals-box">
      <div class="totals-row"><span class="label">Fundație obligatorie ({t['found_h']}h)</span><span class="value">{f['found_eur']} EUR</span></div>
      <div class="totals-row"><span class="label">Total ore lucrate</span><span class="value">{t['total_h']} ore</span></div>
      <div class="totals-row"><span class="label">Total platformă (fără TVA)</span><span class="value">{f['subtotal']} EUR</span></div>
      <div class="totals-row"><span class="label">TVA 21%</span><span class="value">{f['tva']} EUR</span></div>
      <div class="totals-row grand"><span class="label">TOTAL CU TVA</span><span class="value">{f['total_tva']} EUR</span></div>
    </div>
  </div>

  <div class="note">
    <strong>Note:</strong>
    <ul style="margin-top:6px; padding-left:18px;">
      <li>Tariful de <strong>70 EUR/oră</strong> reflectă rata pentru senior full-stack development (PHP/Laravel/Filament + Go + integrări AI + DevOps).</li>
      <li><strong>Fundația de arhitectură este obligatorie</strong> — fără ea niciun modul nu poate funcționa (panel-uri, multi-tenant, autentificare, permisiuni, cozi, orchestrarea fluxurilor între module).</li>
      <li><strong>Platforma este un sistem integrat</strong> și se livrează complet. Modulele partajează date și fluxuri și nu se vând à la carte; defalcarea de mai sus arată distribuția efortului de dezvoltare, nu pachete independente.</li>
      <li>Estimările de ore includ analiză, implementare, testare, debugging și documentație, la efort real de livrare.</li>
      <li>Devizul reflectă valoarea de dezvoltare a platformei. Găzduirea, suportul și mentenanța sunt acoperite de oferta comercială (abonament) — vezi oferta separată.</li>
    </ul>
  </div>

  <div class="footer">
    <strong>ERP Malinco</strong> &nbsp;·&nbsp; Deviz pe baza codului sursă real &nbsp;·&nbsp; 22 Iunie 2026
  </div>

</div>
</body>
</html>'''

open('/var/www/erp/public/deviz-erp-malinco.html','w',encoding='utf-8').write(html)
print('Scris deviz, len', len(html))
print('div balance:', html.count('<div'), html.count('</div>'))
