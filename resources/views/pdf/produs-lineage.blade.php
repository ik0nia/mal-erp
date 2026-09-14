<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 26mm 15mm 20mm 15mm; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1f2933; font-size: 10.5px; line-height: 1.5; }

    .pheader { position: fixed; top: -18mm; left: 0; right: 0; height: 12mm;
               border-bottom: 1.5px solid #b45309; color: #92400e; font-size: 9px; }
    .pheader .l { float: left; font-weight: bold; letter-spacing: .5px; }
    .pheader .r { float: right; color: #7a8899; }
    .pfooter { position: fixed; bottom: -14mm; left: 0; right: 0; height: 10mm;
               border-top: .8px solid #d9dee5; color: #9aa5b1; font-size: 8px; padding-top: 3px; }
    .pfooter .r { float: right; }
    .pagenum:after { content: counter(page); }

    .cover { text-align: center; padding-top: 48mm; }
    .cover .kicker { color: #b45309; font-size: 12px; letter-spacing: 3px; font-weight: bold; }
    .cover h1 { font-size: 30px; margin: 12px 0 4px; color: #111827; }
    .cover .sub { font-size: 13px; color: #52606d; margin-bottom: 26px; }
    .cover .meta { display: inline-block; border: 1px solid #e0a458; border-radius: 6px;
                   padding: 10px 22px; color: #7a5320; font-size: 11px; background: #fdf6ec; }

    h2.section { font-size: 15px; color: #fff; background: #b45309; padding: 7px 12px;
                 border-radius: 4px; margin: 24px 0 4px; page-break-after: avoid; }
    h2.section .n { color: #ffd8a8; font-weight: normal; }
    p.lead { color: #52606d; margin: 4px 0 12px; font-size: 10px; }

    /* Sistem badges (culori) */
    .sys { display: inline-block; font-size: 8.5px; font-weight: bold; padding: 2px 7px;
           border-radius: 3px; white-space: nowrap; }
    .s-wm   { background: #dbeafe; color: #1e40af; }   /* WinMentor */
    .s-toya { background: #ffedd5; color: #9a3412; }   /* Toya */
    .s-woo  { background: #ede9fe; color: #6b21a8; }   /* WooCommerce / site */
    .s-erp  { background: #e5e7eb; color: #374151; }   /* ERP intern */
    .s-ai   { background: #dcfce7; color: #166534; }   /* AI */

    /* Anatomie fisa produs */
    table.anat { width: 100%; border-collapse: collapse; margin: 6px 0 4px; page-break-inside: avoid; }
    table.anat th { background: #1f2933; color: #fff; text-align: left; padding: 6px 9px; font-size: 10px; }
    table.anat td { border-bottom: 1px solid #eceff3; padding: 5px 9px; vertical-align: top; }
    table.anat tr:nth-child(even) td { background: #fafbfc; }
    .fld { font-weight: bold; color: #1f2933; }
    .val { font-family: DejaVu Sans Mono, monospace; color: #0b7285; font-size: 9.5px; }
    .note { color: #7a8899; font-size: 8.8px; }

    /* Pseudocod */
    pre.code { background: #0f172a; color: #e2e8f0; border-radius: 6px; padding: 12px 14px;
               font-family: DejaVu Sans Mono, monospace; font-size: 9.2px; line-height: 1.6;
               page-break-inside: avoid; margin: 8px 0; }
    pre.code .kw { color: #f0a868; font-weight: bold; }   /* keywords */
    pre.code .cm { color: #64748b; }                       /* comment */
    pre.code .wm { color: #7cc0ff; font-weight: bold; }
    pre.code .ty { color: #ffb37a; font-weight: bold; }
    pre.code .wo { color: #c9a8ff; font-weight: bold; }

    .box { border: 1px solid #e4e7eb; border-left: 4px solid #b45309;
           background: #fbfbfc; padding: 9px 13px; border-radius: 3px; margin: 8px 0; }
    .box code { background: #f0f2f5; padding: 1px 4px; border-radius: 3px;
                font-family: DejaVu Sans Mono, monospace; font-size: 9.3px; color: #9a3412; }

    table.line { width: 100%; border-collapse: collapse; margin: 6px 0; font-size: 9.3px; }
    table.line th { background: #f0ece6; color: #6b4a1f; text-align: left; padding: 6px 8px; border-bottom: 2px solid #d9c3a0; }
    table.line td { border-bottom: 1px solid #eceff3; padding: 5px 8px; vertical-align: top; }
    table.line tr:nth-child(even) td { background: #fafbfc; }
    .arrow { color: #b45309; font-weight: bold; }
    .small { font-size: 9px; color: #7a8899; }
    .flowline { text-align: center; font-size: 11px; margin: 10px 0; color: #334155; }
    .flowline .sys { font-size: 9.5px; }
</style>
</head>
<body>

<div class="pheader">
    <span class="l">TEMELIA ERP — PROVENIENȚA DATELOR UNUI PRODUS</span>
    <span class="r">Data lineage</span>
</div>
<div class="pfooter">
    <span class="l">Document intern</span>
    <span class="r">Pagina <span class="pagenum"></span></span>
</div>

{{-- COVER --}}
<div class="cover">
    <div class="kicker">GHID VIZUAL</div>
    <h1>De unde vin datele unui produs</h1>
    <div class="sub">Fiecare câmp de pe fișă, sursa lui reală și logica de prioritate</div>
    <div class="meta">
        Generat: {{ $date }}<br>
        Exemplu real: <b>„{{ $ex['name'] }}"</b>
    </div>

    <div style="margin-top:34px;">
        <span class="sys s-wm">WinMentor</span> &nbsp;
        <span class="sys s-toya">Toya (feed)</span> &nbsp;
        <span class="sys s-woo">WooCommerce (site)</span> &nbsp;
        <span class="sys s-erp">ERP intern</span> &nbsp;
        <span class="sys s-ai">AI</span>
    </div>
    <p class="small" style="margin-top:8px;">Culoarea = sistemul din care provine data.</p>
</div>

<div style="page-break-before: always;"></div>

{{-- 1. IDEEA DE BAZA --}}
<h2 class="section"><span class="n">1 ·</span> Ideea de bază</h2>
<div class="box">
    Un produs este <b>o singură fișă</b> în tabela <code>woo_products</code> din ERP. Dar câmpurile ei
    <b>nu vin dintr-un singur loc</b>: stocul și prețul vin din WinMentor sau din feed-ul Toya (după o
    regulă de prioritate), conținutul (nume/descriere/imagini) vine din import + AI, iar <code>woo_id</code>
    este firul care leagă fișa de produsul viu de pe site.
</div>

<div class="flowline">
    <span class="sys s-wm">WinMentor</span> &nbsp;+&nbsp;
    <span class="sys s-toya">Toya</span> &nbsp;<span class="arrow">&rarr;</span>&nbsp;
    <span class="sys s-erp">woo_products (fișa ERP)</span> &nbsp;<span class="arrow">&rarr;</span>&nbsp;
    <span class="sys s-woo">produsul de pe site</span>
</div>

<p class="lead">Fiecare produs are un câmp <code style="color:#9a3412">source</code> care spune de unde a apărut fișa:</p>
<table class="line">
    <tr><th>source</th><th>Ce înseamnă</th><th>Nr. produse</th></tr>
    <tr><td><span class="sys s-toya">toya_api</span></td><td>Importat din feed-ul API Toya</td><td>12.529</td></tr>
    <tr><td><span class="sys s-woo">woocommerce</span></td><td>Importat direct de pe site</td><td>9.583</td></tr>
    <tr><td><span class="sys s-wm">winmentor_bridge</span></td><td>Venit din WinMentor prin bridge</td><td>1.883</td></tr>
    <tr><td><span class="sys s-wm">winmentor_csv</span></td><td>Import istoric din CSV WinMentor</td><td>91</td></tr>
</table>

{{-- 2. ANATOMIA --}}
<h2 class="section"><span class="n">2 ·</span> Anatomia unei fișe (exemplu real)</h2>
<p class="lead">Produs: <b>„{{ $ex['name'] }}"</b> — SKU {{ $ex['sku'] }}, fără stoc în WinMentor, deci stocul &amp; prețul vin de la <b>Toya</b>.</p>

<table class="anat">
    <tr><th style="width:26%">Câmp pe fișă</th><th style="width:30%">Valoare (exemplu)</th><th style="width:20%">Sursă reală</th><th style="width:24%">Cum ajunge acolo</th></tr>
    <tr><td class="fld">name / descriere</td><td class="val">{{ \Illuminate\Support\Str::limit($ex['name'],28) }}</td><td><span class="sys s-toya">Toya</span> + <span class="sys s-ai">AI</span></td><td class="note">import Toya; descriere generată cu Claude</td></tr>
    <tr><td class="fld">main_image_url</td><td class="val">pim.toya.pl/…</td><td><span class="sys s-toya">Toya</span></td><td class="note">toya:download-images &rarr; product_images</td></tr>
    <tr><td class="fld">categorie</td><td class="val">{{ \Illuminate\Support\Str::limit($ex['cat'],26) }}</td><td><span class="sys s-erp">ERP</span></td><td class="note">categorisire (propuneri + validare)</td></tr>
    <tr><td class="fld">regular_price (vânzare)</td><td class="val">{{ $ex['price'] }} lei</td><td><span class="sys s-toya">Toya</span> + <span class="sys s-erp">adaos</span></td><td class="note">preț achiziție Toya + adaos ERP</td></tr>
    <tr><td class="fld">purchase_price (achiziție)</td><td class="val">{{ $ex['purchase'] }} lei</td><td><span class="sys s-toya">Toya</span></td><td class="note">feed getPricesRo &rarr; product_suppliers</td></tr>
    <tr><td class="fld">stock_status</td><td class="val">{{ $ex['stock'] }}</td><td><span class="sys s-toya">Toya</span></td><td class="note">feed getStocksRo (mapat pe cantitate)</td></tr>
    <tr><td class="fld">supplier (furnizor)</td><td class="val">Toya (id {{ $ex['supplier_id'] }})</td><td><span class="sys s-erp">ERP</span></td><td class="note">product_suppliers (din recepții/feed)</td></tr>
    <tr><td class="fld">woo_id</td><td class="val">{{ $ex['woo_id'] }}</td><td><span class="sys s-woo">site</span></td><td class="note">creat la publicare (toya:push-to-woo)</td></tr>
    <tr><td class="fld">supplier_sku (cheie feed)</td><td class="val">YT-xxxxx</td><td><span class="sys s-wm">WinMentor</span></td><td class="note">codExternAlt &rarr; leagă fișa de feed Toya</td></tr>
</table>

<div class="box" style="border-left-color:#1e40af;">
    <b>Dacă același produs AR avea stoc în WinMentor</b>, atunci <b>stock_status</b> și <b>regular_price</b> ar deveni
    <span class="sys s-wm">WinMentor</span> în loc de <span class="sys s-toya">Toya</span> — restul câmpurilor rămân la fel.
    Asta e regula de prioritate de mai jos.
</div>

{{-- 3. PSEUDOCOD --}}
<h2 class="section"><span class="n">3 ·</span> Regula de aur — cine „câștigă" pe stoc &amp; preț</h2>
<p class="lead">Rulează la fiecare sincronizare. WinMentor are întâietate; Toya completează doar ce WinMentor nu acoperă.</p>

<pre class="code"><span class="cm"># Pentru fiecare produs, la sincronizare:</span>
<span class="kw">pentru fiecare</span> produs <span class="kw">în</span> woo_products:

    are_stoc_winmentor = (product_stocks.quantity &gt; 0)   <span class="cm"># exista in gestiune?</span>

    <span class="kw">dacă</span> are_stoc_winmentor:
        stoc         &larr; <span class="wm">WinMentor Bridge</span>     <span class="cm"># winmentor:sync-stock-bridge (5 min)</span>
        preț_vânzare &larr; <span class="wm">WinMentor Bridge</span>     <span class="cm"># clasa 1, gestiunea configurată</span>
        <span class="cm"># Toya NU atinge acest produs (hasWmStock = true)</span>

    <span class="kw">altfel dacă</span> source == <span class="ty">"toya_api"</span>:
        stoc  &larr; <span class="ty">feed Toya getStocksRo</span>       <span class="cm"># toya:sync-prices (orar 07-19)</span>
              <span class="cm"># LARGE/MEDIUM/SMALL QTY -&gt; instock ; OUT OF STOCK -&gt; outofstock</span>
        preț  &larr; <span class="ty">achiziție Toya</span> + adaos ERP

    <span class="cm"># Se scrie in ERP DOAR daca s-a schimbat ceva (fara rescrieri inutile)</span>
    <span class="kw">dacă</span> valoare_nouă != valoare_veche:
        scrie în woo_products
        push &rarr; <span class="wo">WooCommerce</span>              <span class="cm"># doar produse publicate (status=publish)</span>

<span class="cm"># Plasa de siguranta, o data pe ora:</span>
winmentor:reconcile-woo-stock  <span class="cm"># aliniaza absolut stocul de pe site la ERP</span></pre>

<div class="box">
    <b>Trei consecințe practice ale regulii:</b>
    <ul style="margin:4px 0 0 0; padding-left:16px;">
        <li>Un produs care e și în WinMentor, și la Toya, își ia stocul/prețul <b>din WinMentor</b> — Toya e ignorat pentru el.</li>
        <li>Dacă feed-ul Toya vine gol, produsele „doar Toya" rămân cu ultimul stoc (îngheţat) + <b>alertă email</b>.</li>
        <li>Un produs <b>draft</b> primește stoc actualizat în ERP, dar <b>nu e împins pe site</b> (push doar pt. <code>publish</code>).</li>
    </ul>
</div>

{{-- 4. TABEL PROVENIENTA --}}
<h2 class="section"><span class="n">4 ·</span> Proveniența fiecărui câmp (referință)</h2>
<table class="line">
    <tr><th style="width:22%">Câmp</th><th style="width:26%">Sursă primară</th><th style="width:30%">Cron / mecanism</th><th style="width:22%">Ajunge în</th></tr>
    <tr><td class="fld">stoc</td><td><span class="sys s-wm">WinMentor</span> &gt; <span class="sys s-toya">Toya</span></td><td class="note">sync-stock-bridge / toya:sync-prices</td><td class="note">product_stocks, woo_products</td></tr>
    <tr><td class="fld">preț vânzare</td><td><span class="sys s-wm">WinMentor</span> &gt; <span class="sys s-toya">Toya</span>+adaos</td><td class="note">sync-stock-bridge / toya:sync-prices</td><td class="note">woo_products.regular_price</td></tr>
    <tr><td class="fld">preț achiziție</td><td><span class="sys s-wm">WinMentor</span> / <span class="sys s-toya">Toya</span></td><td class="note">watch-intrari / toya:sync-prices</td><td class="note">product_suppliers, price logs</td></tr>
    <tr><td class="fld">furnizor</td><td><span class="sys s-erp">ERP</span></td><td class="note">din recepții + feed + email</td><td class="note">product_suppliers</td></tr>
    <tr><td class="fld">nume</td><td><span class="sys s-toya">Toya</span> / <span class="sys s-wm">WinMentor</span></td><td class="note">import Toya / winmentor_name</td><td class="note">woo_products.name</td></tr>
    <tr><td class="fld">descriere</td><td><span class="sys s-ai">AI (Claude)</span></td><td class="note">toya:generate-descriptions</td><td class="note">woo_products.description</td></tr>
    <tr><td class="fld">imagini</td><td><span class="sys s-toya">Toya</span></td><td class="note">toya:download-images / import-images</td><td class="note">product_images</td></tr>
    <tr><td class="fld">categorie</td><td><span class="sys s-erp">ERP</span></td><td class="note">categorisire (propuneri + validare)</td><td class="note">woo_product_category</td></tr>
    <tr><td class="fld">comenzi</td><td><span class="sys s-woo">site</span></td><td class="note">woo:sync-orders (15 min)</td><td class="note">woo_orders</td></tr>
    <tr><td class="fld">vânzări</td><td><span class="sys s-wm">WinMentor</span></td><td class="note">winmentor:watch-vanzari (5 min)</td><td class="note">winmentor_vanzari_raw</td></tr>
</table>

{{-- 5. CHEILE DE LEGATURA --}}
<h2 class="section"><span class="n">5 ·</span> Cheile care leagă sistemele</h2>
<p class="lead">Cum se recunoaște „același produs" în trei sisteme diferite:</p>
<table class="line">
    <tr><th style="width:30%">Cheia</th><th>Leagă</th></tr>
    <tr><td><span class="val">woo_products.woo_id</span></td><td><span class="sys s-erp">fișa ERP</span> <span class="arrow">&harr;</span> <span class="sys s-woo">produsul de pe site</span></td></tr>
    <tr><td><span class="val">product_suppliers.supplier_sku</span></td><td><span class="sys s-erp">fișa ERP</span> <span class="arrow">&harr;</span> <span class="sys s-toya">codul intern Toya (YT-xxxxx)</span></td></tr>
    <tr><td><span class="val">winmentor_id / winmentor_name</span></td><td><span class="sys s-erp">fișa ERP</span> <span class="arrow">&harr;</span> <span class="sys s-wm">articolul WinMentor</span></td></tr>
    <tr><td><span class="val">product_stocks.woo_product_id</span></td><td><span class="sys s-wm">stocul WinMentor</span> <span class="arrow">&harr;</span> <span class="sys s-erp">fișa ERP</span></td></tr>
</table>

<p class="small" style="margin-top:16px; border-top:1px solid #e4e7eb; padding-top:8px;">
    Logica de prioritate reflectă <code>SyncToyaPricesJob</code> (guard <code>hasWmStock</code>) și <code>winmentor:sync-stock-bridge</code>.
    Exemplu extras live din baza de date la generare.
</p>

</body>
</html>
