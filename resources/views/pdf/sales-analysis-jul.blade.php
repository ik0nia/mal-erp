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
    <div class="title">Analiză vânzări — 1–10 iulie 2026 vs 2025 <span style="font-size:10px; color:#b91c1c;">(v2 — date corectate)</span></div>
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
    $s = [];
    foreach ($summary as $row) { $s[$row->an] = $row; }
    $t25 = $s[2025]->marfa ?? 0; $t26 = $s[2026]->marfa ?? 0;
    $re = [];
    foreach ($retail as $row) { $re[$row->an] = $row; }
    $r25 = $re[2025]->val ?? 0; $r26 = $re[2026]->val ?? 0;
    $ret25 = array_sum(array_map(fn($r) => $r->an == 2025 ? $r->val : 0, $bigReturns));
    $ret26 = array_sum(array_map(fn($r) => $r->an == 2026 ? $r->val : 0, $bigReturns));
    $t25adj = $t25 - $ret25; $t26adj = $t26 - $ret26;
@endphp

<h2>1. Rezumat</h2>
<div class="kpi-row">
    <div class="kpi"><div class="val">{{ $fmt($t25) }} lei</div><div class="lbl">Vânzări marfă 1–10 iul 2025</div></div>
    <div class="kpi"><div class="val">{{ $fmt($t26) }} lei</div><div class="lbl">Vânzări marfă 1–10 iul 2026</div></div>
    <div class="kpi"><div class="val" style="color:{{ $t26 >= $t25 ? '#047857' : '#b91c1c' }}">{{ $pct($t25, $t26) }}</div><div class="lbl">Evoluție an/an</div></div>
</div>
<p>
    În primele 10 zile din iulie, vânzările de marfă au evoluat cu <b>{{ $pct($t25, $t26) }}</b>
    față de aceeași perioadă din 2025 ({{ $fmt($t26 - $t25) }} lei).
    Clienți B2B activi (facturi/avize &gt; 1.000 lei): <b>{{ $active2025 }}</b> în 2025 vs <b>{{ $active2026 }}</b> în 2026.
</p>
@if ($ret25 < -10000 || $ret26 < -10000)
    <div class="note">
        <b>Atenție la interpretare — retururi mari în fereastră:</b>
        @foreach ($bigReturns as $r)
            bon #{{ $r->nr_factura }} din {{ $r->zi }} iulie {{ $r->an }} = {{ $fmt($r->val) }} lei;
        @endforeach
        acestea sunt stornări reale de marfă (ex. cărămidă returnată), dar apasă baza anului respectiv.
        <b>Excluzând aceste retururi, comparația devine {{ $fmt($t25adj) }} lei (2025) vs {{ $fmt($t26adj) }} lei (2026),
        adică {{ $pct($t25adj, $t26adj) }}</b>. Scăderea aparentă pe documente reflectă în mare parte mutarea
        retailului din bonurile Mentor (incluse la documente în 2025) către casa de marcat, raportată separat mai sus.
    </div>
@endif

<h3>Retail casă de marcat — Magazin Practic (emulare, separat, lei fără TVA)</h3>
<table>
    <tr><th>An</th><th class="num">Bonuri</th><th class="num">Valoare</th><th class="num">Δ %</th></tr>
    <tr><td><b>2025</b></td><td class="num muted">{{ $fmt($re[2025]->bonuri ?? 0) }}</td><td class="num">{{ $fmt($r25) }}</td><td></td></tr>
    <tr><td><b>2026</b></td><td class="num muted">{{ $fmt($re[2026]->bonuri ?? 0) }}</td><td class="num">{{ $fmt($r26) }}</td>
        <td class="num {{ $r26 >= $r25 ? 'pos' : 'neg' }}">{{ $pct($r25, $r26) }}</td></tr>
</table>
<p class="muted" style="font-size:8px">Raportat separat: o parte din bonuri încasează avize deja incluse la documente — nu se adună cu tabelul de mai jos.</p>

