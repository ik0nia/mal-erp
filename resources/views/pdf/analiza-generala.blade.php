<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 90px 40px 60px 40px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9.5px; color: #1f2937; }
    header { position: fixed; top: -65px; left: 0; right: 0; height: 50px;
             border-bottom: 3px solid #e05d10; padding-bottom: 6px; }
    header .title { font-size: 16px; font-weight: bold; color: #111827; }
    header .sub { font-size: 9px; color: #6b7280; }
    footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 8px; color: #9ca3af;
             border-top: 1px solid #e5e7eb; padding-top: 4px; }
    h2 { font-size: 12.5px; color: #e05d10; margin: 18px 0 6px 0; border-bottom: 1px solid #f3d5c0; padding-bottom: 3px; }
    h3 { font-size: 10.5px; margin: 12px 0 4px 0; color: #374151; }
    p, li { line-height: 1.45; }
    table { width: 100%; border-collapse: collapse; margin: 6px 0 10px 0; }
    th { background: #1f2937; color: #fff; padding: 5px 6px; font-size: 8.5px; text-align: left; }
    th.num, td.num { text-align: right; }
    td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
    tr:nth-child(even) td { background: #f9fafb; }
    .pos { color: #047857; font-weight: bold; }
    .neg { color: #b91c1c; font-weight: bold; }
    .warn { color: #b45309; font-weight: bold; }
    .muted { color: #6b7280; }
    .kpi { display: inline-block; width: 22%; background: #f9fafb; border: 1px solid #e5e7eb;
           border-left: 4px solid #e05d10; padding: 8px 10px; margin-right: 1.2%; margin-bottom: 6px; vertical-align: top; }
    .kpi .val { font-size: 14px; font-weight: bold; }
    .kpi .lbl { font-size: 7.5px; color: #6b7280; text-transform: uppercase; }
    .note { background: #fffbeb; border: 1px solid #fde68a; padding: 8px 10px; font-size: 8.5px; margin: 8px 0; }
    .alert { background: #fef2f2; border: 1px solid #fecaca; padding: 8px 10px; font-size: 8.5px; margin: 8px 0; }
    .pagebreak { page-break-before: always; }
</style>
</head>
<body>
@php
    function n($v) { return number_format((float)$v, 0, ',', '.'); }
@endphp
<header>
    <div class="title">Raport analiză: stocuri, prețuri achiziție & furnizori</div>
    <div class="sub">Malinco · calculat integral din date brute WinMentor + ERP (fără stratul BI) · perioadă referință: 90 zile (09.06 – 07.09.2026) · generat {{ $TODAY }}</div>
</header>
<footer>Raport generat automat din ERP Malinco · {{ $TODAY }} · valorile sunt RON fără TVA dacă nu se specifică altfel</footer>

<h2>1. Sumar executiv</h2>
<div>
    <div class="kpi"><div class="val">{{ n($kpiStock->valoare_stoc_net) }}</div><div class="lbl">Valoare stoc (net, preț vânzare)</div></div>
    <div class="kpi"><div class="val">{{ n($kpiStock->produse_in_stoc) }}</div><div class="lbl">Produse în stoc / {{ n($kpiActive->produse_active) }} active</div></div>
    <div class="kpi"><div class="val">{{ n($kpiSales->vanzari90) }}</div><div class="lbl">Vânzări 90 zile (net)</div></div>
    <div class="kpi"><div class="val">{{ n($kpiSales->produse_vandute) }}</div><div class="lbl">Produse vândute în 90 zile</div></div>
</div>
<div>
    <div class="kpi" style="border-left-color:#b91c1c;"><div class="val neg">{{ n($ruptSummary->produse) }}</div><div class="lbl">Rupturi active (vândute, stoc 0)</div></div>
    <div class="kpi" style="border-left-color:#b91c1c;"><div class="val neg">~{{ n($ruptSummary->pierdere30) }}</div><div class="lbl">Vânzări pierdute estimate / 30 zile</div></div>
    <div class="kpi" style="border-left-color:#b45309;"><div class="val warn">{{ n($incrSummary->total) }}</div><div class="lbl">Produse cu preț achiziție ↑ (90z)</div></div>
    <div class="kpi" style="border-left-color:#b45309;"><div class="val warn">{{ n($notAdjustedCount) }}</div><div class="lbl">…din care FĂRĂ ajustare preț vânzare</div></div>
</div>
<p>
    Stocul curent valorează <b>{{ n($kpiStock->valoare_stoc_tva) }} RON cu TVA</b> ({{ n($kpiStock->valoare_stoc_net) }} net).
    În ultimele 90 de zile s-au vândut {{ n($kpiSales->produse_vandute) }} produse distincte, în valoare de {{ n($kpiSales->vanzari90) }} RON net
    (documente + retail casă). <b>{{ n($ruptSummary->produse) }} produse cu vânzări recente sunt acum pe stoc zero</b>, cu vânzări pierdute
    estimate la ~{{ n($ruptSummary->pierdere30) }} RON/lună. {{ n($incrSummary->total) }} produse au preț de achiziție crescut cu &gt;3%
    ({{ n($incrSummary->peste10) }} cu peste 10%), iar la {{ n($notAdjustedCount) }} dintre ele prețul de vânzare nu a fost modificat de la scumpire.
    Capital blocat în produse fără nicio vânzare în 90 zile: <b>{{ n($deadTotal->valoare_net) }} RON</b> ({{ n($deadTotal->produse) }} produse).
</p>

<h2>2. Imagine de ansamblu — vânzări lunare 2026 vs 2025</h2>
@php
    $docs = []; foreach ($salesDocs as $r) $docs[$r->an][$r->luna] = $r->val;
    $ret = []; foreach ($salesRetail as $r) $ret[$r->an][$r->luna] = $r->val;
    $luni = [3=>'Martie',4=>'Aprilie',5=>'Mai',6=>'Iunie',7=>'Iulie',8=>'August'];
@endphp
<table>
    <tr><th>Luna</th><th class="num">Documente 2025</th><th class="num">Documente 2026</th><th class="num">Δ%</th>
        <th class="num">Retail casă 2025</th><th class="num">Retail casă 2026</th><th class="num">Δ%</th><th class="num">Total 2026</th></tr>
    @foreach ($luni as $l => $nume)
    @php
        $d25 = $docs[2025][$l] ?? 0; $d26 = $docs[2026][$l] ?? 0;
        $r25 = $ret[2025][$l] ?? 0; $r26 = $ret[2026][$l] ?? 0;
        $dp = $d25 ? round(($d26/$d25-1)*100,1) : null; $rp = $r25 ? round(($r26/$r25-1)*100,1) : null;
    @endphp
    <tr>
        <td>{{ $nume }}</td>
        <td class="num">{{ n($d25) }}</td><td class="num">{{ n($d26) }}</td>
        <td class="num {{ $dp>=0?'pos':'neg' }}">{{ $dp !== null ? ($dp>=0?'+':'').$dp.'%' : '—' }}</td>
        <td class="num">{{ n($r25) }}</td><td class="num">{{ n($r26) }}</td>
        <td class="num {{ $rp>=0?'pos':'neg' }}">{{ $rp !== null ? ($rp>=0?'+':'').$rp.'%' : '—' }}</td>
        <td class="num"><b>{{ n($d26+$r26) }}</b></td>
    </tr>
    @endforeach
</table>
<p class="muted">Documente = avize + facturi + bonuri native Mentor (ex-TVA); Retail casă = bonuri magazin din emulare (ex-TVA). Fluxurile sunt separate și nu se dublează. Articolele speciale de plăți sunt excluse.</p>

<h3>Evoluție stoc (valoare cu TVA, la preț de vânzare)</h3>
<table>
    <tr><th>Data</th>@foreach ($stockTrend as $r)<th class="num">{{ \Carbon\Carbon::parse($r->day)->format('d.m') }}</th>@endforeach<th class="num">azi</th></tr>
    <tr><td>Valoare stoc</td>@foreach ($stockTrend as $r)<td class="num">{{ n($r->valoare_tva) }}</td>@endforeach<td class="num"><b>{{ n($kpiStock->valoare_stoc_tva) }}</b></td></tr>
    <tr><td>Produse în stoc</td>@foreach ($stockTrend as $r)<td class="num">{{ n($r->in_stoc) }}</td>@endforeach<td class="num"><b>{{ n($kpiStock->produse_in_stoc) }}</b></td></tr>
</table>
<div class="note"><b>Notă date:</b> pe 10 iunie 2026 au dispărut ~3.900 de rânduri de produse din metrici (curățare/merge produse duplicate), de aceea valorile dinainte de 10 iunie (ex. 4,56M pe 9 iunie) nu sunt comparabile. Trendul de mai sus pornește după acest eveniment.</div>

<div class="pagebreak"></div>
<h2>3. Mișcări de stoc — ultimele 30 zile</h2>
<h3>Top scăderi de stoc (valoare net)</h3>
<table>
    <tr><th>SKU</th><th>Produs</th><th class="num">Stoc 08.08</th><th class="num">Stoc azi</th><th class="num">Δ valoare (RON)</th></tr>
    @foreach ($stockDown as $r)
    <tr><td>{{ $r->sku }}</td><td>{{ mb_substr($r->name,0,55) }}</td>
        <td class="num">{{ n($r->q_atunci) }}</td><td class="num">{{ n($r->q_acum) }}</td>
        <td class="num neg">{{ n($r->dif_val) }}</td></tr>
    @endforeach
</table>
<h3>Top creșteri de stoc (aprovizionări/retururi)</h3>
<table>
    <tr><th>SKU</th><th>Produs</th><th class="num">Stoc 08.08</th><th class="num">Stoc azi</th><th class="num">Δ valoare (RON)</th></tr>
    @foreach ($stockUp as $r)
    <tr><td>{{ $r->sku }}</td><td>{{ mb_substr($r->name,0,55) }}</td>
        <td class="num">{{ n($r->q_atunci) }}</td><td class="num">{{ n($r->q_acum) }}</td>
        <td class="num pos">+{{ n($r->dif_val) }}</td></tr>
    @endforeach
</table>

<h3>Capital blocat — stoc fără nicio vânzare în 90 zile</h3>
<p>{{ n($deadTotal->produse) }} produse active au stoc dar zero vânzări în 90 de zile — <b>{{ n($deadTotal->valoare_net) }} RON</b> (net) imobilizați. Top 15:</p>
<table>
    <tr><th>SKU</th><th>Produs</th><th class="num">Cantitate</th><th class="num">Valoare net (RON)</th></tr>
    @foreach ($deadTop as $r)
    <tr><td>{{ $r->sku }}</td><td>{{ mb_substr($r->name,0,60) }}</td><td class="num">{{ n($r->quantity) }}</td><td class="num">{{ n($r->valoare_net) }}</td></tr>
    @endforeach
</table>

<div class="pagebreak"></div>
<h2>4. Creșteri preț de achiziție — ultimele 90 zile</h2>
<p>
    <b>{{ n($incrSummary->total) }} produse</b> au ultimul preț de achiziție mai mare cu &gt;3% față de prețul anterior
    ({{ n($incrSummary->peste10) }} peste +10%, {{ n($incrSummary->peste20) }} peste +20%; medie +{{ $incrSummary->medie_pct }}%).
    „Impact 90z" = diferența de preț × cantitatea vândută în 90 zile (cost suplimentar la volumul actual).
</p>
<h3>Top 20 după impact financiar</h3>
<table>
    <tr><th>SKU</th><th>Produs</th><th>Furnizor</th><th class="num">Preț vechi</th><th class="num">Preț nou</th><th class="num">Δ%</th><th class="num">Data</th><th class="num">Impact 90z</th></tr>
    @foreach ($incrImpactTop as $r)
    <tr><td>{{ $r->sku }}</td><td>{{ mb_substr($r->name,0,42) }}</td><td>{{ mb_substr($r->supplier_name ?? '—',0,22) }}</td>
        <td class="num">{{ number_format($r->old_price,2,',','.') }}</td><td class="num">{{ number_format($r->new_price,2,',','.') }}</td>
        <td class="num warn">+{{ $r->pct }}%</td>
        <td class="num">{{ \Carbon\Carbon::parse($r->change_date)->format('d.m') }}</td>
        <td class="num">{{ n($r->impact90) }}</td></tr>
    @endforeach
</table>

<h3>Agregat pe furnizori (min. 2 produse scumpite)</h3>
<table>
    <tr><th>Furnizor</th><th class="num">Produse scumpite</th><th class="num">Medie Δ%</th><th class="num">Impact 90z (RON)</th><th>Responsabili</th></tr>
    @foreach ($incrSupp as $r)
    <tr><td>{{ mb_substr($r->supplier_name,0,40) }}</td><td class="num">{{ $r->produse }}</td>
        <td class="num">+{{ $r->medie_pct }}%</td><td class="num">{{ n($r->impact90) }}</td>
        <td class="{{ $r->responsabili=='—' ? 'neg' : '' }}">{{ $r->responsabili }}</td></tr>
    @endforeach
</table>

<div class="pagebreak"></div>
<h2>5. ⚠ Preț achiziție crescut, preț de vânzare NEajustat</h2>
<div class="alert">
    Din {{ n($incrSummary->total) }} produse scumpite la achiziție, la <b>{{ n($notAdjustedCount) }}</b> nu există nicio creștere a prețului de vânzare
    de la data scumpirii. Mai jos: cele cu vânzări active, sortate după adaosul rămas (cel mai mic primul).
    <b>{{ $notAdjustedNegative }}</b> dintre ele au adaos sub 10% (sau negativ) — acestea vând în pierdere sau aproape de pierdere.
</div>
<table>
    <tr><th>SKU</th><th>Produs</th><th>Furnizor</th><th class="num">Achiziție nou</th><th class="num">Δ%</th><th class="num">Vânzare net</th><th class="num">Adaos rămas</th><th class="num">Vândut 90z</th></tr>
    @foreach ($notAdjustedTop as $r)
    <tr><td>{{ $r->sku }}</td><td>{{ mb_substr($r->name,0,40) }}</td><td>{{ mb_substr($r->supplier_name ?? '—',0,20) }}</td>
        <td class="num">{{ number_format($r->new_price,2,',','.') }}</td>
        <td class="num warn">+{{ $r->pct }}%</td>
        <td class="num">{{ $r->sell_price ? number_format($r->sell_price/1.21,2,',','.') : '—' }}</td>
        <td class="num {{ $r->margin !== null && $r->margin < 10 ? 'neg' : '' }}">{{ $r->margin !== null ? $r->margin.'%' : '—' }}</td>
        <td class="num">{{ n($r->qty90) }}</td></tr>
    @endforeach
</table>
<p class="muted">Adaos rămas = (preț vânzare fără TVA ÷ preț achiziție nou − 1). Prețuri achiziție fără TVA, din intrările WinMentor.</p>

<div class="pagebreak"></div>
<h2>6. Rupturi de stoc pe furnizori</h2>
<p>
    <b>{{ n($ruptSummary->produse) }} produse</b> active (tip stoc, nediscontinuate) cu vânzări în ultimele 90 zile au acum stoc zero.
    Pierdere estimată: <b>~{{ n($ruptSummary->pierdere30) }} RON/lună</b> (net), calculată din ritmul de vânzare pe 90 zile.
</p>
<h3>Pe furnizori — unde sunt cele mai mari probleme și cine răspunde</h3>
<table>
    <tr><th>Furnizor</th><th class="num">Produse rupte</th><th class="num">Pierdere est./lună (RON)</th><th>Responsabili</th></tr>
    @foreach ($ruptSupp as $r)
    <tr><td>{{ mb_substr($r->supplier_name,0,42) }}</td><td class="num">{{ $r->produse }}</td>
        <td class="num neg">{{ n($r->pierdere30) }}</td>
        <td class="{{ $r->responsabili=='—' ? 'neg' : '' }}">{{ $r->responsabili }}</td></tr>
    @endforeach
</table>

<h3>Top 25 produse rupte (după pierdere estimată)</h3>
<table>
    <tr><th>SKU</th><th>Produs</th><th>Furnizor</th><th class="num">Vândut 90z (buc)</th><th class="num">Zile fără stoc /30</th><th class="num">Pierdere/lună</th></tr>
    @foreach ($ruptTop as $r)
    <tr><td>{{ $r->sku }}</td><td>{{ mb_substr($r->name,0,42) }}</td><td>{{ mb_substr($r->supplier_name,0,20) }}</td>
        <td class="num">{{ n($r->qty90) }}</td><td class="num">{{ $r->zile_rupt30 ?? '—' }}</td>
        <td class="num neg">{{ n($r->pierdere30) }}</td></tr>
    @endforeach
</table>

<div class="pagebreak"></div>
<h2>7. Achiziții — statistici și puncte de blocaj</h2>
<h3>Volum achiziții 90 zile (intrări WinMentor)</h3>
<p>Total intrări: <b>{{ n($purchTotal->valoare) }} RON</b> ({{ n($purchTotal->documente) }} documente). Top furnizori:</p>
<table>
    <tr><th>Furnizor</th><th class="num">Valoare 90z (RON)</th><th class="num">Documente</th></tr>
    @foreach ($purchTopSupp as $r)
    <tr><td>{{ mb_substr($r->supplier_name,0,50) }}</td><td class="num">{{ n($r->valoare) }}</td><td class="num">{{ $r->documente }}</td></tr>
    @endforeach
</table>

<h3>Comenzi furnizori (PO) — toate din ERP</h3>
<table>
    <tr><th>Status</th><th class="num">Număr</th><th class="num">Valoare (RON)</th></tr>
    @foreach ($poByStatus as $r)<tr><td>{{ $r->status }}</td><td class="num">{{ $r->nr }}</td><td class="num">{{ n($r->valoare) }}</td></tr>@endforeach
</table>
<h3>Activitate pe cumpărători (PO create în ultimele 90 zile)</h3>
<table>
    <tr><th>Cumpărător</th><th class="num">PO-uri</th><th class="num">Valoare (RON)</th><th class="num">Recepționate</th><th class="num">Lead time mediu (zile)</th></tr>
    @foreach ($poByBuyer as $r)
    <tr><td>{{ $r->buyer }}</td><td class="num">{{ $r->nr }}</td><td class="num">{{ n($r->valoare) }}</td>
        <td class="num">{{ $r->receptionate }}</td><td class="num">{{ $r->lead_mediu ?? '—' }}</td></tr>
    @endforeach
</table>
<h3>Top furnizori după valoare PO (90 zile)</h3>
<table>
    <tr><th>Furnizor</th><th class="num">PO-uri</th><th class="num">Valoare (RON)</th><th class="num">Lead time mediu</th></tr>
    @foreach ($poTopSupp as $r)
    <tr><td>{{ mb_substr($r->supplier_name,0,45) }}</td><td class="num">{{ $r->nr }}</td><td class="num">{{ n($r->valoare) }}</td><td class="num">{{ $r->lead_mediu ?? '—' }}</td></tr>
    @endforeach
</table>

@if ($poStaleCount->nr > 0)
<h3>PO-uri blocate (deschise de peste 14 zile: {{ $poStaleCount->nr }}, {{ n($poStaleCount->valoare) }} RON)</h3>
<table>
    <tr><th>Număr</th><th>Furnizor</th><th>Status</th><th class="num">Valoare</th><th class="num">Creat</th><th class="num">Zile</th></tr>
    @foreach ($poStale as $r)
    <tr><td>{{ $r->number }}</td><td>{{ mb_substr($r->supplier_name,0,35) }}</td><td>{{ $r->status }}</td>
        <td class="num">{{ n($r->valoare) }}</td><td class="num">{{ $r->creat }}</td><td class="num neg">{{ $r->zile }}</td></tr>
    @endforeach
</table>
@endif

<h3>Alte semnale în achiziții</h3>
<ul>
    <li>Cereri de achiziție: @foreach ($prByStatus as $r){{ $r->status }}: {{ $r->nr }}@if(!$loop->last), @endif @endforeach · articole în așteptare (pending): <b>{{ $prItemsPending->nr }}</b></li>
    <li><b>{{ n($noSupplier->produse) }} produse vândute în 90 zile nu au niciun furnizor asociat în ERP</b> (vânzări de {{ n($noSupplier->vanzari90) }} RON) — nu pot intra în fluxul de reaprovizionare.
        Top: @foreach ($noSupplierTop as $r){{ mb_substr($r->name,0,30) }} ({{ n($r->val90) }} RON)@if(!$loop->last); @endif @endforeach</li>
    <li>Anomalii de preț nerevizuite din importul WinMentor: <b>{{ n($anomalies->nr) }}</b>
        (@foreach ($anomaliesByType as $r){{ $r->anomaly_type }}: {{ n($r->nr) }}@if(!$loop->last), @endif @endforeach)</li>
    @if (count($suppNoBuyer))
    <li><b>{{ count($suppNoBuyer) }} furnizori activi cu achiziții în ultimele 90 zile nu au responsabil alocat</b>:
        {{ implode(', ', array_map(fn($s) => $s->name, $suppNoBuyer)) }}</li>
    @endif
</ul>

<div class="pagebreak"></div>
<h2>8. Sănătate ERP — imagine de ansamblu</h2>
<table>
    <tr><th>Indicator</th><th class="num">Valoare</th></tr>
    <tr><td>Ultima sincronizare stocuri</td><td class="num">{{ $health['last_stock_sync'] }}</td></tr>
    <tr><td>Ultima intrare (preț achiziție) importată</td><td class="num">{{ $health['last_purchase_log'] }}</td></tr>
    <tr><td>Ultimul rând de vânzări importat</td><td class="num">{{ $health['last_sale_row'] }}</td></tr>
    <tr><td>Joburi eșuate în coadă (failed_jobs)</td><td class="num {{ $health['failed_jobs']>0?'warn':'pos' }}">{{ n($health['failed_jobs']) }}</td></tr>
    <tr><td>Joburi în așteptare</td><td class="num">{{ n($health['jobs_queue']) }}</td></tr>
    <tr><td>Sincronizări eșuate (7 zile)</td><td class="num {{ $health['sync_fails_7d']>0?'warn':'pos' }}">{{ n($health['sync_fails_7d']) }}</td></tr>
    <tr><td>Comenzi online (30 zile)</td><td class="num">{{ n($health['woo_orders_30d']->n) }} ({{ n($health['woo_orders_30d']->v) }} RON cu TVA)</td></tr>
    <tr><td>Oferte create (30 zile)</td><td class="num">{{ n($health['offers_30d']) }}</td></tr>
    <tr><td>Emailuri în modul comunicare</td><td class="num">{{ n($health['emails_total']) }}</td></tr>
    <tr><td>Produse placeholder (de curățat)</td><td class="num">{{ n($health['products_placeholder']) }}</td></tr>
</table>
<h3>Ultimele sincronizări pe tip</h3>
<table>
    <tr><th>Provider</th><th>Tip</th><th>Status</th><th class="num">Pornit la</th></tr>
    @foreach ($syncLastRuns as $r)
    <tr><td>{{ $r->provider }}</td><td>{{ $r->type }}</td>
        <td class="{{ in_array($r->status,['success','completed','ok'])?'pos':'warn' }}">{{ $r->status }}</td>
        <td class="num">{{ $r->started_at }}</td></tr>
    @endforeach
</table>

<h2>9. Concluzii și recomandări</h2>
<ol>
    <li><b>Rupturile de stoc sunt cea mai scumpă problemă</b>: ~{{ n($ruptSummary->pierdere30) }} RON/lună vânzări pierdute pe {{ n($ruptSummary->produse) }} produse. Prioritizați furnizorii din tabelul 6 (primii 5 concentrează cea mai mare parte a pierderii) și alocați responsabili unde lipsesc.</li>
    <li><b>Ajustați prețurile de vânzare</b> la produsele din secțiunea 5 — începeți cu cele cu adaos sub 10% și vânzări active; fiecare zi de întârziere erodează marja.</li>
    <li><b>Capital blocat {{ n($deadTotal->valoare_net) }} RON</b> în produse fără vânzări 90 zile — candidate pentru promoții/lichidare sau retur la furnizor.</li>
    <li><b>Completați datele de achiziție</b>: produse vândute fără furnizor asociat + furnizori fără responsabil + anomalii de preț nerevizuite ({{ n($anomalies->nr) }}) reduc fiabilitatea automatizărilor de reaprovizionare.</li>
    <li><b>Închideți PO-urile vechi</b> ({{ $poStaleCount->nr }} deschise de peste 14 zile) — statusuri curate = lead time-uri și rapoarte corecte.</li>
</ol>

</body>
</html>
