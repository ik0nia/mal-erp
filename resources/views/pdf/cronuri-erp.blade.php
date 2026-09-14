<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 26mm 16mm 20mm 16mm; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1f2933; font-size: 10.5px; line-height: 1.5; }

    /* Header / footer pe fiecare pagină */
    .pheader { position: fixed; top: -18mm; left: 0; right: 0; height: 12mm;
               border-bottom: 1.5px solid #b45309; color: #92400e; font-size: 9px; }
    .pheader .l { float: left; font-weight: bold; letter-spacing: .5px; }
    .pheader .r { float: right; color: #7a8899; }
    .pfooter { position: fixed; bottom: -14mm; left: 0; right: 0; height: 10mm;
               border-top: .8px solid #d9dee5; color: #9aa5b1; font-size: 8px; padding-top: 3px; }
    .pfooter .r { float: right; }
    .pagenum:after { content: counter(page); }

    /* Cover */
    .cover { text-align: center; padding-top: 55mm; }
    .cover .kicker { color: #b45309; font-size: 12px; letter-spacing: 3px; font-weight: bold; }
    .cover h1 { font-size: 34px; margin: 12px 0 4px; color: #111827; }
    .cover .sub { font-size: 14px; color: #52606d; margin-bottom: 30px; }
    .cover .meta { display: inline-block; border: 1px solid #e0a458; border-radius: 6px;
                   padding: 10px 22px; color: #7a5320; font-size: 11px; background: #fdf6ec; }

    h2.section { font-size: 15px; color: #fff; background: #b45309; padding: 7px 12px;
                 border-radius: 4px; margin: 26px 0 4px; page-break-after: avoid; }
    h2.section .n { color: #ffd8a8; font-weight: normal; }
    p.lead { color: #52606d; margin: 4px 0 12px; font-size: 10px; }

    /* Card per cron */
    table.cron { width: 100%; border-collapse: collapse; margin-bottom: 8px;
                 border: 1px solid #e4e7eb; page-break-inside: avoid; }
    table.cron td { padding: 6px 9px; vertical-align: top; }
    .cmd { font-family: DejaVu Sans Mono, monospace; font-weight: bold; color: #9a3412; font-size: 10.5px; }
    .freq { float: right; background: #eef2f7; color: #3e4c59; border-radius: 3px;
            padding: 2px 8px; font-size: 8.5px; font-family: DejaVu Sans Mono, monospace; }
    .crow-head { background: #fbf7f1; border-bottom: 1px solid #eee; }
    .desc b { color: #1f2933; }
    .tag { display: inline-block; font-size: 8px; font-weight: bold; padding: 1px 5px;
           border-radius: 3px; margin-right: 4px; }
    .t-scrie { background: #dcfce7; color: #166534; }   /* scrie in DB */
    .t-push  { background: #dbeafe; color: #1e40af; }    /* push extern */
    .t-read  { background: #f3e8ff; color: #6b21a8; }    /* read-only */
    .t-mail  { background: #fef3c7; color: #92400e; }    /* alerta email */
    .links { color: #52606d; font-size: 9.3px; margin-top: 3px; }
    .links .arrow { color: #b45309; font-weight: bold; }

    .intro-box { border: 1px solid #e4e7eb; border-left: 4px solid #b45309;
                 background: #fbfbfc; padding: 10px 14px; border-radius: 3px; margin: 10px 0; }
    .intro-box code { background: #f0f2f5; padding: 1px 4px; border-radius: 3px;
                      font-family: DejaVu Sans Mono, monospace; font-size: 9.3px; color: #9a3412; }
    ul.flow { margin: 6px 0 0 0; padding-left: 16px; }
    ul.flow li { margin-bottom: 4px; }

    .legend td { padding: 4px 8px; font-size: 9px; }
    .nowrap { white-space: nowrap; }
    .small { font-size: 9px; color: #7a8899; }
</style>
</head>
<body>

<div class="pheader">
    <span class="l">TEMELIA ERP — HARTA AUTOMATIZĂRILOR</span>
    <span class="r">Sincronizări &amp; sarcini programate (cron)</span>
</div>
<div class="pfooter">
    <span class="l">Document intern — generat automat din <span class="nowrap">schedule:list</span></span>
    <span class="r">Pagina <span class="pagenum"></span></span>
</div>

{{-- ================= COVER ================= --}}
<div class="cover">
    <div class="kicker">DOCUMENTAȚIE OPERAȚIONALĂ</div>
    <h1>Harta automatizărilor ERP</h1>
    <div class="sub">Ce actualizează fiecare cron, ce leagă și cu ce logică</div>
    <div class="meta">
        Generat: {{ $date }}<br>
        {{ $count }} sarcini programate active · Sursă: <b>routes/console.php</b>
    </div>
</div>

<div style="page-break-before: always;"></div>

{{-- ================= INTRO ================= --}}
<h2 class="section">0 &nbsp;·&nbsp; Cum funcționează, pe scurt</h2>

<div class="intro-box">
    <b>Lanțul de execuție</b>
    <ul class="flow">
        <li>Un singur cron de sistem rulează în fiecare minut: <code>php artisan schedule:run</code> (crontab userul <code>erp</code>).</li>
        <li>Laravel decide ce e „scadent" la minutul curent și <b>dispatch-uiește joburile în Redis</b>; <b>Laravel Horizon</b> (sub supervisor) le procesează asincron cu 1–6 workeri.</li>
        <li>Datele din <b>WinMentor</b> vin exclusiv prin <code>WinmentorBridgeClient</code> → <b>MentorAPI</b> (bridge Go, port 9500) → COM → WinMentor. Read-only, cu excepția push-urilor explicite (PO, recepții).</li>
        <li>Datele către/de la <b>site (WooCommerce)</b> trec prin <code>WooClient</code> (REST) și prin plugin-ul <code>malinco-erp-bridge</code> (meta + flush cache).</li>
    </ul>
</div>

<table class="legend" style="width:100%; border-collapse:collapse; margin-top:10px;">
    <tr>
        <td><span class="tag t-scrie">SCRIE DB</span> populează tabele locale</td>
        <td><span class="tag t-push">PUSH SITE</span> trimite spre WooCommerce</td>
        <td><span class="tag t-read">READ-ONLY</span> doar citește din WinMentor</td>
        <td><span class="tag t-mail">ALERTĂ</span> poate trimite email</td>
    </tr>
</table>
<p class="small" style="margin-top:6px;">Notă frecvență: <b>L–S</b> = Luni–Sâmbătă (nu rulează duminica, când WinMentor n-are activitate). Orele sunt Europe/Bucharest.</p>

{{-- ================= 1. WINMENTOR STOC & VANZARI ================= --}}
<h2 class="section"><span class="n">1 ·</span> WinMentor — stoc, vânzări &amp; articole</h2>
<p class="lead">Coloana vertebrală: aduce stocul real și vânzările din WinMentor și le propagă în ERP și pe site.</p>

@php
$g1 = [
 ['winmentor:sync-stock-bridge', 'La 5 min · L–S', 't-scrie t-push',
  'Aduce <b>stocul și prețul de vânzare</b> din WinMentor (clasa 1, gestiunea configurată) și le scrie în <b>product_stocks</b> + <b>woo_products</b>.',
  'winmentor_solduri/bridge <span class="arrow">→</span> product_stocks <span class="arrow">→</span> stoc afișat pe site. Este sursa primară de adevăr pentru stoc.'],

 ['winmentor:reconcile-woo-stock', 'La :18 orar · L–S', 't-push',
  'Aliniază <b>stocul WooCommerce la stocul real ERP</b> — corecția absolută (nu delta). Repară produse „fantomă" și backorders de precomandă.',
  'product_stocks <span class="arrow">→</span> WooCommerce. Complementar cu push-ul pe delte al sync-ului de stoc.'],

 ['winmentor:watch-vanzari', 'La 5 min · L–S', 't-read t-scrie',
  'Detectează <b>vânzările noi</b> apărute în WinMentor și le salvează raw în <b>winmentor_vanzari_raw</b> (an/lună/zi, SKU, cantitate, lei_cu_tva).',
  'Bază pentru BI, velocity, recomandări PO și dispecerizare. Rulează cu mutex (fără suprapunere).'],

 ['winmentor:recompute-vanzari-net', 'Zilnic 05:40', 't-scrie',
  'Recalculează <b>lei_cu_tva</b> + <b>motiv_exclus</b> pe vânzările raw (VanzariNetService) — normalizează valorile pentru raportare corectă.',
  'winmentor_vanzari_raw <span class="arrow">→</span> valori curățate pentru BI/CA. Rezolvă dublările și TVA-ul amestecat.'],

 ['winmentor:watch-intrari', 'La 15 min · L–S', 't-read t-scrie',
  'Detectează <b>intrările de marfă</b> (recepții) noi și le procesează în <b>winmentor_intrari_raw</b> + loguri preț achiziție.',
  'Alimentează istoricul de prețuri de achiziție și asocierea furnizor–produs.'],

 ['winmentor:fetch-solduri', 'Zilnic 19:35 · L–S', 't-read t-scrie',
  'Sincronizează <b>soldurile per factură</b> (scadențarul oficial) din WinMentor.',
  'winmentor_solduri_raw <span class="arrow">→</span> fișa client 360° (sold, facturi neîncasate).'],

 ['winmentor:detect-article-changes', 'La 5 min · L–S', 't-read t-scrie t-push',
  'Detectează <b>modificări de SKU / denumire</b> în WinMentor și le sincronizează în ERP și pe site.',
  'Ține codurile și denumirile aliniate între WinMentor ↔ ERP ↔ WooCommerce.'],

 ['winmentor:check-articole-sterse', 'Zilnic 05:40', 't-read',
  'Detectează <b>articolele șterse</b> din WinMentor și le leagă de produsele ERP (marcaj, fără ștergere).',
  'Evită „produse fantomă" rămase active pe site după ștergerea din WinMentor.'],

 ['winmentor:fetch-emulare', 'Zilnic 20:45 · L–S', 't-read t-scrie',
  'Aduce <b>bonurile de emulare casă de marcat</b> din WinMentor, lună cu lună.',
  'winmentor_emulare_raw — completează imaginea vânzărilor cu bonurile de casă.'],

 ['stock:dispatch-scheduled-winmentor', 'În fiecare minut', 't-scrie',
  'Pune la coadă importurile WinMentor <b>conform programării per conexiune</b> (setări din IntegrationConnection).',
  'Orchestrator: decide ce conexiune se importă și când, fără a bloca cronul principal.'],

 ['winmentor:sync-supplier-sku', 'Zilnic 06:00', 't-read t-scrie',
  'Sincronizează <b>supplier_sku</b> din <code>codExternAlt</code> WinMentor pentru toți furnizorii.',
  'Leagă produsul de codul furnizorului <span class="arrow">→</span> asociere feed Toya și comenzi furnizor.'],

 ['winmentor:refetch-luna-precedenta', 'Zilele 3 și 10, 04:20', 't-read t-scrie',
  'Re-aduce vânzările/intrările <b>lunii precedente</b> după închiderea contabilă (datele se mai ajustează retroactiv).',
  'Corectează retroactiv raw-ul după ce luna e „închisă" în WinMentor.'],
];
@endphp
@include('pdf.partials.cron-cards', ['rows' => $g1])

{{-- ================= 2. COMENZI / LIVRARI / PO / FACTURI ================= --}}
<h2 class="section"><span class="n">2 ·</span> WinMentor — comenzi, livrări, PO &amp; facturi</h2>
<p class="lead">Leagă comenzile și PO-urile din ERP de documentele reale (recepții, facturi, livrări) din WinMentor.</p>

@php
$g2 = [
 ['sync:winmentor-comenzi', 'La 10 min · L–S', 't-read t-scrie',
  'Sincronizează <b>comenzile clienți non-CM</b>; trackuiește deschis/facturat + euristic factură vs aviz.',
  'winmentor_comenzi — WinMentor nu leagă direct comanda de factură, așa că euristica o face conservator.'],

 ['sync:winmentor-livrari', 'La 5 min · L–S', 't-read t-scrie',
  'Sincronizează <b>livrările CM</b> (case de marcat mobile) din Bridge și le trackuiește istoricul.',
  'winmentor_livrari — seria CM = comenzi trimise la livrare cu casa mobilă.'],

 ['winmentor:match-po-receptie', 'La 30 min', 't-read t-scrie',
  'Asociază <b>PO-urile ERP cu recepțiile contabile</b> din WinMentor.',
  'PurchaseOrder <span class="arrow">↔</span> winmentor_intrari_raw — închide bucla comandă furnizor → recepție.'],

 ['winmentor:match-woo-facturi', 'La 15 min (--apply)', 't-read t-scrie',
  'Asociază <b>comenzile Woo cu facturile WinMentor</b> (determinist + euristic conservator), local.',
  'WooOrder <span class="arrow">↔</span> factură WinMentor — pentru raportare și fișa client.'],

 ['winmentor:retry-failed-po-sync', 'La 30 min · L–S', 't-push t-scrie',
  'Retrimite la WinMentor <b>PO-urile eșuate sau nesincronizate (NULL)</b>.',
  'Plasă de siguranță pentru PushComenziFurnizori/Reception care au picat temporar.'],

 ['erp:associate-suppliers-from-receptions', 'Luni 05:30', 't-scrie',
  'Asociază <b>furnizori la produsele cu rulaj</b> rămase fără furnizor, din istoricul de recepții.',
  'Completează ProductSupplier acolo unde lipsește — util pentru recomandările PO.'],
];
@endphp
@include('pdf.partials.cron-cards', ['rows' => $g2])

{{-- ================= 3. PARTENERI / INCASARI ================= --}}
<h2 class="section"><span class="n">3 ·</span> WinMentor — parteneri, clienți &amp; încasări</h2>

@php
$g3 = [
 ['winmentor:sync-parteneri', 'Zilnic 00:05 + orar L–S', 't-read t-scrie',
  'Sincronizează <b>partenerii</b> din Bridge în <b>winmentor_parteneri</b> + reconciliază winmentor_id la furnizori.',
  'Sursa pentru CUI/denumiri furnizori. Reconcilierea previne coruperea winmentor_id (eroarea 213).'],

 ['winmentor:import-parteneri-clienti', 'Zilnic 00:20', 't-scrie',
  'Importă <b>partenerii reali ca clienți ERP</b>, legați prin winmentor_partner_id.',
  'winmentor_parteneri <span class="arrow">→</span> Customer (fișa client cu date live din WinMentor).'],

 ['winmentor:fetch-incasari-plati', 'La :40 orar · L–S', 't-read t-scrie',
  'Sincronizează <b>încasările de la clienți și plățile către furnizori</b>.',
  'winmentor_incasari_raw / winmentor_plati_raw — cash-flow și solduri.'],

 ['winmentor:fetch-incasari-clienti', '2h / 21:50 / dum. 02:30', 't-read t-scrie',
  'Istoric <b>încasări bancare per client</b> (GetIncasariClienti). Trei ritmuri: recent (2 zile), zilnic (10 zile), săptămânal complet (--toti).',
  'winmentor_incasari_clienti <span class="arrow">→</span> fișa client 360° (istoric plăți).'],
];
@endphp
@include('pdf.partials.cron-cards', ['rows' => $g3])

{{-- ================= 4. WOOCOMMERCE / SITE ================= --}}
<h2 class="section"><span class="n">4 ·</span> Site WooCommerce</h2>

@php
$g4 = [
 ['woo:sync-orders', 'La 15 min', 't-read t-scrie',
  'Aduce local <b>comenzile de pe site</b> (WooOrder + WooOrderItem), inclusiv statusuri și date client.',
  'Sursă pentru modulul „Comenzi", AWB Sameday și match-ul cu facturile WinMentor.'],

 ['woo:sync-categories', 'La 6 ore', 't-read t-scrie',
  'Sincronizează <b>categoriile WooCommerce</b> pentru toate conexiunile active.',
  'woo_categories — ține arborele de categorii aliniat cu site-ul.'],
];
@endphp
@include('pdf.partials.cron-cards', ['rows' => $g4])

{{-- ================= 5. TOYA ================= --}}
<h2 class="section"><span class="n">5 ·</span> Furnizor Toya (feed API)</h2>
<p class="lead">Aduce prețuri de achiziție și stocuri Toya și le împinge pe site pentru cele ~12.500 de produse.</p>

@php
$g5 = [
 ['toya:sync-prices', 'Orar 07–19', 't-scrie t-push t-mail',
  'Aduce din API-ul Toya <b>prețuri</b> (getPricesRo) și <b>stocuri</b> (getStocksRo). Mapează cantitatea furnizor (LARGE/MEDIUM/SMALL → <b>instock</b>, OUT OF STOCK → <b>outofstock</b>) și pushează pe site DOAR ce s-a schimbat.',
  'Feed Toya <span class="arrow">→</span> woo_products (stock_status/preț) <span class="arrow">→</span> WooCommerce. Dacă feedul de stoc vine gol, statusurile rămân „înghețate" și trimite alertă email. Nu publică singur draft-urile (vezi nota).'],
];
@endphp
@include('pdf.partials.cron-cards', ['rows' => $g5])

<div class="intro-box" style="border-left-color:#9a3412;">
    <b>De ce un produs Toya în stoc rămâne „draft"?</b><br>
    Sincronizarea actualizează <b>doar câmpul de stoc</b> în baza locală; ea <u>nu schimbă niciodată statusul de publicare</u>.
    Publicarea (draft → publish) este o decizie <b>editorială separată</b>, făcută explicit cu <code>toya:push-to-woo</code>.
    De aceea un draft poate arăta „instock" dar nu apare pe site până nu e publicat manual. Cele 51 de draft-uri curente
    au fost importate la <b>17 martie</b>, au conținut complet, iar multe sunt <b>duplicate</b> de denumire — de aceea au rămas nepublicate.
</div>

{{-- ================= 6. AWB / SAMEDAY ================= --}}
<h2 class="section"><span class="n">6 ·</span> Curierat Sameday (AWB)</h2>

@php
$g6 = [
 ['awb:sync-courier-status', 'La 15 min · 08–21', 't-read t-scrie',
  'Sincronizează în bloc <b>statusurile AWB</b> Sameday (un apel status-sync la 2h).',
  'SamedayAwb <span class="arrow">→</span> status tracking vizibil în ERP.'],

 ['awb:refresh-courier-status', ':35 orar 08–21 (limit 6)', 't-read t-scrie',
  'Împrospătează individual tracking-ul pentru un lot mic de AWB-uri active.',
  'Completează sync-ul bulk cu detalii per colet, cu delay între apeluri.'],

 ['awb:refresh-courier-status --cod', 'Zilnic 09:30 (limit 40)', 't-read t-scrie',
  'Refresh dedicat AWB-urilor <b>cu ramburs (COD)</b> — prioritate pe încasări.',
  'Urmărește confirmarea livrării și a rambursului.'],

 ['sameday:sync-awbs-from-site', 'La 30 min', 't-read t-scrie',
  'Aduce în ERP <b>AWB-urile create pe site</b> (wp_sameday_awb).',
  'Site <span class="arrow">→</span> SamedayAwb — unifică AWB-urile din ambele surse.'],

 ['sameday:sync-lockers', 'Zilnic 06:50', 't-read t-scrie',
  'Sincronizează <b>căsuțele Easybox</b> (API Sameday live, fallback site).',
  'Listă locker-e pentru selecția la creare AWB.'],
];
@endphp
@include('pdf.partials.cron-cards', ['rows' => $g6])

{{-- ================= 7. BI / RAPOARTE ================= --}}
<h2 class="section"><span class="n">7 ·</span> Business Intelligence &amp; rapoarte</h2>
<p class="lead">Transformă vânzările raw în KPI, velocity, alerte de stoc și rapoarte periodice.</p>

@php
$g7 = [
 ['bi:compute-daily', 'Zilnic 00:30', 't-scrie',
  'Rulează toate agregările BI pentru <b>ieri</b>, în ordine: <b>KPI → Velocity → Alerts → Replenishment → Margin</b>.',
  'winmentor_vanzari_raw + product_stocks <span class="arrow">→</span> tabelele bi_*. Ziua e „înghețată" după miezul nopții.'],

 ['bi:health-check', 'Zilnic 09:00', 't-scrie t-mail',
  'Watchdog BI: detectează și <b>repară automat</b> zilele lipsă din bi_inventory_kpi_daily.',
  'Plasă de siguranță dacă compute-daily a ratat o zi (ex. weekend fără vânzări).'],

 ['erp:compute-abc-classification', 'Zilnic 01:00', 't-scrie',
  'Calculează clasificarea <b>ABC/XYZ</b> a produselor pe baza vânzărilor.',
  'Prioritizează produsele pentru reaprovizionare și analiză.'],

 ['stock:snapshot-daily-metrics', 'Zilnic 17:30 · L–S', 't-scrie',
  'Snapshot zilnic <b>stoc + preț</b> din product_stocks/woo_products.',
  'daily_stock_metrics <span class="arrow">→</span> graficele de evoluție stoc/preț.'],

 ['bi:compute-seasonality', 'Lunar, ziua 1, 01:15', 't-scrie',
  'Calculează <b>sezonalitatea lunară</b> per produs din istoricul multi-anual.',
  'Ajustează cererea estimată pe sezon în recomandările de aprovizionare.'],

 ['bi:generate-weekly-report', 'Duminică 05:00', 't-scrie t-mail',
  'Generează <b>raportul BI săptămânal</b> (P0/P1/P2 + velocity).',
  'Din tabelele bi_* <span class="arrow">→</span> raport livrat.'],

 ['bi:generate-monthly-report', 'Ziua 1, 11:00', 't-scrie t-mail',
  'Generează <b>raportul BI lunar</b> (30 zile, grupat săptămânal + velocity).',
  'Sinteză lunară pentru management.'],

 ['bi:generate-period-report', 'Trim./sem./anual, ziua 1', 't-scrie t-mail',
  'Generează rapoartele <b>trimestrial, semestrial și anual</b> (variante ale aceleiași comenzi).',
  'Rapoarte de perioadă pe orizont lung.'],
];
@endphp
@include('pdf.partials.cron-cards', ['rows' => $g7])

{{-- ================= 8. EMAIL / OFERTE / SOCIAL / ALTE ================= --}}
<h2 class="section"><span class="n">8 ·</span> Email, oferte, dispecerizare &amp; diverse</h2>

@php
$g8 = [
 ['App\\Jobs\\FetchEmailsJob', 'La 5 min', 't-scrie',
  'Aduce emailurile noi prin IMAP (folosind <code>SINCE &lt;dată&gt;</code>, RAM-safe) în EmailMessage.',
  'Alimentează modulul de comunicări furnizori + procesarea AI a prețurilor.'],

 ['email:parse-supplier-docs', 'Zilnic 02:00', 't-scrie t-mail',
  'Parsează <b>PDF/XLSX-urile din emailurile furnizorilor</b> și generează raport comparativ cu WinMentor.',
  'Atașamente furnizor <span class="arrow">→</span> prețuri/oferte extrase + raport diferențe.'],

 ['offers:expire', 'Zilnic 00:30', 't-scrie',
  'Marchează <b>ofertele trimise cu valabilitatea depășită</b> ca expirate.',
  'Ține pipeline-ul de ofertare curat (Offer.status).'],

 ['sm:publish-scheduled', 'În fiecare minut', 't-push',
  'Publică <b>postările social media programate</b> când le vine ora.',
  'Modulul Social Media Generator <span class="arrow">→</span> platforme.'],

 ['dispecer:warm', 'În fiecare minut · L–S', 't-read',
  'Pre-încălzește <b>cache-ul vânzărilor zilei</b> (bonuri reale via GetInfoBonExt) pentru dispecerizare.',
  'Pagina /app/dispecer se deschide instant cu date live, fără așteptare.'],

 ['wh:cleanup-reception-drafts', 'Zilnic 03:00', 't-scrie',
  'Șterge <b>draft-urile de recepție</b> ale comenzilor la care recepția cantitativă s-a finalizat.',
  'Curăță magazia de recepții „fantomă" rămase după finalizare.'],
];
@endphp
@include('pdf.partials.cron-cards', ['rows' => $g8])

{{-- ================= 9. INFRA / MONITORIZARE ================= --}}
<h2 class="section"><span class="n">9 ·</span> Infrastructură &amp; monitorizare</h2>

@php
$g9 = [
 ['horizon:snapshot', 'La 5 min', 't-scrie',
  'Salvează un <b>snapshot al metricelor cozii</b> Horizon (throughput, timpi).',
  'Alimentează dashboard-ul /horizon.'],

 ['monitoring:probe', 'La 5 min', 't-mail',
  'Sondează <b>disponibilitatea aplicației și a bridge-ului WinMentor</b>.',
  'Detectează timpuriu dacă ERP sau MentorAPI cade.'],

 ['erp:workers-health-check', 'La 30 min', 't-mail',
  'Verifică <b>sănătatea workerilor</b> și trimite email dacă ceva e picat.',
  'Garantează că joburile din coadă chiar se procesează.'],

 ['security:detect-anomalies', 'La 15 min', 't-scrie t-mail',
  'Detectează tipare suspecte (<b>brute-force login</b>) și creează automat breșe.',
  'Log-uri autentificare <span class="arrow">→</span> alerte de securitate.'],

 ['mentorapi:com-reset', 'Zilnic 05:00', 't-scrie',
  'Resetează conexiunea <b>COM a MentorAPI</b> (igienă zilnică a bridge-ului Windows).',
  'Previne blocaje ale obiectului COM WinMentor (stare globală).'],

 ['winmentor:archive-logs', 'Lunar, ziua 1, 02:00', 't-scrie',
  'Arhivează <b>logurile WinMentor mai vechi de 30 zile</b> în ZIP-uri lunare.',
  'Ține sub control dimensiunea logurilor.'],

 ['Curățare sync_runs', 'Zilnic 00:15', 't-scrie',
  'Șterge din <b>sync_runs</b> rulările mai vechi de 30 de zile (closure în routes/console.php).',
  'Menține tabela de istoric sincronizări compactă.'],
];
@endphp
@include('pdf.partials.cron-cards', ['rows' => $g9])

<p class="small" style="margin-top:18px; border-top:1px solid #e4e7eb; padding-top:8px;">
    Document generat automat pentru {{ $count }} sarcini programate din <b>routes/console.php</b>.
    Frecvențele reflectă configurarea la data generării. Pentru starea live: <code>php artisan schedule:list</code>
    și dashboard-ul <b>/horizon</b>.
</p>

</body>
</html>