<h3>Defalcare pe tip document (valori marfă, lei fără TVA; bonurile normalizate din prețuri cu TVA)</h3>
<table>
    <tr><th>An</th><th class="num">Avize</th><th class="num">Facturi</th><th class="num">Bonuri casă</th><th class="num">Total</th><th class="num">Documente</th></tr>
    @foreach ([2025, 2026] as $an)
        <tr>
            <td><b>{{ $an }}</b></td>
            <td class="num">{{ $fmt($s[$an]->avize) }}</td>
            <td class="num">{{ $fmt($s[$an]->facturi) }}</td>
            <td class="num">{{ $fmt($s[$an]->bonuri) }}</td>
            <td class="num"><b>{{ $fmt($s[$an]->marfa) }}</b></td>
            <td class="num muted">{{ $fmt($s[$an]->docs) }}</td>
        </tr>
    @endforeach
</table>

<h2>2. Evoluție zilnică (lei)</h2>
<table>
    <tr><th>Ziua</th><th class="num">2025</th><th class="num">2026</th><th class="num">Δ lei</th><th class="num">Δ %</th></tr>
    @foreach ($daily as $d)
        <tr>
            <td>{{ $d->zi }} iulie</td>
            <td class="num">{{ $fmt($d->v2025) }}</td>
            <td class="num">{{ $fmt($d->v2026) }}</td>
            <td class="num {{ $d->v2026 >= $d->v2025 ? 'pos' : 'neg' }}">{{ $fmt($d->v2026 - $d->v2025) }}</td>
            <td class="num {{ $d->v2026 >= $d->v2025 ? 'pos' : 'neg' }}">{{ $pct($d->v2025, $d->v2026) }}</td>
        </tr>
    @endforeach
</table>
<p class="muted" style="font-size:8px">
    Comparație pe zile calendaristice — zilele săptămânii diferă între ani (1 iulie 2025 = marți, 1 iulie 2026 = miercuri),
    deci abaterile zilnice mari (ex. weekend) sunt normale; relevant e totalul perioadei.
</p>

<div class="note">
    <b>Reguli de curățare aplicate</b> (identice cu raportul trimestrial v2): articolele interne de plată
    și avizele-ofertă („OFERTE CLIENTI") excluse; bonurile normalizate la fără-TVA; clienții consolidați pe CUI.
    Corecții v2: cantitățile bonurilor iulie 2026 reparate (bug de import), bonurile native Mentor recuperate,
    liniile cu cantități zecimale recuperate. Datele 1–10 iulie sunt complete în ambii ani.
</div>

<div class="pagebreak"></div>

<h2>3. Furnizori — cele mai mari scăderi</h2>
<h3>3a. Vânzări pe produsele furnizorului (1–10 iul, lei)</h3>
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

<h3>3b. Rulaj achiziții de la furnizor (intrări 1–10 iul, lei)</h3>
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

<h3>3c. Pentru context: furnizorii cu cele mai mari creșteri de vânzări</h3>
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

<h2>4. Clienți — cele mai mari scăderi (facturi + avize, 1–10 iul, lei)</h2>
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

<h2>5. Observații</h2>
<ul style="padding-left: 14px;">
    <li>Perioada scurtă (10 zile) amplifică efectul comenzilor mari punctuale — un singur proiect livrat poate muta procentele; interpretează topurile ca semnal, nu ca tendință certă.</li>
    <li>Pe 1–10 iulie: documente {{ $pct($t25, $t26) }} (cu efect de retururi și de mutare a retailului), retail casă {{ $pct($r25, $r26) }}. Imaginea combinată e de activitate în creștere, în linie cu trimestrul apr–iun (documente +4,1%, retail +74,9%).</li>
    <li>Zilele săptămânii nu se aliniază între ani; totalul perioadei este indicatorul de încredere.</li>
</ul>
</body>
</html>
