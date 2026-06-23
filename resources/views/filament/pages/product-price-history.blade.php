<x-filament-panels::page>

    @php $product = $this->product; @endphp

    {{-- Selector produs --}}
    @if(! $product)
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1.25rem;">
            <p style="font-size:0.85rem; color:#374151; margin:0 0 0.6rem; font-weight:600;">Caută un produs (SKU sau denumire)</p>
            <input type="text" wire:model.live.debounce.350ms="search"
                placeholder="ex: 5906083119101 sau Cot Alamă"
                style="width:100%; padding:0.5rem 0.75rem; border:1px solid #d1d5db; border-radius:0.5rem; font-size:0.9rem;">
            @php $results = $this->results(); @endphp
            @if($results->isNotEmpty())
                <div style="margin-top:0.6rem; border:1px solid #f3f4f6; border-radius:0.5rem; overflow:hidden;">
                    @foreach($results as $r)
                        <button wire:click="select({{ $r->id }})"
                            style="display:flex; gap:0.75rem; width:100%; text-align:left; padding:0.5rem 0.75rem; border:none; border-top:1px solid #f3f4f6; background:#fff; cursor:pointer; font-size:0.85rem;">
                            <span style="font-family:monospace; color:#4f46e5;">{{ $r->sku }}</span>
                            <span style="color:#374151;">{{ \Illuminate\Support\Str::limit($r->name, 60) }}</span>
                        </button>
                    @endforeach
                </div>
            @elseif(mb_strlen(trim($search)) >= 2)
                <p style="font-size:0.8rem; color:#9ca3af; margin-top:0.5rem;">Niciun produs găsit.</p>
            @endif
        </div>

        {{-- Ultimele 30 de produse cu schimbare de preț (clickabile) --}}
        @php $recent = $this->recentlyChanged(); @endphp
        @if($recent->isNotEmpty())
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.25rem; margin-top:1.25rem;">
                <p style="font-size:0.85rem; font-weight:600; color:#374151; margin:0 0 0.6rem;">Ultimele 30 de produse cu schimbare de preț de vânzare</p>
                <table style="width:100%; border-collapse:collapse; font-size:0.82rem;">
                    <thead><tr style="text-align:left; color:#6b7280;">
                        <th style="padding:0.35rem 0.5rem;">Data</th>
                        <th style="padding:0.35rem 0.5rem;">Produs</th>
                        <th style="padding:0.35rem 0.5rem; text-align:right;">Vechi</th>
                        <th style="padding:0.35rem 0.5rem; text-align:right;">Nou</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    @foreach($recent as $log)
                        <tr wire:click="select({{ $log->woo_product_id }})" style="border-top:1px solid #f3f4f6; cursor:pointer;"
                            onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background=''">
                            <td style="padding:0.35rem 0.5rem; white-space:nowrap;">{{ $log->changed_at?->format('d.m.Y H:i') }}</td>
                            <td style="padding:0.35rem 0.5rem;">
                                <span style="font-family:monospace; color:#4f46e5;">{{ $log->product?->sku }}</span>
                                <span style="color:#374151;">{{ \Illuminate\Support\Str::limit($log->product?->name, 42) }}</span>
                            </td>
                            <td style="padding:0.35rem 0.5rem; text-align:right; color:#9ca3af;">{{ number_format((float)$log->old_price, 2, ',', '.') }}</td>
                            <td style="padding:0.35rem 0.5rem; text-align:right; font-weight:600; color:{{ (float)$log->new_price >= (float)$log->old_price ? '#16a34a' : '#dc2626' }};">
                                {{ number_format((float)$log->new_price, 2, ',', '.') }} {{ (float)$log->new_price >= (float)$log->old_price ? '↑' : '↓' }}
                            </td>
                            <td style="padding:0.35rem 0.5rem; color:#4f46e5; white-space:nowrap;">vezi →</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @else
        @php
            $payload = $this->chartPayload();
            $sale = $this->saleLogs();
            $purchase = $this->purchaseLogs();
        @endphp

        {{-- Antet produs --}}
        @php
            $lastPurchase = $this->latestPurchase();
            $currentSale = $this->currentSalePrice();
            $currentSaleNet = $this->currentSaleNet();
            $vat = $this->vatRate();
        @endphp
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.25rem; margin-bottom:1rem;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span style="font-family:monospace; color:#4f46e5; font-size:0.85rem;">{{ $product->sku }}</span>
                    <span style="font-weight:600; color:#111827; margin-left:0.5rem;">{{ $product->name }}</span>
                </div>
                <button wire:click="clearProduct"
                    style="padding:0.4rem 0.9rem; background:#f3f4f6; color:#374151; border:none; border-radius:0.5rem; font-size:0.8rem; cursor:pointer;">
                    ← Schimbă produsul
                </button>
            </div>
            <div style="display:flex; gap:0.5rem; margin-top:0.75rem; flex-wrap:wrap;">
                <span style="background:#eef2ff; color:#3730a3; padding:0.3rem 0.7rem; border-radius:9999px; font-size:0.8rem; font-weight:600;">
                    Vânzare curent (cu TVA): {{ $currentSale !== null ? number_format($currentSale, 2, ',', '.').' lei' : '—' }}
                </span>
                <span style="background:#e0e7ff; color:#3730a3; padding:0.3rem 0.7rem; border-radius:9999px; font-size:0.8rem; font-weight:600;">
                    Vânzare fără TVA: {{ $currentSaleNet !== null ? number_format($currentSaleNet, 2, ',', '.').' lei' : '—' }}
                    <span style="opacity:.7; font-weight:400;">(TVA {{ rtrim(rtrim(number_format($vat, 2, '.', ''), '0'), '.') }}%)</span>
                </span>
                @if($lastPurchase)
                    <span style="background:#dcfce7; color:#166534; padding:0.3rem 0.7rem; border-radius:9999px; font-size:0.8rem; font-weight:600;">
                        Ultima achiziție (fără TVA): {{ number_format((float)$lastPurchase->unit_price, 2, ',', '.') }} {{ $lastPurchase->currency }}
                    </span>
                    @if($currentSaleNet !== null && $lastPurchase->currency === 'RON' && (float)$lastPurchase->unit_price > 0)
                        @php $margin = round(($currentSaleNet - (float)$lastPurchase->unit_price) / (float)$lastPurchase->unit_price * 100); @endphp
                        <span style="background:{{ $margin >= 0 ? '#dcfce7' : '#fee2e2' }}; color:{{ $margin >= 0 ? '#166534' : '#991b1b' }}; padding:0.3rem 0.7rem; border-radius:9999px; font-size:0.8rem; font-weight:700;">
                            Adaos net: {{ $margin >= 0 ? '+' : '' }}{{ $margin }}%
                            <span style="opacity:.7; font-weight:400;">(vânzare fără TVA vs. ultima achiziție)</span>
                        </span>
                    @endif
                @endif
            </div>
        </div>

        {{-- Grafic --}}
        @if(count($payload['labels']) > 0)
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1.25rem; margin-bottom:1rem;">
                <p style="font-size:0.8rem; color:#6b7280; margin:0 0 0.75rem; font-weight:600;">Evoluție preț (vânzare vs. achiziție)</p>
                <div wire:key="chart-{{ $product->id }}" wire:ignore style="height:300px;"
                     x-data="{
                        payload: @js($payload),
                        chart: null,
                        init() {
                            if (! window.Chart) {
                                const s = document.createElement('script');
                                s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
                                document.head.appendChild(s);
                            }
                            const build = () => {
                                if (! window.Chart) { setTimeout(build, 120); return; }
                                if (this.chart) { this.chart.destroy(); }
                                this.chart = new window.Chart(this.$refs.cv, {
                                    type: 'line',
                                    data: { labels: this.payload.labels, datasets: [
                                        { label: 'Preț vânzare (cu TVA)', data: this.payload.sale, borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,.10)', tension: 0.35, pointRadius: 0, borderWidth: 2.5, fill: true },
                                        { label: 'Preț achiziție (fără TVA)', data: this.payload.purchase, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.08)', spanGaps: true, tension: 0.35, pointRadius: 2.5, borderWidth: 2, fill: false },
                                    ]},
                                    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false } }
                                });
                            };
                            build();
                        }
                     }">
                    <canvas x-ref="cv"></canvas>
                </div>
                <p style="font-size:0.72rem; color:#9ca3af; margin:0.75rem 0 0;">
                    ⓘ Prețul de <strong>vânzare</strong> este cel de pe site, <strong>cu TVA inclus</strong> ({{ rtrim(rtrim(number_format($vat, 2, '.', ''), '0'), '.') }}%). Prețul de <strong>achiziție</strong> este <strong>net (fără TVA)</strong>, din facturile furnizorilor. Pentru adaosul real, compară achiziția cu „Vânzare fără TVA" din antet.
                </p>
            </div>
        @endif

        {{-- Tabel vânzare --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.25rem; margin-bottom:1rem;">
            <p style="font-size:0.8rem; color:#374151; margin:0 0 0.5rem; font-weight:600;">Istoric preț vânzare ({{ $sale->total() }} total)</p>
            @if($sale->total() === 0)
                <p style="font-size:0.8rem; color:#9ca3af; margin:0;">Fără istoric de preț vânzare.</p>
            @else
                <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
                    <thead><tr style="text-align:left; color:#6b7280;">
                        <th style="padding:0.3rem 0.5rem;">Data</th><th style="padding:0.3rem 0.5rem;">Vechi</th>
                        <th style="padding:0.3rem 0.5rem;">Nou</th><th style="padding:0.3rem 0.5rem;">Sursă</th>
                        <th style="padding:0.3rem 0.5rem;">Locație</th>
                    </tr></thead>
                    <tbody>
                    @foreach($sale as $l)
                        <tr style="border-top:1px solid #f3f4f6;">
                            <td style="padding:0.3rem 0.5rem;">{{ $l->changed_at?->format('d.m.Y H:i') }}</td>
                            <td style="padding:0.3rem 0.5rem; color:#9ca3af;">{{ number_format((float)$l->old_price, 2, ',', '.') }}</td>
                            <td style="padding:0.3rem 0.5rem; font-weight:600;">{{ number_format((float)$l->new_price, 2, ',', '.') }}</td>
                            <td style="padding:0.3rem 0.5rem;">{{ $l->source }}</td>
                            <td style="padding:0.3rem 0.5rem;">{{ $l->location?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div style="margin-top:0.6rem;">{{ $sale->links() }}</div>
            @endif
        </div>

        {{-- Tabel achiziție --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1rem 1.25rem;">
            <p style="font-size:0.8rem; color:#374151; margin:0 0 0.5rem; font-weight:600;">Istoric preț achiziție ({{ $purchase->total() }} total)</p>
            @if($purchase->total() === 0)
                <p style="font-size:0.8rem; color:#9ca3af; margin:0;">Fără istoric de preț achiziție.</p>
            @else
                <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
                    <thead><tr style="text-align:left; color:#6b7280;">
                        <th style="padding:0.3rem 0.5rem;">Data</th><th style="padding:0.3rem 0.5rem;">Furnizor</th>
                        <th style="padding:0.3rem 0.5rem;">Preț unitar</th><th style="padding:0.3rem 0.5rem;">Mon.</th>
                        <th style="padding:0.3rem 0.5rem;">Doc.</th>
                    </tr></thead>
                    <tbody>
                    @foreach($purchase as $l)
                        <tr style="border-top:1px solid #f3f4f6; {{ $l->has_anomaly ? 'background:#fef6f5;' : '' }}">
                            <td style="padding:0.3rem 0.5rem;">{{ $l->acquired_at?->format('d.m.Y') }}</td>
                            <td style="padding:0.3rem 0.5rem;">{{ \Illuminate\Support\Str::limit($l->supplier_name_raw ?? '—', 28) }}</td>
                            <td style="padding:0.3rem 0.5rem; font-weight:600;">{{ number_format((float)$l->unit_price, 2, ',', '.') }} @if($l->has_anomaly)<span title="Anomalie preț">⚠</span>@endif</td>
                            <td style="padding:0.3rem 0.5rem;">{{ $l->currency }}</td>
                            <td style="padding:0.3rem 0.5rem;">{{ $l->nr_doc }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div style="margin-top:0.6rem;">{{ $purchase->links() }}</div>
            @endif
        </div>
    @endif

</x-filament-panels::page>
