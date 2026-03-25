<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="15">
    <title>Import Toya — Progres</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f6f9; color: #1a1a2e; min-height: 100vh; padding: 2rem; }
        h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; }
        .subtitle { color: #6b7280; font-size: 0.875rem; margin-bottom: 2rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); text-align: center; }
        .card .num { font-size: 2.5rem; font-weight: 800; line-height: 1; }
        .card .label { font-size: 0.8rem; color: #6b7280; margin-top: 0.4rem; }
        .card .pct { font-size: 0.75rem; color: #9ca3af; margin-top: 0.2rem; }
        .bar-wrap { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); margin-bottom: 2rem; }
        .bar-wrap h2 { font-size: 1rem; font-weight: 600; margin-bottom: 1rem; }
        .bar-row { margin-bottom: 0.75rem; }
        .bar-row .bar-label { display: flex; justify-content: space-between; font-size: 0.8rem; color: #374151; margin-bottom: 0.3rem; }
        .bar-bg { background: #e5e7eb; border-radius: 99px; height: 10px; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 99px; transition: width 0.3s; }
        .blue   { color: #3b82f6; } .bar-fill.blue   { background: #3b82f6; }
        .purple { color: #8b5cf6; } .bar-fill.purple { background: #8b5cf6; }
        .orange { color: #f97316; } .bar-fill.orange { background: #f97316; }
        .green  { color: #22c55e; } .bar-fill.green  { background: #22c55e; }
        .gray   { color: #6b7280; }
        .refresh { text-align: center; font-size: 0.75rem; color: #9ca3af; }
        @media (max-width: 600px) { body { padding: 1rem; } .card .num { font-size: 2rem; } }
    </style>
</head>
<body>

    <h1>Import Toya Romania — Progres</h1>
    <p class="subtitle">Pagina se actualizează automat la fiecare 15 secunde</p>

    <div class="grid">
        <div class="card">
            <div class="num gray">{{ number_format($total, 0, '.', '') }}</div>
            <div class="label">Total importate</div>
            <div class="pct">din ~15.625 cu preț RO</div>
        </div>
        <div class="card">
            <div class="num blue">{{ number_format($instock, 0, '.', '') }}</div>
            <div class="label">În stoc la Toya</div>
            <div class="pct">{{ $pct($instock) }}%</div>
        </div>
        <div class="card">
            <div class="num purple">{{ number_format($withImage, 0, '.', '') }}</div>
            <div class="label">Cu imagine</div>
            <div class="pct">{{ $pct($withImage) }}%</div>
        </div>
        <div class="card">
            <div class="num orange">{{ number_format($withCat, 0, '.', '') }}</div>
            <div class="label">Cu categorie</div>
            <div class="pct">{{ $pct($withCat) }}%</div>
        </div>
        <div class="card">
            <div class="num green">{{ number_format($readyToPub, 0, '.', '') }}</div>
            <div class="label">Gata de publicat</div>
            <div class="pct">{{ $pct($readyToPub) }}%</div>
        </div>
    </div>

    <div class="bar-wrap">
        <h2>Completitudine catalog</h2>

        <div class="bar-row">
            <div class="bar-label">
                <span class="blue">Imagini</span>
                <span>{{ number_format($withImage, 0, '.', '') }} / {{ number_format($total, 0, '.', '') }} &nbsp; {{ $pct($withImage) }}%</span>
            </div>
            <div class="bar-bg"><div class="bar-fill blue" style="width: {{ $pct($withImage) }}%"></div></div>
        </div>

        <div class="bar-row">
            <div class="bar-label">
                <span class="purple">Descrieri</span>
                <span>{{ number_format($withDesc, 0, '.', '') }} / {{ number_format($total, 0, '.', '') }} &nbsp; {{ $pct($withDesc) }}%</span>
            </div>
            <div class="bar-bg"><div class="bar-fill purple" style="width: {{ $pct($withDesc) }}%"></div></div>
        </div>

        <div class="bar-row">
            <div class="bar-label">
                <span class="orange">Categorii</span>
                <span>{{ number_format($withCat, 0, '.', '') }} / {{ number_format($total, 0, '.', '') }} &nbsp; {{ $pct($withCat) }}%</span>
            </div>
            <div class="bar-bg"><div class="bar-fill orange" style="width: {{ $pct($withCat) }}%"></div></div>
        </div>

        <div class="bar-row">
            <div class="bar-label">
                <span class="green">Gata de publicat</span>
                <span>{{ number_format($readyToPub, 0, '.', '') }} / {{ number_format($total, 0, '.', '') }} &nbsp; {{ $pct($readyToPub) }}%</span>
            </div>
            <div class="bar-bg"><div class="bar-fill green" style="width: {{ $pct($readyToPub) }}%"></div></div>
        </div>
    </div>

    <p class="refresh">Ultima actualizare: {{ now()->format('H:i:s') }}</p>

</body>
</html>
