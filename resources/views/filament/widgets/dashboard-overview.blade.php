@php
    $sys = $this->system();
    $biz = $this->business();
    $sales = $this->sales();
    $oos = $this->supplierStockouts();
    $chart = $this->priceChart();
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
    $oosMax = collect($oos)->max('cnt') ?: 1;
    $statusColors = ['completed' => '#16a34a', 'processing' => '#d97706', 'cancelled' => '#9ca3af', 'refunded' => '#dc2626', 'on-hold' => '#6366f1', 'pending' => '#6366f1'];
@endphp

<div style="display:flex; flex-direction:column; gap:0.85rem;">

    {{-- ───── Stare sistem (strip compact) ───── --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:0.6rem;">
        @php
            $pills = [
                ['MentorAPI / WinMentor', $sys['bridge'] ? 'Conectat' : 'Indisponibil', $sys['bridge'] ? '#16a34a' : '#dc2626', 'heroicon-m-bolt'],
                ['Disponibilitate (lună)', $sys['uptime'] !== null ? $sys['uptime'].'%' : '—', $sys['uptime'] === null ? '#9ca3af' : ($sys['uptime'] >= 99 ? '#16a34a' : ($sys['uptime'] >= 95 ? '#d97706' : '#dc2626')), 'heroicon-m-signal'],
                ['Cozi (Horizon)', $sys['failed'] === 0 ? 'OK' : $sys['failed'].' eșuate', $sys['failed'] === 0 ? '#16a34a' : '#d97706', 'heroicon-m-queue-list'],
                ['Backup DB', $sys['backup_age'] ?? 'necunoscut', $sys['backup_stale'] ? '#dc2626' : '#16a34a', 'heroicon-m-circle-stack'],
                ['Securitate', $sys['breaches'] === 0 ? 'Curat' : $sys['breaches'].' deschise', $sys['breaches'] === 0 ? '#16a34a' : '#dc2626', 'heroicon-m-shield-check'],
            ];
        @endphp
        @foreach($pills as [$label, $val, $color, $icon])
            <div style="background:#fff; border:1px solid #e5e7eb; border-left:3px solid {{ $color }}; border-radius:0.6rem; padding:0.6rem 0.8rem;">
                <div style="font-size:0.68rem; color:#6b7280; text-transform:uppercase; letter-spacing:.03em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $label }}</div>
                <div style="font-size:1rem; font-weight:700; color:{{ $color }};">{{ $val }}</div>
            </div>
        @endforeach
    </div>

    {{-- ───── Secțiuni grupate ───── --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(290px,1fr)); gap:0.85rem;">

        {{-- Business & stoc --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.1rem;">
            <div style="font-size:0.7rem; font-weight:700; color:#4f46e5; text-transform:uppercase; letter-spacing:.04em; margin-bottom:0.7rem;">Business &amp; stoc</div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.7rem;">
                <div><div style="font-size:1.35rem; font-weight:800; color:#111827;">{{ $fmt($biz['published']) }}</div><div style="font-size:0.72rem; color:#6b7280;">produse publicate / {{ $fmt($biz['total']) }}</div></div>
                <div><div style="font-size:1.35rem; font-weight:800; color:#16a34a;">{{ $fmt($biz['stock_value']) }}</div><div style="font-size:0.72rem; color:#6b7280;">valoare stoc (lei)</div></div>
                <div><div style="font-size:1.35rem; font-weight:800; color:#dc2626;">{{ $fmt($biz['zero_stock']) }}</div><div style="font-size:0.72rem; color:#6b7280;">produse pe stoc 0</div></div>
                <div><div style="font-size:1.35rem; font-weight:800; color:#d97706;">{{ $fmt($biz['price_today']) }}</div><div style="font-size:0.72rem; color:#6b7280;">modif. preț azi ({{ $biz['price_7d'] }}/7z)</div></div>
            </div>
        </div>

        {{-- Vânzări --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.1rem;">
            <div style="font-size:0.7rem; font-weight:700; color:#4f46e5; text-transform:uppercase; letter-spacing:.04em; margin-bottom:0.7rem;">Vânzări online</div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.5rem; margin-bottom:0.7rem;">
                @foreach(['today'=>'Azi','week'=>'7 zile','month'=>'Luna'] as $k=>$lbl)
                    <div style="text-align:center; background:#f8fafc; border-radius:0.5rem; padding:0.45rem;">
                        <div style="font-size:1.2rem; font-weight:800; color:#111827;">{{ $sales[$k]['n'] }}</div>
                        <div style="font-size:0.66rem; color:#6b7280;">{{ $lbl }} · {{ $fmt($sales[$k]['v']) }} lei</div>
                    </div>
                @endforeach
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:0.3rem;">
                @foreach($sales['statuses'] as $st=>$c)
                    <span style="font-size:0.68rem; padding:0.15rem 0.5rem; border-radius:9999px; background:{{ ($statusColors[$st] ?? '#6b7280') }}1a; color:{{ $statusColors[$st] ?? '#6b7280' }}; font-weight:600;">{{ $st }}: {{ $c }}</span>
                @endforeach
            </div>
        </div>

        {{-- Furnizori cu ruperi de stoc --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.1rem;">
            <div style="font-size:0.7rem; font-weight:700; color:#4f46e5; text-transform:uppercase; letter-spacing:.04em; margin-bottom:0.7rem;">Furnizori — ruperi de stoc</div>
            @forelse($oos as $o)
                <div style="margin-bottom:0.5rem;">
                    <div style="display:flex; justify-content:space-between; font-size:0.78rem; margin-bottom:0.15rem;">
                        <span style="color:#374151; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:70%;">{{ $o->name }}</span>
                        <span style="font-weight:700; color:#dc2626;">{{ $fmt($o->cnt) }}</span>
                    </div>
                    <div style="height:5px; background:#f3f4f6; border-radius:9999px; overflow:hidden;">
                        <div style="height:100%; width:{{ round($o->cnt / $oosMax * 100) }}%; background:#dc2626;"></div>
                    </div>
                </div>
            @empty
                <p style="font-size:0.8rem; color:#9ca3af;">Niciun produs pe stoc 0.</p>
            @endforelse
        </div>

    </div>

    {{-- ───── Grafic modificări preț ───── --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.1rem;">
        <div style="font-size:0.7rem; font-weight:700; color:#4f46e5; text-transform:uppercase; letter-spacing:.04em; margin-bottom:0.6rem;">Modificări de preț de vânzare / zi (30 zile)</div>
        <div wire:ignore style="height:220px;"
             x-data="{
                payload: @js($chart),
                chart: null,
                init() {
                    if (! window.Chart) {
                        const s = document.createElement('script');
                        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
                        document.head.appendChild(s);
                    }
                    const build = () => {
                        if (! window.Chart) { setTimeout(build, 120); return; }
                        this.chart = new window.Chart(this.$refs.cv, {
                            type: 'bar',
                            data: { labels: this.payload.labels, datasets: [{ label: 'Modificări preț', data: this.payload.data, backgroundColor: 'rgba(79,70,229,0.7)', borderRadius: 4 }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
                        });
                    };
                    build();
                }
             }">
            <canvas x-ref="cv"></canvas>
        </div>
    </div>

</div>
