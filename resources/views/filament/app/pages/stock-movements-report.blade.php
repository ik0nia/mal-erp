<x-filament-panels::page>

<style>
.smr-pills { display:flex; flex-wrap:wrap; align-items:center; gap:0.75rem; }
.smr-pills-label { font-size:0.875rem; color:#6b7280; }
.smr-pill { border-radius:9999px; padding:0.375rem 1rem; font-size:0.875rem; font-weight:500; border:1px solid #e5e7eb; background:#fff; color:#4b5563; cursor:pointer; transition:all 0.15s; }
.smr-pill:hover { background:#f9fafb; }
.smr-pill:focus { outline:none; }
.smr-pill--active { background:#dc2626; color:#fff; border-color:#dc2626; box-shadow:0 1px 2px rgba(0,0,0,0.1); }
.smr-sep { color:#d1d5db; }
.smr-select { border-radius:0.5rem; border:1px solid #e5e7eb; background:#fff; padding:0.375rem 0.5rem; font-size:0.875rem; color:#374151; }
.smr-clear { font-size:0.75rem; color:#9ca3af; cursor:pointer; background:none; border:none; }
.smr-clear:hover { color:#dc2626; }
.smr-cat-chip { border-radius:9999px; background:#fee2e2; padding:0.25rem 0.75rem; font-size:0.875rem; font-weight:500; color:#991b1b; }
.smr-stats { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1rem; }
@@media(min-width:640px){ .smr-stats { grid-template-columns:repeat(4, minmax(0, 1fr)); } }
.smr-stat { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; padding:1rem; }
.smr-stat-label { font-size:0.75rem; font-weight:500; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; }
.smr-stat-value { margin-top:0.25rem; font-size:1.875rem; font-weight:700; }
.smr-stat-sub { margin-top:0.25rem; font-size:0.75rem; color:#9ca3af; }
.smr-charts { display:grid; grid-template-columns:1fr; gap:1rem; }
@@media(min-width:768px){ .smr-charts { grid-template-columns:1fr 1fr; } }
.smr-card { border-radius:0.875rem; border:1px solid #e2e8f0; background:#fff; overflow:hidden; box-shadow:0 1px 2px rgba(15,23,42,0.05); }
.smr-card-header { padding:0.75rem 1rem; border-bottom:1px solid #f1f5f9; background:linear-gradient(180deg,#fafbfc,#f8fafc); }
.smr-card-header h3 { font-size:0.875rem; font-weight:600; color:#374151; margin:0; }
.smr-table { width:100%; font-size:0.875rem; border-collapse:collapse; }
.smr-table th { padding:0.5rem 1rem; text-align:left; font-size:0.75rem; font-weight:500; text-transform:uppercase; color:#6b7280; background:#f9fafb; border-bottom:1px solid #f3f4f6; }
.smr-table td { padding:0.625rem 1rem; border-bottom:1px solid #f3f4f6; }
.smr-table tr { cursor:pointer; transition:background 0.1s; }
.smr-table tr:hover td { background:#f9fafb; }
.smr-table tr.smr-row--active td { background:#fef2f2; }
.smr-cat-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:0.75rem; }
@@media(min-width:640px){ .smr-cat-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
@@media(min-width:768px){ .smr-cat-grid { grid-template-columns:repeat(4, minmax(0, 1fr)); } }
@@media(min-width:1024px){ .smr-cat-grid { grid-template-columns:repeat(5, minmax(0, 1fr)); } }
@@media(min-width:1280px){ .smr-cat-grid { grid-template-columns:repeat(6, minmax(0, 1fr)); } }
.smr-cat-btn { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; padding:0.75rem; text-align:left; cursor:pointer; transition:all 0.15s; }
.smr-cat-btn:hover { border-color:#dc2626; background:#fef2f2; }
.smr-cat-btn:focus { outline:none; }
.smr-cat-btn--active { border-color:#dc2626; background:#fef2f2; box-shadow:0 0 0 1px #dc2626; }
.smr-cat-name { font-size:0.75rem; font-weight:600; color:#374151; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.smr-cat-value { margin-top:0.25rem; font-size:1.125rem; font-weight:700; color:#111827; }
.smr-cat-value span { font-size:0.75rem; font-weight:400; color:#9ca3af; }
.smr-cat-sub { margin-top:0.125rem; font-size:0.75rem; color:#9ca3af; }
</style>

    {{-- Filters --}}
    <div class="smr-pills">
        <div style="display:flex; align-items:center; gap:0.5rem;">
            <span class="smr-pills-label">Interval:</span>
            @foreach([7 => '7 zile', 14 => '14 zile', 30 => '30 zile'] as $n => $label)
                <button wire:click="setDays({{ $n }})" class="smr-pill {{ $this->days === $n ? 'smr-pill--active' : '' }}">{{ $label }}</button>
            @endforeach
        </div>
        <span class="smr-sep">|</span>
        <div style="display:flex; align-items:center; gap:0.5rem;">
            <span class="smr-pills-label">Furnizor:</span>
            <select wire:change="setSupplier($event.target.value ? Number($event.target.value) : null)" class="smr-select">
                <option value="">Toți furnizorii</option>
                @foreach($this->supplierOptions as $id => $name)
                    <option value="{{ $id }}" {{ $this->supplierId === $id ? 'selected' : '' }}>{{ $name }}</option>
                @endforeach
            </select>
            @if($this->supplierId)
                <button wire:click="setSupplier(null)" class="smr-clear" title="Șterge filtrul">✕</button>
            @endif
        </div>
        @if($this->categoryId)
            <span class="smr-sep">|</span>
            <div style="display:flex; align-items:center; gap:0.375rem;">
                <span class="smr-pills-label">Categorie:</span>
                <span class="smr-cat-chip">{{ $this->categoryName }}</span>
                <button wire:click="setCategory(null)" class="smr-clear" title="Șterge filtrul">✕</button>
            </div>
        @endif
    </div>

    {{-- Stat cards --}}
    <div class="erp-stat-grid">
        <div class="erp-stat erp-stat--success">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-arrow-down-tray" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Intrări stoc</p>
                <p class="erp-stat-value">{{ number_format($this->statTotalInQty, 0, '.', '') }}</p>
                <p class="erp-stat-sub">{{ number_format($this->statTotalInValue, 2, ',', '.') }} lei</p>
            </div>
        </div>
        <div class="erp-stat erp-stat--danger">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-arrow-up-tray" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Ieșiri stoc</p>
                <p class="erp-stat-value">{{ number_format($this->statTotalOutQty, 0, '.', '') }}</p>
                <p class="erp-stat-sub">{{ number_format($this->statTotalOutValue, 2, ',', '.') }} lei</p>
            </div>
        </div>
        <div class="erp-stat erp-stat--info">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-cube" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Produse cu mișcări</p>
                <p class="erp-stat-value">{{ number_format($this->statProductsWithMovement, 0, '.', '') }}</p>
                <p class="erp-stat-sub">în ultimele {{ $this->days }} zile</p>
            </div>
        </div>
        <div class="erp-stat erp-stat--warning">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-receipt-percent" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Prețuri modificate</p>
                <p class="erp-stat-value">{{ number_format($this->statProductsWithPriceChange, 0, '.', '') }}</p>
                <p class="erp-stat-sub">în ultimele {{ $this->days }} zile</p>
            </div>
        </div>
    </div>

    {{-- Charts --}}
    <div class="smr-charts">
        @livewire(\App\Filament\App\Widgets\StockMovementChartWidget::class)
        @livewire(\App\Filament\App\Widgets\PriceMovementChartWidget::class)
    </div>

    @if(count($this->supplierStats) > 0)
    <div>@livewire(\App\Filament\App\Widgets\SupplierMovementChartWidget::class)</div>
    @endif

    {{-- Supplier stats table --}}
    @if(count($this->supplierStats) > 0)
    <div class="smr-card">
        <div class="smr-card-header"><h3>Statistici furnizori — ultimele {{ $this->days }} zile</h3></div>
        <div style="overflow-x:auto;">
            <table class="smr-table">
                <thead>
                    <tr>
                        <th>Furnizor</th>
                        <th style="text-align:right;">Produse</th>
                        <th style="text-align:right;">Intrări (buc)</th>
                        <th style="text-align:right;">Ieșiri (buc)</th>
                        <th style="text-align:right;">Val. mișcări (lei)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->supplierStats as $s)
                    <tr wire:click="setSupplier({{ $this->supplierId === $s['id'] ? 'null' : $s['id'] }})"
                        class="{{ $this->supplierId === $s['id'] ? 'smr-row--active' : '' }}">
                        <td style="font-weight:500; color:#1f2937;">
                            {{ $s['name'] }}
                            @if($this->supplierId === $s['id'])
                                <span style="margin-left:0.25rem; font-size:0.75rem; color:#dc2626;">● filtrat</span>
                            @endif
                        </td>
                        <td style="text-align:right; color:#4b5563;">{{ number_format($s['products'], 0, '.', '') }}</td>
                        <td style="text-align:right; color:#16a34a;">+{{ number_format($s['in_qty'], 0, '.', '') }}</td>
                        <td style="text-align:right; color:#dc2626;">-{{ number_format($s['out_qty'], 0, '.', '') }}</td>
                        <td style="text-align:right; font-weight:600; color:#111827;">{{ number_format($s['value'], 2, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Category cards --}}
    @if(count($this->categoryStats) > 0)
    <div class="smr-cat-grid">
        @foreach($this->categoryStats as $cat)
        <button wire:click="setCategory({{ $this->categoryId === $cat['id'] ? 'null' : $cat['id'] }})"
            class="smr-cat-btn {{ $this->categoryId === $cat['id'] ? 'smr-cat-btn--active' : '' }}">
            <div class="smr-cat-name">{{ $cat['name'] }}</div>
            <div class="smr-cat-value">{{ number_format($cat['value'], 0, ',', '.') }} <span>lei</span></div>
            <div class="smr-cat-sub">{{ number_format($cat['products'], 0, '.', '') }} produse</div>
        </button>
        @endforeach
    </div>
    @endif

    {{-- Top movers table --}}
    <div>
        <p style="margin-bottom:0.5rem; font-size:0.875rem; color:#6b7280;">
            Produse cu cele mai mari mișcări de stoc în ultimele <strong>{{ $this->days }}</strong> zile
            @if($this->supplierId && isset($this->supplierOptions[$this->supplierId]))
                — furnizor: <strong>{{ $this->supplierOptions[$this->supplierId] }}</strong>
            @endif
            @if($this->categoryId && $this->categoryName)
                — categorie: <strong>{{ $this->categoryName }}</strong>
            @endif.
            Click pe produs pentru detalii.
        </p>
        {{ $this->table }}
    </div>

</x-filament-panels::page>
