<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BI Marje Comerciale — Malinco</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; }

  header { background: #1e293b; border-bottom: 1px solid #334155; padding: 20px 32px; display: flex; align-items: center; gap: 16px; }
  header h1 { font-size: 1.4rem; font-weight: 700; color: #f8fafc; }
  header span { font-size: 0.85rem; color: #64748b; }
  .badge { background: #0ea5e9; color: #fff; font-size: 0.7rem; font-weight: 600; padding: 3px 8px; border-radius: 20px; letter-spacing: 0.5px; }

  .container { max-width: 1400px; margin: 0 auto; padding: 28px 24px; }

  .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 28px; }
  .kpi { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; }
  .kpi-label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px; }
  .kpi-value { font-size: 2rem; font-weight: 700; color: #f8fafc; line-height: 1; }
  .kpi-sub { font-size: 0.8rem; color: #94a3b8; margin-top: 6px; }
  .kpi-value.green { color: #4ade80; }
  .kpi-value.yellow { color: #fbbf24; }
  .kpi-value.red { color: #f87171; }

  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
  .grid-3 { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px; }
  @media (max-width: 900px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }

  .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 22px; }
  .card-title { font-size: 0.85rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.7px; margin-bottom: 18px; }
  .chart-wrap { position: relative; }

  table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
  thead th { color: #64748b; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.6px; padding: 8px 12px; text-align: left; border-bottom: 1px solid #334155; }
  tbody tr { border-bottom: 1px solid #1e293b; transition: background 0.15s; }
  tbody tr:hover { background: #0f172a; }
  tbody td { padding: 9px 12px; color: #cbd5e1; }
  .pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
  .pill-green { background: #052e16; color: #4ade80; }
  .pill-yellow { background: #422006; color: #fbbf24; }
  .pill-red { background: #450a0a; color: #f87171; }
  .pill-blue { background: #0c1a3a; color: #60a5fa; }

  .alert-row td:first-child { color: #f8fafc; font-weight: 500; }
  .truncate { max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

  .section-full { margin-bottom: 20px; }
</style>
</head>
<body>

<header>
  <div>
    <h1>BI Marje Comerciale</h1>
    <span>Sursă: WinMentor Bridge — /api/receptii &nbsp;·&nbsp; Generat: 13 Apr 2026</span>
  </div>
  <div style="margin-left:auto"><span class="badge">LIVE DATA</span></div>
</header>

<div class="container">

  <!-- KPI -->
  <div class="kpi-grid">
    <div class="kpi">
      <div class="kpi-label">Adaos mediu global</div>
      <div class="kpi-value green">50.1%</div>
      <div class="kpi-sub">2019–2026, 104k tranzacții</div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Adaos mediu 2026</div>
      <div class="kpi-value green">67.9%</div>
      <div class="kpi-sub">↑ față de 46.3% în 2019</div>
    </div>
    <div class="kpi">
      <div class="kpi-label">SKU-uri distincte</div>
      <div class="kpi-value">8.749</div>
      <div class="kpi-sub">cu date marjă disponibile</div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Furnizori activi</div>
      <div class="kpi-value">195</div>
      <div class="kpi-sub">2024–2026</div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Tranzacții cu adaos negativ</div>
      <div class="kpi-value red">1.775</div>
      <div class="kpi-sub">6.2% din total 2024–2026</div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Preț intrare mediu 2026</div>
      <div class="kpi-value yellow">28.84</div>
      <div class="kpi-sub">RON / tranzacție</div>
    </div>
  </div>

  <!-- Evolutie anuala + distributie -->
  <div class="grid-3">
    <div class="card">
      <div class="card-title">Evoluție adaos mediu & preț intrare (2019–2026)</div>
      <div class="chart-wrap" style="height:280px">
        <canvas id="chartEvolutie"></canvas>
      </div>
    </div>
    <div class="card">
      <div class="card-title">Distribuție adaos 2024–2026</div>
      <div class="chart-wrap" style="height:280px">
        <canvas id="chartDist"></canvas>
      </div>
    </div>
  </div>

  <!-- Evolutie lunara -->
  <div class="section-full">
    <div class="card">
      <div class="card-title">Evoluție lunară adaos % — 2025–2026</div>
      <div class="chart-wrap" style="height:220px">
        <canvas id="chartLunar"></canvas>
      </div>
    </div>
  </div>

  <!-- Top furnizori -->
  <div class="grid-2">
    <div class="card">
      <div class="card-title">Top furnizori după volum tranzacții (2024–2026)</div>
      <div class="chart-wrap" style="height:320px">
        <canvas id="chartFurnizoriVol"></canvas>
      </div>
    </div>
    <div class="card">
      <div class="card-title">Adaos mediu per furnizor (2024–2026)</div>
      <div class="chart-wrap" style="height:320px">
        <canvas id="chartFurnizoriAdaos"></canvas>
      </div>
    </div>
  </div>

  <!-- Tabel furnizori -->
  <div class="section-full">
    <div class="card">
      <div class="card-title">Detaliu furnizori — 2024–2026 (min. 15 tranzacții)</div>
      <table>
        <thead>
          <tr>
            <th>Furnizor</th>
            <th style="text-align:right">Tranzacții</th>
            <th style="text-align:right">Produse</th>
            <th style="text-align:right">Valoare intrări</th>
            <th style="text-align:right">Adaos mediu</th>
          </tr>
        </thead>
        <tbody id="tabelFurnizori"></tbody>
      </table>
    </div>
  </div>

  <!-- Alerte produse adaos mic -->
  <div class="section-full">
    <div class="card">
      <div class="card-title">Produse cu adaos sub 10% sau negativ — 2025–2026 (min. 3 apariții)</div>
      <table>
        <thead>
          <tr>
            <th>Produs</th>
            <th>Furnizor</th>
            <th style="text-align:right">Adaos mediu</th>
            <th style="text-align:right">Apariții</th>
            <th style="text-align:right">Preț intrare mediu</th>
          </tr>
        </thead>
        <tbody id="tabelProbleme"></tbody>
      </table>
    </div>
  </div>

</div>

<script>
const evolutie = [{"an":2019,"tranzactii":15636,"adaos":"46.3","pret_intrare":"20.00","pret_vanzare_fara_tva":"25.45"},{"an":2020,"tranzactii":17721,"adaos":"45.1","pret_intrare":"21.98","pret_vanzare_fara_tva":"27.43"},{"an":2021,"tranzactii":16632,"adaos":"38.2","pret_intrare":"26.71","pret_vanzare_fara_tva":"33.30"},{"an":2022,"tranzactii":12693,"adaos":"42.0","pret_intrare":"30.03","pret_vanzare_fara_tva":"38.50"},{"an":2023,"tranzactii":12908,"adaos":"51.1","pret_intrare":"30.47","pret_vanzare_fara_tva":"44.09"},{"an":2024,"tranzactii":12902,"adaos":"64.1","pret_intrare":"30.89","pret_vanzare_fara_tva":"40.23"},{"an":2025,"tranzactii":13010,"adaos":"66.2","pret_intrare":"27.42","pret_vanzare_fara_tva":"35.72"},{"an":2026,"tranzactii":2598,"adaos":"67.9","pret_intrare":"28.84","pret_vanzare_fara_tva":"37.11"}];

const furnizori = [{"den_furnizor":"BAUMIT ROMANIA COM SRL","tranzactii":2248,"produse":251,"adaos":"19.1","valoare_intrari":"8816160"},{"den_furnizor":"SEDA-INVEST SRL","tranzactii":1977,"produse":322,"adaos":"108.8","valoare_intrari":"249474"},{"den_furnizor":"ROMPROFIX SRL","tranzactii":1702,"produse":307,"adaos":"41.1","valoare_intrari":"156445"},{"den_furnizor":"LEVIROM PRODCOM SRL","tranzactii":1622,"produse":204,"adaos":"61.6","valoare_intrari":"179486"},{"den_furnizor":"HARDEX PRODUCTS S.R.L.","tranzactii":1567,"produse":182,"adaos":"36.4","valoare_intrari":"243157"},{"den_furnizor":"IMCOP SRL","tranzactii":1324,"produse":99,"adaos":"24.0","valoare_intrari":"2769293"},{"den_furnizor":"ENDLES SRL","tranzactii":1195,"produse":164,"adaos":"39.9","valoare_intrari":"1549874"},{"den_furnizor":"TEMAD CO SRL","tranzactii":1165,"produse":240,"adaos":"46.0","valoare_intrari":"484692"},{"den_furnizor":"UNIPREST INSTAL SRL","tranzactii":874,"produse":255,"adaos":"57.5","valoare_intrari":"160128"},{"den_furnizor":"TOBIMAR S.R.L.","tranzactii":824,"produse":189,"adaos":"59.1","valoare_intrari":"113855"},{"den_furnizor":"CEMACON -  S.A.","tranzactii":818,"produse":54,"adaos":"7.3","valoare_intrari":"5819176"},{"den_furnizor":"FISCHER FIXINGS ROMANIA S.R.L.","tranzactii":806,"produse":248,"adaos":"43.7","valoare_intrari":"144179"},{"den_furnizor":"SURUBEX TRADE SRL","tranzactii":697,"produse":142,"adaos":"93.6","valoare_intrari":"119652"},{"den_furnizor":"MELINDA-IMPEX INSTAL S.A.","tranzactii":662,"produse":114,"adaos":"50.0","valoare_intrari":"63941"},{"den_furnizor":"DAFERO SRL","tranzactii":646,"produse":78,"adaos":"151.1","valoare_intrari":"89337"},{"den_furnizor":"FORMATT BUILDING PRODUCTS LTD","tranzactii":634,"produse":73,"adaos":"733.3","valoare_intrari":"500166"},{"den_furnizor":"SCHULLER EH KLAR SRL","tranzactii":624,"produse":63,"adaos":"42.5","valoare_intrari":"141843"},{"den_furnizor":"CEMIX ROMANIA S.R.L.","tranzactii":618,"produse":55,"adaos":"21.1","valoare_intrari":"2934045"},{"den_furnizor":"AUSTROTHERM COM SRL","tranzactii":546,"produse":64,"adaos":"22.3","valoare_intrari":"2633112"},{"den_furnizor":"SOUDAL SRL","tranzactii":539,"produse":95,"adaos":"46.2","valoare_intrari":"391782"}];

const distributie = {"negativ":1775,"d0_20":994,"d20_40":12495,"d40_60":7638,"d60_100":2704,"peste100":2904};

const lunar = [{"an":2025,"luna":1,"tranzactii":740,"adaos":"68.5","pret_intrare":"22.35"},{"an":2025,"luna":2,"tranzactii":707,"adaos":"107.3","pret_intrare":"21.99"},{"an":2025,"luna":3,"tranzactii":1283,"adaos":"51.1","pret_intrare":"26.29"},{"an":2025,"luna":4,"tranzactii":972,"adaos":"64.7","pret_intrare":"26.96"},{"an":2025,"luna":5,"tranzactii":1467,"adaos":"63.9","pret_intrare":"30.73"},{"an":2025,"luna":6,"tranzactii":1305,"adaos":"56.6","pret_intrare":"30.85"},{"an":2025,"luna":7,"tranzactii":1567,"adaos":"72.8","pret_intrare":"27.56"},{"an":2025,"luna":8,"tranzactii":658,"adaos":"75.4","pret_intrare":"24.47"},{"an":2025,"luna":9,"tranzactii":1466,"adaos":"51.4","pret_intrare":"25.39"},{"an":2025,"luna":10,"tranzactii":1195,"adaos":"84.1","pret_intrare":"30.90"},{"an":2025,"luna":11,"tranzactii":956,"adaos":"60.5","pret_intrare":"27.21"},{"an":2025,"luna":12,"tranzactii":694,"adaos":"59.9","pret_intrare":"28.70"},{"an":2026,"luna":1,"tranzactii":376,"adaos":"94.7","pret_intrare":"23.43"},{"an":2026,"luna":2,"tranzactii":740,"adaos":"52.9","pret_intrare":"26.02"},{"an":2026,"luna":3,"tranzactii":1189,"adaos":"60.4","pret_intrare":"32.66"},{"an":2026,"luna":4,"tranzactii":293,"adaos":"101.7","pret_intrare":"27.38"}];

const probleme = [{"den_articol":"SKOL DOZA 0.5","sku":"594008496559753","den_furnizor":"UNITED ROMANIAN BREWERIES BEREPROD SRL","adaos":"-16.0","aparitii":3,"pret_med":"3.44"},{"den_articol":"MORTAR MPI 25 40 KG","sku":"9007912539674","den_furnizor":"BAUMIT ROMANIA COM SRL","adaos":"-16.0","aparitii":25,"pret_med":"23.18"},{"den_articol":"APA PLATA BILBOR 2 L","sku":"594008496560243","den_furnizor":"PARTNER DRINKS SRL","adaos":"-16.0","aparitii":18,"pret_med":"3.15"},{"den_articol":"PALET VAR E 1200X1000","sku":"9007767530970","den_furnizor":"ENDLES SRL","adaos":"-16.0","aparitii":7,"pret_med":"65.00"},{"den_articol":"PALET EURO SIKA 1200X1150","sku":"9007767538020","den_furnizor":"ENDLES SRL","adaos":"-16.0","aparitii":3,"pret_med":"101.46"},{"den_articol":"ADEZIV PROCONTACT GEL 27.5 KG","sku":"594008496560849","den_furnizor":"BAUMIT ROMANIA COM SRL","adaos":"-16.0","aparitii":5,"pret_med":"34.59"},{"den_articol":"APA MINERAL BILBOR 1 L","sku":"594008496560852","den_furnizor":"PARTNER DRINKS SRL","adaos":"-16.0","aparitii":9,"pret_med":"2.82"},{"den_articol":"DUO TOP 1.5 K 0016 25 KG","sku":"594008496560877","den_furnizor":"BAUMIT ROMANIA COM SRL","adaos":"-16.0","aparitii":6,"pret_med":"96.87"},{"den_articol":"GRUND UNIVERSAL 0879 20KG","sku":"594008496560888","den_furnizor":"BAUMIT ROMANIA COM SRL","adaos":"-16.0","aparitii":3,"pret_med":"149.57"},{"den_articol":"BUIANDRUGI 2.25M CEMACON","sku":"594008496560854","den_furnizor":"CEMACON -  S.A.","adaos":"-16.0","aparitii":3,"pret_med":"38.74"},{"den_articol":"BUIANDRUGI 2.5 M CEMACON","sku":"594008496560855","den_furnizor":"CEMACON -  S.A.","adaos":"-16.0","aparitii":3,"pret_med":"43.19"},{"den_articol":"BUIANDRUGI 3 M CEMACON","sku":"9007912547365","den_furnizor":"CEMACON -  S.A.","adaos":"-16.0","aparitii":3,"pret_med":"52.29"},{"den_articol":"DUO TOP 1.5K 0019 25 KG","sku":"594008496560785","den_furnizor":"BAUMIT ROMANIA COM SRL","adaos":"-16.0","aparitii":8,"pret_med":"89.51"},{"den_articol":"PALET  EURO E","sku":"594008496560252","den_furnizor":"SCHULLER EH KLAR SRL","adaos":"-16.0","aparitii":3,"pret_med":"45.00"},{"den_articol":"DUO TOP 1.5K 0897 25 KG","sku":"594008496560787","den_furnizor":"BAUMIT ROMANIA COM SRL","adaos":"-16.0","aparitii":5,"pret_med":"96.16"}];

const COLORS = {
  blue:   '#3b82f6',
  green:  '#4ade80',
  yellow: '#fbbf24',
  red:    '#f87171',
  purple: '#a78bfa',
  cyan:   '#22d3ee',
  orange: '#fb923c',
  grid:   '#1e293b',
  text:   '#94a3b8',
};

const baseChartOpts = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { labels: { color: COLORS.text, font: { size: 11 } } } },
  scales: {
    x: { ticks: { color: COLORS.text, font: { size: 11 } }, grid: { color: '#1e293b' } },
    y: { ticks: { color: COLORS.text, font: { size: 11 } }, grid: { color: '#1e293b' } },
  }
};

// 1. Evolutie anuala
new Chart(document.getElementById('chartEvolutie'), {
  type: 'bar',
  data: {
    labels: evolutie.map(r => r.an),
    datasets: [
      {
        type: 'line',
        label: 'Adaos mediu %',
        data: evolutie.map(r => parseFloat(r.adaos)),
        borderColor: COLORS.green,
        backgroundColor: 'rgba(74,222,128,0.1)',
        borderWidth: 2.5,
        pointRadius: 4,
        tension: 0.3,
        yAxisID: 'y',
        fill: true,
      },
      {
        label: 'Preț intrare mediu (RON)',
        data: evolutie.map(r => parseFloat(r.pret_intrare)),
        backgroundColor: 'rgba(59,130,246,0.6)',
        borderRadius: 4,
        yAxisID: 'y2',
      },
      {
        label: 'Preț vânzare fără TVA (RON)',
        data: evolutie.map(r => parseFloat(r.pret_vanzare_fara_tva)),
        backgroundColor: 'rgba(251,191,36,0.6)',
        borderRadius: 4,
        yAxisID: 'y2',
      },
    ]
  },
  options: {
    ...baseChartOpts,
    scales: {
      x: { ticks: { color: COLORS.text }, grid: { color: '#263347' } },
      y: { position: 'left', ticks: { color: COLORS.green, callback: v => v + '%' }, grid: { color: '#263347' } },
      y2: { position: 'right', ticks: { color: COLORS.text, callback: v => v + ' RON' }, grid: { display: false } },
    },
    plugins: { legend: { labels: { color: COLORS.text, font: { size: 11 } } } },
  }
});

// 2. Distributie donut
new Chart(document.getElementById('chartDist'), {
  type: 'doughnut',
  data: {
    labels: ['Negativ (<0%)', '0–20%', '20–40%', '40–60%', '60–100%', '>100%'],
    datasets: [{
      data: [distributie.negativ, distributie.d0_20, distributie.d20_40, distributie.d40_60, distributie.d60_100, distributie.peste100],
      backgroundColor: [COLORS.red, '#f97316', COLORS.yellow, COLORS.green, COLORS.blue, COLORS.purple],
      borderWidth: 2,
      borderColor: '#0f172a',
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'right', labels: { color: COLORS.text, font: { size: 11 }, padding: 12 } },
      tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.parsed.toLocaleString('ro')} tranz.` } }
    }
  }
});

// 3. Evolutie lunara
new Chart(document.getElementById('chartLunar'), {
  type: 'line',
  data: {
    labels: lunar.map(r => `${['','Ian','Feb','Mar','Apr','Mai','Iun','Iul','Aug','Sep','Oct','Nov','Dec'][r.luna]} ${r.an}`),
    datasets: [{
      label: 'Adaos mediu %',
      data: lunar.map(r => parseFloat(r.adaos)),
      borderColor: COLORS.cyan,
      backgroundColor: 'rgba(34,211,238,0.08)',
      fill: true,
      borderWidth: 2,
      pointRadius: 3,
      tension: 0.3,
    }]
  },
  options: {
    ...baseChartOpts,
    scales: {
      x: { ticks: { color: COLORS.text, font: { size: 10 }, maxRotation: 45 }, grid: { color: '#263347' } },
      y: { ticks: { color: COLORS.text, callback: v => v + '%' }, grid: { color: '#263347' } },
    },
    plugins: { legend: { display: false } }
  }
});

// 4. Furnizori volum (top 10)
const top10 = furnizori.slice(0, 10);
new Chart(document.getElementById('chartFurnizoriVol'), {
  type: 'bar',
  data: {
    labels: top10.map(r => r.den_furnizor.length > 20 ? r.den_furnizor.substring(0,20)+'…' : r.den_furnizor),
    datasets: [{
      label: 'Tranzacții',
      data: top10.map(r => r.tranzactii),
      backgroundColor: top10.map(r => {
        const a = parseFloat(r.adaos);
        if (a < 15) return 'rgba(248,113,113,0.7)';
        if (a < 40) return 'rgba(251,191,36,0.7)';
        return 'rgba(74,222,128,0.7)';
      }),
      borderRadius: 4,
    }]
  },
  options: {
    ...baseChartOpts,
    indexAxis: 'y',
    scales: {
      x: { ticks: { color: COLORS.text }, grid: { color: '#263347' } },
      y: { ticks: { color: COLORS.text, font: { size: 10 } }, grid: { display: false } },
    },
    plugins: { legend: { display: false } }
  }
});

// 5. Furnizori adaos (sortati dupa adaos, excludem outlieri >200%)
const furnSorted = [...furnizori]
  .filter(r => parseFloat(r.adaos) <= 200)
  .sort((a,b) => parseFloat(b.adaos) - parseFloat(a.adaos))
  .slice(0, 12);

new Chart(document.getElementById('chartFurnizoriAdaos'), {
  type: 'bar',
  data: {
    labels: furnSorted.map(r => r.den_furnizor.length > 20 ? r.den_furnizor.substring(0,20)+'…' : r.den_furnizor),
    datasets: [{
      label: 'Adaos mediu %',
      data: furnSorted.map(r => parseFloat(r.adaos)),
      backgroundColor: furnSorted.map(r => {
        const a = parseFloat(r.adaos);
        if (a >= 80) return 'rgba(74,222,128,0.8)';
        if (a >= 40) return 'rgba(59,130,246,0.8)';
        if (a >= 20) return 'rgba(251,191,36,0.8)';
        return 'rgba(248,113,113,0.8)';
      }),
      borderRadius: 4,
    }]
  },
  options: {
    ...baseChartOpts,
    indexAxis: 'y',
    scales: {
      x: { ticks: { color: COLORS.text, callback: v => v + '%' }, grid: { color: '#263347' } },
      y: { ticks: { color: COLORS.text, font: { size: 10 } }, grid: { display: false } },
    },
    plugins: { legend: { display: false } }
  }
});

// Tabel furnizori
const tbody = document.getElementById('tabelFurnizori');
furnizori.forEach(r => {
  const adaos = parseFloat(r.adaos);
  const pillClass = adaos < 15 ? 'pill-red' : adaos < 40 ? 'pill-yellow' : 'pill-green';
  const val = parseInt(r.valoare_intrari).toLocaleString('ro') + ' RON';
  tbody.innerHTML += `<tr>
    <td>${r.den_furnizor}</td>
    <td style="text-align:right">${r.tranzactii.toLocaleString('ro')}</td>
    <td style="text-align:right">${r.produse}</td>
    <td style="text-align:right">${val}</td>
    <td style="text-align:right"><span class="pill ${pillClass}">${r.adaos}%</span></td>
  </tr>`;
});

// Tabel probleme
const tbody2 = document.getElementById('tabelProbleme');
probleme.forEach(r => {
  const adaos = parseFloat(r.adaos);
  const pillClass = adaos < 0 ? 'pill-red' : 'pill-yellow';
  tbody2.innerHTML += `<tr class="alert-row">
    <td><div class="truncate" title="${r.den_articol}">${r.den_articol}</div></td>
    <td><div class="truncate" style="max-width:180px" title="${r.den_furnizor}">${r.den_furnizor}</div></td>
    <td style="text-align:right"><span class="pill ${pillClass}">${r.adaos}%</span></td>
    <td style="text-align:right">${r.aparitii}</td>
    <td style="text-align:right">${r.pret_med} RON</td>
  </tr>`;
});
</script>
</body>
</html>
