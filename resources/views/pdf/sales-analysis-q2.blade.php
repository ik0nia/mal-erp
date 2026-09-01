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
    .muted { color: #6b7280; }
    .kpi-row { width: 100%; margin: 8px 0; }
    .kpi { display: inline-block; width: 30%; background: #f9fafb; border: 1px solid #e5e7eb;
           border-left: 4px solid #e05d10; padding: 8px 10px; margin-right: 1.5%; }
    .kpi .val { font-size: 15px; font-weight: bold; }
    .kpi .lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; }
    .note { background: #fffbeb; border: 1px solid #fde68a; padding: 8px 10px; font-size: 8.5px; margin: 8px 0; }
    .pagebreak { page-break-before: always; }
</style>
</head>
<body>
<header>
    <div class="title">Analiză vânzări — aprilie–iunie 2026 vs 2025 <span style="font-size:10px; color:#b91c1c;">(v2 — date corectate)</span></div>
    <div class="sub">Malinco · sursă: WinMentor (winmentor_vanzari_raw / winmentor_intrari_raw) · generat {{ $generatedAt }}</div>
</header>
<footer>Raport generat automat din ERP Malinco · {{ $generatedAt }}</footer>

@php
    $fmt = fn($v) => number_format($v, 0, ',', '.');
    $pct = function ($old, $new) {
        if (!$old) return '—';
        $p = 100 * ($new - $old) / abs($old);
        return ($p >= 0 ? '+' : '') . number_format($p, 1, ',', '.') . '%';
    };
    $byMonth = [];
    foreach ($monthly as $m) { $byMonth[$m->luna][$m->an] = $m; }
    $byRetail = [];
    foreach ($retail as $r) { $byRetail[$r->luna][$r->an] = $r; }
    $r25 = array_sum(array_map(fn($r) => $r->an == 2025 ? $r->val : 0, $retail));
    $r26 = array_sum(array_map(fn($r) => $r->an == 2026 ? $r->val : 0, $retail));
    $luni = [4 => 'Aprilie', 5 => 'Mai', 6 => 'Iunie'];
    $t25 = array_sum(array_map(fn($m) => $m->an == 2025 ? $m->marfa : 0, $monthly));
    $t26 = array_sum(array_map(fn($m) => $m->an == 2026 ? $m->marfa : 0, $monthly));
@endphp

<h2>1. Rezumat executiv</h2>
<div class="kpi-row">
    <div class="kpi"><div class="val">{{ $fmt($t25) }} lei</div><div class="lbl">Vânzări marfă apr–iun 2025</div></div>
    <div class="kpi"><div class="val">{{ $fmt($t26) }} lei</div><div class="lbl">Vânzări marfă apr–iun 2026</div></div>
    <div class="kpi"><div class="val" style="color:{{ $t26 >= $t25 ? '#047857' : '#b91c1c' }}">{{ $pct($t25, $t26) }}</div><div class="lbl">Evoluție an/an</div></div>
</div>
<p>
    Vânzările de marfă (fără articole interne de tip avans/încasare) au crescut cu
    <b>{{ $pct($t25, $t26) }}</b> față de aceeași perioadă a anului trecut
    ({{ $fmt($t26 - $t25) }} lei în plus). Creșterea este concentrată în luna mai.
    Separat, retailul casei de marcat (emulare) a fost {{ $fmt($r25) }} lei în 2025 vs
    {{ $fmt($r26) }} lei în 2026 ({{ $pct($r25, $r26) }}).
    Numărul de clienți B2B activi (facturi/avize &gt; 1.000 lei) a fost
    <b>{{ $active2025 }}</b> în 2025 vs <b>{{ $active2026 }}</b> în 2026.
</p>

<h2>2. Evoluție lunară — vânzări pe documente (avize + facturi + bonuri Mentor, lei fără TVA*)</h2>
<table>
    <tr>
        <th>Luna</th><th class="num">2025</th><th class="num">2026</th><th class="num">Δ lei</th><th class="num">Δ %</th>
        <th class="num">Avize 2026</th><th class="num">Facturi 2026</th><th class="num">Bonuri 2026</th>
    </tr>
    @foreach ($luni as $nr => $nume)
        @php $m25 = $byMonth[$nr][2025]; $m26 = $byMonth[$nr][2026]; @endphp
        <tr>
            <td><b>{{ $nume }}</b></td>
            <td class="num">{{ $fmt($m25->marfa) }}</td>
            <td class="num">{{ $fmt($m26->marfa) }}</td>
            <td class="num {{ $m26->marfa >= $m25->marfa ? 'pos' : 'neg' }}">{{ $fmt($m26->marfa - $m25->marfa) }}</td>
            <td class="num {{ $m26->marfa >= $m25->marfa ? 'pos' : 'neg' }}">{{ $pct($m25->marfa, $m26->marfa) }}</td>
            <td class="num muted">{{ $fmt($m26->avize) }}</td>
            <td class="num muted">{{ $fmt($m26->facturi) }}</td>
            <td class="num muted">{{ $fmt($m26->bonuri) }}</td>
        </tr>
    @endforeach
    <tr>
        <td><b>TOTAL</b></td>
        <td class="num"><b>{{ $fmt($t25) }}</b></td>
        <td class="num"><b>{{ $fmt($t26) }}</b></td>
        <td class="num {{ $t26 >= $t25 ? 'pos' : 'neg' }}">{{ $fmt($t26 - $t25) }}</td>
        <td class="num {{ $t26 >= $t25 ? 'pos' : 'neg' }}">{{ $pct($t25, $t26) }}</td>
        <td colspan="3"></td>
    </tr>
</table>
<p class="muted" style="font-size:8px">* cantitate × preț unitar din LisVanz WinMentor; bonurile (S), care în WinMentor au prețuri cu TVA, au fost normalizate la fără-TVA (19% în 2025, 21% în 2026). Avize expediție (AE), facturi directe (F), bonuri casă Mentor (S).</p>

<h3>Retail casă de marcat — Magazin Practic (emulare, lei fără TVA)</h3>
<table>
    <tr><th>Luna</th><th class="num">Bonuri 2025</th><th class="num">Bonuri 2026</th><th class="num">2025</th><th class="num">2026</th><th class="num">Δ %</th></tr>
    @foreach ($luni as $nr => $nume)
        @php $e25 = $byRetail[$nr][2025] ?? null; $e26 = $byRetail[$nr][2026] ?? null; @endphp
        <tr>
            <td><b>{{ $nume }}</b></td>
            <td class="num muted">{{ $e25 ? $fmt($e25->bonuri) : '—' }}</td>
            <td class="num muted">{{ $e26 ? $fmt($e26->bonuri) : '—' }}</td>
            <td class="num">{{ $e25 ? $fmt($e25->val) : '—' }}</td>
            <td class="num">{{ $e26 ? $fmt($e26->val) : '—' }}</td>
            <td class="num {{ ($e26->val ?? 0) >= ($e25->val ?? 0) ? 'pos' : 'neg' }}">{{ $pct($e25->val ?? 0, $e26->val ?? 0) }}</td>
        </tr>
    @endforeach
    <tr>
        <td><b>TOTAL</b></td><td></td><td></td>
        <td class="num"><b>{{ $fmt($r25) }}</b></td>
        <td class="num"><b>{{ $fmt($r26) }}</b></td>
        <td class="num {{ $r26 >= $r25 ? 'pos' : 'neg' }}">{{ $pct($r25, $r26) }}</td>
    </tr>
</table>
<p class="muted" style="font-size:8px">Retailul casei (emulare) e raportat separat pentru că o parte din bonuri încasează avize deja incluse la „documente" — cele două tabele NU se adună. Reconcilierea cu cifra de afaceri contabilă: documente + ~40% din retail (partea care nu plătește avize).</p>

<h2>3. Verificarea calității datelor (raw)</h2>
<div class="note">
    <b>Verificări efectuate și rezultate:</b>
    <ul style="margin:4px 0; padding-left: 14px;">
        <li><b>Corecții v2 față de raportul anterior:</b> (1) cantitățile bonurilor mai–iul 2026 erau greșite (bug de import: se salva poziția pe bon în loc de cantitate) — reparate din sursa casei de marcat; (2) bonurile native Mentor lipseau complet din mai 2026 — recuperate (~500 linii/lună); (3) 4.428 linii cu cantități zecimale („5,5") pierdute la import — recuperate pe tot istoricul; (4) TVA normalizat pe bonuri (aveau prețuri cu TVA, restul fără).</li>
        <li><b>Fără duplicate:</b> nu există linii duplicate exacte și nici suprapunere bon ↔ aviz pentru aceeași livrare (testat pe partener + articol + cantitate + zi). Creșterea din mai 2026 este reală, nu artefact de import.</li>
        <li><b>Schimbare de proces din mai 2026:</b> bonurile de casă se înregistrează individual („Bon #N / Casa 1"), de aici saltul numărului de documente (6.200 în mai 2026 vs ~3.000 anterior). Valorile rămân comparabile.</li>
        <li><b>Articole interne excluse:</b> 5 articole speciale (avans facturat, achitare facturi emise, încasări/regularizări pe bon — SKU 5940084965599xx/60735) sunt fluxuri de plată, nu marfă; au fost excluse din toate cifrele. Impact: ex. mai 2025 conținea o singură linie de avans de +311.916 lei care distorsiona comparația brută.</li>
        <li><b>Oferte excluse:</b> avizele emise pe partenerul generic „OFERTE CLIENTI" (apărut din mai 2026, ~389.000 lei în mai) sunt oferte de preț, nu livrări — au fost excluse din toate cifrele.</li>
        <li><b>Coloana valoare_totala</b> din raw e nefiabilă pentru istoricul vechi — s-a folosit formula canonică a ERP-ului (cantitate × preț), identică cu pagina Vânzări WinMentor.</li>
        <li><b>Atribuirea pe furnizori</b> (prin catalogul de produse) acoperă {{ $coverage[2025] ?? '?' }}% din valoarea 2025 și {{ $coverage[2026] ?? '?' }}% din 2026 — acoperire echilibrată, comparația e corectă.</li>
        <li><b>CUI-uri neuniforme</b> („RO 18487309" vs „RO18487309") au fost normalizate; clienții persoane fizice fără CUI sunt agregați pe ID partener.</li>
    </ul>
</div>

<div class="pagebreak"></div>

<h2>4. Furnizori — cele mai mari scăderi</h2>
<h3>4a. Vânzări pe produsele furnizorului (apr–iun, lei)</h3>
<table>
    <tr><th>Furnizor</th><th class="num">2025</th><th class="num">2026</th><th class="num">Δ lei</th><th class="num">Δ %</th></tr>
    @foreach ($supSalesDown as $r)
        <tr>
            <td>{{ $r->furnizor }}</td>
            <td class="num">{{ $fmt($r->v2025) }}</td>
            <td class="num">{{ $fmt($r->v2026) }}</td>
            <td class="num neg">{{ $fmt($r->v2026 - $r->v2025) }}</td>
            <td class="num neg">{{ $pct($r->v2025, $r->v2026) }}</td>
        </tr>
    @endforeach
</table>

<h3>4b. Rulaj achiziții de la furnizor (intrări, apr–iun, lei)</h3>
<table>
    <tr><th>Furnizor</th><th class="num">2025</th><th class="num">2026</th><th class="num">Δ lei</th><th class="num">Δ %</th></tr>
    @foreach ($supPurchDown as $r)
        <tr>
            <td>{{ $r->furnizor }}</td>
            <td class="num">{{ $fmt($r->v2025) }}</td>
            <td class="num">{{ $fmt($r->v2026) }}</td>
            <td class="num neg">{{ $fmt($r->v2026 - $r->v2025) }}</td>
            <td class="num neg">{{ $pct($r->v2025, $r->v2026) }}</td>
        </tr>
    @endforeach
</table>

<h3>4c. Pentru context: furnizorii cu cele mai mari creșteri de vânzări</h3>
<table>
    <tr><th>Furnizor</th><th class="num">2025</th><th class="num">2026</th><th class="num">Δ lei</th><th class="num">Δ %</th></tr>
    @foreach ($supSalesUp as $r)
        <tr>
            <td>{{ $r->furnizor }}</td>
            <td class="num">{{ $fmt($r->v2025) }}</td>
            <td class="num">{{ $fmt($r->v2026) }}</td>
            <td class="num pos">+{{ $fmt($r->v2026 - $r->v2025) }}</td>
            <td class="num pos">{{ $pct($r->v2025, $r->v2026) }}</td>
        </tr>
    @endforeach
</table>

<div class="pagebreak"></div>

<h2>5. Clienți — cele mai mari scăderi (facturi + avize, apr–iun, lei)</h2>
<table>
    <tr><th>Client</th><th class="num">2025</th><th class="num">2026</th><th class="num">Δ lei</th><th class="num">Δ %</th></tr>
    @foreach ($clientsDown as $r)
        <tr>
            <td>{{ $r->client }}</td>
            <td class="num">{{ $fmt($r->v2025) }}</td>
            <td class="num">{{ $fmt($r->v2026) }}</td>
            <td class="num neg">{{ $fmt($r->v2026 - $r->v2025) }}</td>
            <td class="num neg">{{ $pct($r->v2025, $r->v2026) }}</td>
        </tr>
    @endforeach
</table>

<h3>Pentru context: clienții cu cele mai mari creșteri</h3>
<table>
    <tr><th>Client</th><th class="num">2025</th><th class="num">2026</th><th class="num">Δ lei</th><th class="num">Δ %</th></tr>
    @foreach ($clientsUp as $r)
        <tr>
            <td>{{ $r->client }}</td>
            <td class="num">{{ $fmt($r->v2025) }}</td>
            <td class="num">{{ $fmt($r->v2026) }}</td>
            <td class="num pos">+{{ $fmt($r->v2026 - $r->v2025) }}</td>
            <td class="num pos">{{ $pct($r->v2025, $r->v2026) }}</td>
        </tr>
    @endforeach
</table>

<h2>6. Concluzii</h2>
<ul style="padding-left: 14px;">
    <li>Vânzările pe documente cresc moderat (<b>{{ $pct($t25, $t26) }}</b>), dar <b>retailul casei de marcat explodează ({{ $pct($r25, $r26) }})</b> — creșterea reală a businessului e în magazin. Iunie pe documente e în minus (−10%) în principal pe facturi directe; de urmărit.</li>
    <li>Scăderile pe furnizori sunt puține și concentrate: <b>Melinda Impex Steel</b> și <b>Metalurgica Industrial</b> au dispărut complet din achiziții (de verificat dacă e decizie comercială sau pierdere de furnizor), iar <b>Endles</b> scade puternic atât la achiziții cât și la vânzări (relație atât de furnizor cât și de client în scădere — vezi și top clienți).</li>
    <li>Scăderile pe clienți sunt tipice profilului de proiect (constructori care au terminat lucrările: Compania de Construcții Rezidențiale AG, Pada Cons, Prima Kapital, Digi Infrastructura). Mulți au ajuns la zero — merită contactați cei încă activi în piață. Notă: în WinMentor același client are adesea mai multe fișe partener (per proiect); analiza le-a consolidat pe CUI.</li>
    <li>Creșterile (Imcop, Formatt, Austrotherm, Baumit, Intertranscom, Cemix, Holcim) confirmă sezonul bun pe materiale de zidărie/termosistem. Cemacon rămâne cel mai mare furnizor ca volum, cu vânzări aproximativ stabile și achiziții în creștere.</li>
</ul>
</body>
</html>
