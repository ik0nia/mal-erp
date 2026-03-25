<style>
.osr-pills { display:flex; flex-wrap:wrap; align-items:center; gap:0.75rem; }
.osr-pills-label { font-size:0.875rem; color:#6b7280; }
.osr-pill { border-radius:9999px; padding:0.375rem 1rem; font-size:0.875rem; font-weight:500; border:1px solid #e5e7eb; background:#fff; color:#4b5563; cursor:pointer; transition:all 0.15s; }
.osr-pill:hover { background:#f9fafb; }
.osr-pill:focus { outline:none; }
.osr-pill--active { background:#dc2626; color:#fff; border-color:#dc2626; box-shadow:0 1px 2px rgba(0,0,0,0.1); }
.osr-sep { color:#d1d5db; }
.osr-stats { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1rem; }
@@media(min-width:640px){ .osr-stats { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
@@media(min-width:1024px){ .osr-stats { grid-template-columns:repeat(5, minmax(0, 1fr)); } }
.osr-stat { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; padding:1rem; }
.osr-stat-label { font-size:0.75rem; font-weight:500; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; }
.osr-stat-value { margin-top:0.25rem; font-size:1.5rem; font-weight:700; color:#111827; }
.osr-stat-sub { margin-top:0.25rem; font-size:0.75rem; color:#9ca3af; }
.osr-charts { display:grid; grid-template-columns:1fr; gap:1rem; }
@@media(min-width:768px){ .osr-charts { grid-template-columns:2fr 1fr; } }
.osr-card { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; overflow:hidden; }
.osr-card-header { padding:0.75rem 1rem; border-bottom:1px solid #f3f4f6; }
.osr-card-header h3 { font-size:0.875rem; font-weight:600; color:#374151; margin:0; }
.osr-cat-grid { padding:1rem; display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:0.75rem; }
@@media(min-width:640px){ .osr-cat-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
@@media(min-width:768px){ .osr-cat-grid { grid-template-columns:repeat(4, minmax(0, 1fr)); } }
@@media(min-width:1280px){ .osr-cat-grid { grid-template-columns:repeat(6, minmax(0, 1fr)); } }
.osr-cat { border-radius:0.5rem; border:1px solid #e5e7eb; background:#f9fafb; padding:0.75rem; }
.osr-cat-name { font-size:0.75rem; font-weight:600; color:#374151; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.osr-cat-rev { margin-top:0.25rem; font-size:1.125rem; font-weight:700; color:#111827; }
.osr-cat-rev span { font-size:0.75rem; font-weight:400; color:#9ca3af; }
.osr-bar-track { margin-top:0.375rem; height:0.375rem; width:100%; border-radius:9999px; background:#e5e7eb; }
.osr-bar-fill { height:0.375rem; border-radius:9999px; }
.osr-bar-fill--primary { background:#dc2626; }
.osr-bar-fill--indigo { background:#6366f1; }
.osr-bar-fill--emerald { background:#10b981; }
.osr-cat-orders { margin-top:0.25rem; font-size:0.75rem; color:#9ca3af; }
.osr-list-item { padding:0.75rem 1.5rem; display:flex; flex-direction:column; gap:0.25rem; }
.osr-list-item + .osr-list-item { border-top:1px solid #f3f4f6; }
.osr-list-row { display:flex; align-items:center; justify-content:space-between; }
.osr-list-name { font-size:0.875rem; font-weight:500; color:#1f2937; }
.osr-list-rev { font-size:0.875rem; font-weight:600; color:#1f2937; }
.osr-list-orders { font-size:0.75rem; color:#9ca3af; }
.osr-2col { display:grid; grid-template-columns:1fr; gap:1rem; }
@@media(min-width:768px){ .osr-2col { grid-template-columns:1fr 1fr; } }
.osr-table { width:100%; font-size:0.875rem; border-collapse:collapse; }
.osr-table th { padding:0.5rem 1.5rem; text-align:left; font-size:0.75rem; font-weight:500; text-transform:uppercase; color:#6b7280; background:#f9fafb; border-bottom:1px solid #f3f4f6; }
.osr-table td { padding:0.625rem 1.5rem; border-bottom:1px solid #f3f4f6; }
.osr-table tfoot td { border-top:2px solid #e5e7eb; background:#f9fafb; font-weight:600; }
.osr-status-badge { display:inline-flex; padding:0.125rem 0.625rem; border-radius:9999px; font-size:0.75rem; font-weight:500; }
</style>

<x-filament-panels::page>

    {{-- Year / Month filters --}}
    <div class="osr-pills">
        <div style="display:flex; align-items:center; gap:0.5rem;">
            <span class="osr-pills-label">An:</span>
            @foreach($this->availableYears as $yr)
                <button wire:click="setYear({{ $yr }})" class="osr-pill {{ $this->year === $yr ? 'osr-pill--active' : '' }}">{{ $yr }}</button>
            @endforeach
        </div>
        <span class="osr-sep">|</span>
        <div style="display:flex; flex-wrap:wrap; align-items:center; gap:0.375rem;">
            <span class="osr-pills-label">Lună:</span>
            <button wire:click="setMonth(null)" class="osr-pill {{ $this->month === null ? 'osr-pill--active' : '' }}">Toate</button>
            @foreach(['Ian','Feb','Mar','Apr','Mai','Iun','Iul','Aug','Sep','Oct','Nov','Dec'] as $idx => $mn)
                <button wire:click="setMonth({{ $idx + 1 }})" class="osr-pill {{ $this->month === ($idx + 1) ? 'osr-pill--active' : '' }}">{{ $mn }}</button>
            @endforeach
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="osr-stats">
        <div class="osr-stat">
            <div class="osr-stat-label">Total vânzări</div>
            <div class="osr-stat-value" style="color:#dc2626;">{{ number_format($this->statRevenue, 2, ',', '.') }}</div>
            <div class="osr-stat-sub">lei (fără anulate)</div>
        </div>
        <div class="osr-stat">
            <div class="osr-stat-label">Comenzi active</div>
            <div class="osr-stat-value">{{ number_format($this->statOrders) }}</div>
            <div class="osr-stat-sub">fără anulate/eșuate</div>
        </div>
        <div class="osr-stat">
            <div class="osr-stat-label">Medie/comandă</div>
            <div class="osr-stat-value">{{ number_format($this->statAvgOrder, 2, ',', '.') }}</div>
            <div class="osr-stat-sub">lei</div>
        </div>
        <div class="osr-stat">
            <div class="osr-stat-label">Finalizate</div>
            <div class="osr-stat-value" style="color:#16a34a;">{{ number_format($this->statCompleted) }}</div>
            <div class="osr-stat-sub">{{ $this->statOrders > 0 ? round($this->statCompleted / $this->statOrders * 100) . '% din total' : '—' }}</div>
        </div>
        <div class="osr-stat">
            <div class="osr-stat-label">În procesare</div>
            <div class="osr-stat-value" style="color:#d97706;">{{ number_format($this->statProcessing) }}</div>
            <div class="osr-stat-sub">{{ $this->statOrders > 0 ? round($this->statProcessing / $this->statOrders * 100) . '% din total' : '—' }}</div>
        </div>
    </div>

    {{-- Charts --}}
    <div class="osr-charts">
        <div>@livewire(\App\Filament\App\Widgets\SalesChartWidget::class)</div>
        <div>@livewire(\App\Filament\App\Widgets\OrderStatusChartWidget::class)</div>
    </div>

    {{-- Category cards --}}
    @if(count($this->categoryData) > 0)
    @php $maxCatRevenue = max(array_column($this->categoryData, 'revenue')) ?: 1; @endphp
    <div class="osr-card">
        <div class="osr-card-header"><h3>Vânzări pe categorii principale</h3></div>
        <div class="osr-cat-grid">
            @foreach($this->categoryData as $cat)
            <div class="osr-cat">
                <div class="osr-cat-name" title="{{ $cat['name'] }}">{{ $cat['name'] }}</div>
                <div class="osr-cat-rev">{{ number_format($cat['revenue'], 0, ',', '.') }} <span>lei</span></div>
                <div class="osr-bar-track"><div class="osr-bar-fill osr-bar-fill--primary" style="width:{{ round($cat['revenue'] / $maxCatRevenue * 100) }}%"></div></div>
                <div class="osr-cat-orders">{{ number_format($cat['orders']) }} comenzi</div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Supplier + Brand --}}
    <div class="osr-2col">
        @if(count($this->supplierData) > 0)
        @php $maxSupRevenue = max(array_column($this->supplierData, 'revenue')) ?: 1; @endphp
        <div class="osr-card">
            <div class="osr-card-header"><h3>Top furnizori</h3></div>
            @foreach($this->supplierData as $s)
            <div class="osr-list-item">
                <div class="osr-list-row">
                    <span class="osr-list-name">{{ $s['name'] }}</span>
                    <span class="osr-list-rev">{{ number_format($s['revenue'], 2, ',', '.') }} lei</span>
                </div>
                <div class="osr-bar-track"><div class="osr-bar-fill osr-bar-fill--indigo" style="width:{{ round($s['revenue'] / $maxSupRevenue * 100) }}%"></div></div>
                <div class="osr-list-orders">{{ number_format($s['orders']) }} comenzi</div>
            </div>
            @endforeach
        </div>
        @endif

        @if(count($this->brandData) > 0)
        @php $maxBrandRevenue = max(array_column($this->brandData, 'revenue')) ?: 1; @endphp
        <div class="osr-card">
            <div class="osr-card-header"><h3>Top brand-uri</h3></div>
            @foreach($this->brandData as $b)
            <div class="osr-list-item">
                <div class="osr-list-row">
                    <span class="osr-list-name">{{ $b['name'] }}</span>
                    <span class="osr-list-rev">{{ number_format($b['revenue'], 2, ',', '.') }} lei</span>
                </div>
                <div class="osr-bar-track"><div class="osr-bar-fill osr-bar-fill--emerald" style="width:{{ round($b['revenue'] / $maxBrandRevenue * 100) }}%"></div></div>
                <div class="osr-list-orders">{{ number_format($b['orders']) }} comenzi</div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Status breakdown --}}
    @if(count($this->statusData) > 0)
    @php
        $statusLabels = ['completed'=>'Finalizate','processing'=>'În procesare','cancelled'=>'Anulate','on-hold'=>'În așteptare','refunded'=>'Rambursate','failed'=>'Eșuate','pending'=>'În așteptare plată'];
        $statusColors = ['completed'=>'background:#dcfce7;color:#166534;','processing'=>'background:#fee2e2;color:#991b1b;','on-hold'=>'background:#fef3c7;color:#92400e;','cancelled'=>'background:#fecaca;color:#991b1b;','refunded'=>'background:#ffedd5;color:#9a3412;','failed'=>'background:#f3f4f6;color:#374151;','pending'=>'background:#f3f4f6;color:#374151;'];
    @endphp
    <div class="osr-card">
        <div class="osr-card-header"><h3>Detaliu comenzi pe status</h3></div>
        <div style="overflow-x:auto;">
            <table class="osr-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th style="text-align:right;">Comenzi</th>
                        <th style="text-align:right;">Valoare (lei)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->statusData as $s)
                    <tr>
                        <td><span class="osr-status-badge" style="{{ $statusColors[$s['status']] ?? 'background:#f3f4f6;color:#374151;' }}">{{ $statusLabels[$s['status']] ?? $s['status'] }}</span></td>
                        <td style="text-align:right; color:#4b5563;">{{ number_format($s['cnt']) }}</td>
                        <td style="text-align:right; font-weight:600; color:#111827;">{{ number_format($s['revenue'], 2, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td style="font-weight:600; color:#374151;">Total</td>
                        <td style="text-align:right; font-weight:600; color:#374151;">{{ number_format(array_sum(array_column($this->statusData, 'cnt'))) }}</td>
                        <td style="text-align:right; font-weight:700; color:#dc2626;">{{ number_format(array_sum(array_column($this->statusData, 'revenue')), 2, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endif

</x-filament-panels::page>
