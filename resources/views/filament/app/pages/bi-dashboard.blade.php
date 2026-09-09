<x-filament-panels::page>

  <style>
  .bi-kpi { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1rem; }
  @@media(min-width:640px){ .bi-kpi { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
  @@media(min-width:1280px){ .bi-kpi { grid-template-columns:repeat(6, minmax(0, 1fr)); } }
  .bi-kpi-card { border-radius:0.875rem; border:1px solid #e2e8f0; background:#fff; padding:1rem 1.25rem; box-shadow:0 1px 2px rgba(15,23,42,0.05); transition:box-shadow 0.18s ease, transform 0.18s ease; }
  .bi-kpi-card:hover { box-shadow:0 6px 16px -4px rgba(15,23,42,0.12); transform:translateY(-1px); }
  .bi-kpi-card--wide { grid-column:span 2; }
  @@media(min-width:640px){ .bi-kpi-card--wide { grid-column:span 1; } }
  @@media(min-width:1280px){ .bi-kpi-card--wide { grid-column:span 2; } }
  .bi-kpi-label { font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#9ca3af; }
  .bi-kpi-value { margin-top:0.25rem; font-size:1.5rem; font-weight:700; color:#111827; }
  .bi-kpi-sub { margin-top:0.25rem; font-size:0.75rem; color:#9ca3af; }
  .bi-kpi-delta { margin-top:0.25rem; display:flex; align-items:center; gap:0.5rem; }
  .bi-card { border-radius:0.875rem; border:1px solid #e2e8f0; background:#fff; overflow:hidden; box-shadow:0 1px 2px rgba(15,23,42,0.05); }
  .bi-card-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.75rem; padding:0.75rem 1.25rem; border-bottom:1px solid #f1f5f9; background:linear-gradient(180deg,#fafbfc,#f8fafc); }
  .bi-card-title { font-size:0.875rem; font-weight:600; color:#1f2937; }
  .bi-card-subtitle { font-size:0.75rem; color:#9ca3af; margin-top:0.125rem; }
  .bi-pills { display:flex; align-items:center; gap:0.5rem; }
  .bi-pill { display:inline-flex; align-items:center; gap:0.375rem; border-radius:9999px; padding:0.375rem 0.75rem; font-size:0.75rem; font-weight:600; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer; transition:all 0.15s; }
  .bi-pill:focus { outline:none; }
  .bi-pill-count { border-radius:9999px; padding:0.125rem 0.375rem; font-size:0.75rem; font-weight:700; background:#f3f4f6; }
  .bi-table { width:100%; font-size:0.875rem; border-collapse:collapse; }
  .bi-table th { padding:0.625rem 1rem; text-align:left; font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; border-bottom:1px solid #f3f4f6; }
  .bi-table td { padding:0.625rem 1rem; border-bottom:1px solid #f9fafb; }
  .bi-table tr:hover td { background:#f9fafb; }
  .bi-flag { display:inline-flex; padding:0.125rem 0.375rem; border-radius:0.25rem; font-size:0.75rem; font-weight:500; margin:0.125rem; }
  .bi-flag--red { background:#fee2e2; color:#b91c1c; }
  .bi-flag--orange { background:#ffedd5; color:#9a3412; }
  .bi-flag--yellow { background:#fef3c7; color:#92400e; }
  .bi-flag--gray { background:#f3f4f6; color:#4b5563; }
  .bi-empty { display:flex; align-items:center; justify-content:center; padding:3rem 0; color:#9ca3af; font-size:0.875rem; }
  .bi-footer { padding:0.5rem 1.25rem; font-size:0.75rem; color:#9ca3af; border-top:1px solid #f3f4f6; }
  .copy-sku { cursor:pointer; color:#111827; }
  @@media(min-width:1280px){ .bi-margin-kpi { grid-template-columns:repeat(4, minmax(0, 1fr)) !important; } }
  </style>
  <script>function copySku(el,sku){event.stopPropagation();navigator.clipboard.writeText(sku);new FilamentNotification().title('Copiat!').success().duration(2000).send()}</script>

  @php
    $deltaPositive = $this->stockDelta >= 0;
    $deltaColor = $deltaPositive ? 'color:#16a34a;' : 'color:#dc2626;';
    $deltaSign = $deltaPositive ? '+' : '';
    $kpiDataMissing = $this->kpiDay === '—';
  @endphp

  {{-- KPI Cards --}}
  <div class="bi-kpi">
    <div class="bi-kpi-card bi-kpi-card--wide">
      <div class="bi-kpi-label">Valoare stoc</div>
      @if($kpiDataMissing)
        <div class="bi-kpi-value" style="color:#d1d5db;">—</div>
      @else
        <div class="bi-kpi-value">{{ number_format($this->stockValue, 0, ',', '.') }} <span style="font-size:0.875rem; font-weight:400; color:#9ca3af;">RON</span></div>
        <div class="bi-kpi-delta">
          <span style="font-size:0.875rem; font-weight:500; {{ $deltaColor }}">{{ $deltaSign }}{{ number_format($this->stockDelta, 0, ',', '.') }} RON</span>
          <span style="font-size:0.75rem; color:#9ca3af;">({{ $deltaSign }}{{ number_format($this->stockDeltaPct, 2, ',', '.') }}% față de ieri)</span>
        </div>
        <div class="bi-kpi-sub">Data: {{ $this->kpiDay }}</div>
      @endif
    </div>
    <div class="bi-kpi-card">
      <div class="bi-kpi-label">Produse în stoc</div>
      <div class="bi-kpi-value">
        {{ number_format($this->inStock, 0, '.', '') }}
        @if(isset($this->kpiDeltas['in_stock']) && $this->kpiDeltas['in_stock'] != 0)
          @php $d = $this->kpiDeltas['in_stock']; @endphp
          <span style="font-size:0.75rem; font-weight:500; {{ $d > 0 ? 'color:#16a34a;' : 'color:#dc2626;' }}">{{ $d > 0 ? "\u{2191}" : "\u{2193}" }} {{ $d > 0 ? '+' : '' }}{{ $d }}</span>
        @endif
      </div>
      <div class="bi-kpi-sub">
        {{ number_format($this->outOfStock, 0, '.', '') }} fără stoc
        @if(isset($this->kpiDeltas['out_of_stock']) && $this->kpiDeltas['out_of_stock'] != 0)
          @php $d = $this->kpiDeltas['out_of_stock']; @endphp
          <span style="{{ $d > 0 ? 'color:#dc2626;' : 'color:#16a34a;' }}">({{ $d > 0 ? '+' : '' }}{{ $d }})</span>
        @endif
      </div>
    </div>
    <div class="bi-kpi-card" style="border-color:#fecaca; background:#fef2f2;">
      <div class="bi-kpi-label" style="color:#ef4444;">Critice (P0)</div>
      <div class="bi-kpi-value" style="color:#b91c1c;">
        {{ number_format($this->countP0, 0, '.', '') }}
        @if(isset($this->kpiDeltas['countP0']) && $this->kpiDeltas['countP0'] != 0)
          @php $d = $this->kpiDeltas['countP0']; @endphp
          <span style="font-size:0.75rem; font-weight:500; {{ $d > 0 ? 'color:#dc2626;' : 'color:#16a34a;' }}">{{ $d > 0 ? "\u{2191}" : "\u{2193}" }} {{ $d > 0 ? '+' : '' }}{{ $d }}</span>
        @endif
      </div>
      <div class="bi-kpi-sub" style="color:#ef4444;">stoc 0 sau &lt; 7 zile</div>
    </div>
    <div class="bi-kpi-card" style="border-color:#fed7aa; background:#fff7ed;">
      <div class="bi-kpi-label" style="color:#f97316;">Moderate (P1)</div>
      <div class="bi-kpi-value" style="color:#c2410c;">
        {{ number_format($this->countP1, 0, '.', '') }}
        @if(isset($this->kpiDeltas['countP1']) && $this->kpiDeltas['countP1'] != 0)
          @php $d = $this->kpiDeltas['countP1']; @endphp
          <span style="font-size:0.75rem; font-weight:500; {{ $d > 0 ? 'color:#dc2626;' : 'color:#16a34a;' }}">{{ $d > 0 ? "\u{2191}" : "\u{2193}" }} {{ $d > 0 ? '+' : '' }}{{ $d }}</span>
        @endif
      </div>
      <div class="bi-kpi-sub" style="color:#f97316;">7–14 zile rămase</div>
    </div>
    <div class="bi-kpi-card" style="border-color:#fde68a; background:#fefce8;">
      <div class="bi-kpi-label" style="color:#ca8a04;">Capital Blocat — Dead Stock (P2)</div>
      <div class="bi-kpi-value" style="color:#a16207;">
        {{ number_format($this->countP2, 0, '.', '') }}
        @if(isset($this->kpiDeltas['countP2']) && $this->kpiDeltas['countP2'] != 0)
          @php $d = $this->kpiDeltas['countP2']; @endphp
          <span style="font-size:0.75rem; font-weight:500; {{ $d > 0 ? 'color:#dc2626;' : 'color:#16a34a;' }}">{{ $d > 0 ? "\u{2191}" : "\u{2193}" }} {{ $d > 0 ? '+' : '' }}{{ $d }}</span>
        @endif
      </div>
      <div class="bi-kpi-sub" style="color:#ca8a04;">capital blocat ≥ 300 RON</div>
    </div>
  </div>

  {{-- Margin KPI Cards --}}
  <div class="bi-margin-kpi" style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1rem; margin-top:1rem;">
    <div class="bi-kpi-card" style="border-color:#d1fae5; background:#ecfdf5;">
      <div class="bi-kpi-label" style="color:#059669;">Valoare stoc la cost</div>
      @if($kpiDataMissing)
        <div class="bi-kpi-value" style="color:#d1d5db;">—</div>
      @else
        <div class="bi-kpi-value" style="color:#065f46;">{{ number_format($this->stockValueCost, 0, ',', '.') }} <span style="font-size:0.875rem; font-weight:400; color:#6ee7b7;">RON</span></div>
      @endif
    </div>
    <div class="bi-kpi-card" style="border-color:#d1fae5; background:#ecfdf5;">
      <div class="bi-kpi-label" style="color:#059669;">Marjă brută totală</div>
      @if($kpiDataMissing)
        <div class="bi-kpi-value" style="color:#d1d5db;">—</div>
      @else
        <div class="bi-kpi-value" style="color:#065f46;">{{ number_format($this->grossMarginTotal, 0, ',', '.') }} <span style="font-size:0.875rem; font-weight:400; color:#6ee7b7;">RON</span></div>
      @endif
    </div>
    <div class="bi-kpi-card" style="border-color:#fde68a; background:#fefce8;">
      <div class="bi-kpi-label" style="color:#ca8a04;">Marjă brută %</div>
      @if($kpiDataMissing)
        <div class="bi-kpi-value" style="color:#d1d5db;">—</div>
      @else
        <div class="bi-kpi-value" style="{{ $this->grossMarginPct >= 20 ? 'color:#16a34a;' : ($this->grossMarginPct >= 5 ? 'color:#ca8a04;' : 'color:#dc2626;') }}">{{ number_format($this->grossMarginPct, 2, ',', '.') }}%</div>
      @endif
    </div>
    <div class="bi-kpi-card">
      <div class="bi-kpi-label">Produse cu date cost</div>
      @if($kpiDataMissing)
        <div class="bi-kpi-value" style="color:#d1d5db;">—</div>
      @else
        <div class="bi-kpi-value">{{ number_format($this->productsWithCostData, 0, '.', '') }}</div>
        <div class="bi-kpi-sub">produse cu preț achiziție</div>
      @endif
    </div>
  </div>

  {{-- Stoc & mișcări per furnizor --}}
  @if(count($this->supplierRows) > 0)
  @php
    $totRetail    = array_sum(array_column($this->supplierRows, 'value_retail'));
    $totCost      = array_sum(array_column($this->supplierRows, 'value_cost'));
    $totMargin    = array_sum(array_column($this->supplierRows, 'margin_total'));
    $totOut       = array_sum(array_column($this->supplierRows, 'out_value'));
    $totIn        = array_sum(array_column($this->supplierRows, 'in_value'));
    $totMarginPct = $totRetail > 0 ? round($totMargin / $totRetail * 100, 1) : 0;
  @endphp
  <div class="bi-card" style="margin-top:1rem;"
       x-data="{
         rows: {{ Js::from($this->supplierRows) }},
         search: '',
         page: 1,
         perPage: 20,
         gmroi(s) {
           // GMROI anualizat = marjă% × (ieșiri_30z / stoc_cost × 12)
           // Standard retail: pentru 1 RON imobilizat, câți RON marjă generezi/an
           if (s.value_cost <= 0) return 0;
           const rot_annual = (s.out_value / s.value_cost) * 12;
           return (s.margin_pct / 100) * rot_annual;
         },
         score(s) { return this.gmroi(s); },
         badge(s) {
           const g = this.gmroi(s);
           if (g >= 2.5) return { label:'Excelent', title:'GMROI ≥ 2.5 — stoc foarte eficient',    style:'background:#dcfce7;color:#15803d;border:1px solid #86efac;' };
           if (g >= 1.5) return { label:'Bun',      title:'GMROI ≥ 1.5 — performanță solidă',      style:'background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;' };
           if (g >= 0.8) return { label:'Mediu',    title:'GMROI ≥ 0.8 — acceptabil, de urmărit',  style:'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;' };
           return                { label:'Slab',     title:'GMROI < 0.8 — capital blocat / marjă mică', style:'background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;' };
         },
         sortCol: 'score',
         sortDir: 'desc',
         sortBy(col) {
           if (this.sortCol === col) { this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'; }
           else { this.sortCol = col; this.sortDir = col === 'supplier_name' ? 'asc' : 'desc'; }
           this.page = 1;
         },
         sortIcon(col) {
           if (this.sortCol !== col) return ' ⇅';
           return this.sortDir === 'asc' ? ' ↑' : ' ↓';
         },
         thStyle(col) {
           return this.sortCol === col
             ? 'cursor:pointer;user-select:none;color:#6366f1;'
             : 'cursor:pointer;user-select:none;color:#6b7280;';
         },
         get filtered() {
           const q = this.search.toLowerCase().trim();
           const list = q ? this.rows.filter(r => r.supplier_name.toLowerCase().includes(q)) : this.rows;
           const col = this.sortCol; const dir = this.sortDir;
           return [...list].sort((a, b) => {
             let av = col === 'score' ? this.score(a) : (a[col] ?? 0);
             let bv = col === 'score' ? this.score(b) : (b[col] ?? 0);
             if (col === 'supplier_name') { av = (a.supplier_name||'').toLowerCase(); bv = (b.supplier_name||'').toLowerCase(); }
             if (av < bv) return dir === 'asc' ? -1 : 1;
             if (av > bv) return dir === 'asc' ? 1 : -1;
             return 0;
           });
         },
         get totalPages() { return Math.max(1, Math.ceil(this.filtered.length / this.perPage)); },
         get paginated() {
           const start = (this.page - 1) * this.perPage;
           return this.filtered.slice(start, start + this.perPage);
         },
         fmt(n) { if (!n) return '—'; return new Intl.NumberFormat('ro-RO', {maximumFractionDigits:0}).format(n) + ' RON'; },
         fmtPct(n) { return new Intl.NumberFormat('ro-RO', {minimumFractionDigits:1, maximumFractionDigits:1}).format(n) + '%'; },
         marginColor(pct) { return pct >= 25 ? 'color:#16a34a;font-weight:700;' : (pct >= 15 ? 'color:#ca8a04;font-weight:600;' : 'color:#dc2626;font-weight:700;'); },
         prevPage() { if (this.page > 1) this.page--; },
         nextPage() { if (this.page < this.totalPages) this.page++; },
       }"
       x-effect="search && (page = 1)">
    <div class="bi-card-header">
      <div>
        <div class="bi-card-title">Stoc & mișcări pe furnizori</div>
        <div class="bi-card-subtitle">GMROI = marjă% × rotație anuală &mdash; câți RON marjă generezi per RON imobilizat în stoc/an &mdash; mișcări 30 zile (fără TVA)</div>
      </div>
      <div>
        <input x-model="search" type="search" placeholder="Caută furnizor…"
               style="border:1px solid #d1d5db; border-radius:0.5rem; padding:0.375rem 0.75rem; font-size:0.875rem; outline:none; width:220px;"
               onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#d1d5db'">
      </div>
    </div>

    <div style="overflow-x:auto;">
      <table class="bi-table">
        <thead><tr>
          <th @click="sortBy('supplier_name')" :style="thStyle('supplier_name')" x-text="'Furnizor' + sortIcon('supplier_name')"></th>
          <th @click="sortBy('score')"         :style="'text-align:center;' + thStyle('score')"         x-text="'Perf.' + sortIcon('score')"></th>
          <th @click="sortBy('product_count')" :style="'text-align:right;' + thStyle('product_count')"  x-text="'Prod.' + sortIcon('product_count')"></th>
          <th @click="sortBy('value_retail')"  :style="'text-align:right;' + thStyle('value_retail')"   x-text="'Val. retail' + sortIcon('value_retail')"></th>
          <th @click="sortBy('value_cost')"    :style="'text-align:right;' + thStyle('value_cost')"     x-text="'Val. cost' + sortIcon('value_cost')"></th>
          <th @click="sortBy('margin_pct')"    :style="'text-align:right;' + thStyle('margin_pct')"     x-text="'Marjă %' + sortIcon('margin_pct')"></th>
          <th @click="sortBy('margin_total')"  :style="'text-align:right;' + thStyle('margin_total')"   x-text="'Marjă RON' + sortIcon('margin_total')"></th>
          <th @click="sortBy('out_value')"     :style="'text-align:right;color:#dc2626;' + thStyle('out_value')"  x-text="'↓ Ieșiri 30z' + sortIcon('out_value')"></th>
          <th @click="sortBy('in_value')"      :style="'text-align:right;color:#16a34a;' + thStyle('in_value')"   x-text="'↑ Intrări 30z' + sortIcon('in_value')"></th>
        </tr></thead>
        <tbody>
          <template x-for="(s, i) in paginated" :key="s.supplier_id">
            <tr>
              <td style="font-weight:500; color:#1f2937; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" x-text="s.supplier_name"></td>
              <td style="text-align:center;">
                <span :style="badge(s).style" :title="badge(s).title"
                      style="display:inline-block; padding:0.125rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:700; white-space:nowrap; cursor:help;">
                  <span x-text="badge(s).label"></span>
                  <span style="opacity:0.7; margin-left:0.2rem;" x-text="'(' + gmroi(s).toFixed(2) + ')'"></span>
                </span>
              </td>
              <td style="text-align:right; color:#6b7280;" x-text="s.product_count"></td>
              <td style="text-align:right; color:#374151;" x-text="fmt(s.value_retail)"></td>
              <td style="text-align:right; font-weight:600; color:#111827;" x-text="fmt(s.value_cost)"></td>
              <td style="text-align:right;" :style="marginColor(s.margin_pct)" x-text="fmtPct(s.margin_pct)"></td>
              <td style="text-align:right; color:#059669;" x-text="fmt(s.margin_total)"></td>
              <td style="text-align:right;" :style="s.out_value > 0 ? 'color:#dc2626;font-weight:600;' : 'color:#9ca3af;'" x-text="fmt(s.out_value)"></td>
              <td style="text-align:right;" :style="s.in_value > 0 ? 'color:#16a34a;font-weight:600;' : 'color:#9ca3af;'" x-text="fmt(s.in_value)"></td>
            </tr>
          </template>
          <tr x-show="filtered.length === 0">
            <td colspan="9" style="text-align:center; padding:2rem; color:#9ca3af;">Niciun furnizor găsit.</td>
          </tr>
        </tbody>
        <tfoot>
          <tr style="border-top:2px solid #e5e7eb; background:#f9fafb;">
            <td style="font-weight:700; color:#111827; padding:0.625rem 1rem;">TOTAL</td>
            <td></td>
            <td></td>
            <td style="text-align:right; font-weight:700; color:#111827; padding:0.625rem 1rem;">{{ number_format($totRetail, 0, ',', '.') }} RON</td>
            <td style="text-align:right; font-weight:700; color:#111827; padding:0.625rem 1rem;">{{ number_format($totCost, 0, ',', '.') }} RON</td>
            <td style="text-align:right; font-weight:700; padding:0.625rem 1rem; {{ $totMarginPct >= 25 ? 'color:#16a34a;' : ($totMarginPct >= 15 ? 'color:#ca8a04;' : 'color:#dc2626;') }}">{{ number_format($totMarginPct, 1, ',', '.') }}%</td>
            <td style="text-align:right; font-weight:700; color:#059669; padding:0.625rem 1rem;">{{ number_format($totMargin, 0, ',', '.') }} RON</td>
            <td style="text-align:right; font-weight:700; color:#dc2626; padding:0.625rem 1rem;">{{ $totOut > 0 ? number_format($totOut, 0, ',', '.') . ' RON' : '—' }}</td>
            <td style="text-align:right; font-weight:700; color:#16a34a; padding:0.625rem 1rem;">{{ $totIn > 0 ? number_format($totIn, 0, ',', '.') . ' RON' : '—' }}</td>
          </tr>
        </tfoot>
      </table>
    </div>

    {{-- Paginare --}}
    <div style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem 1.25rem; border-top:1px solid #f3f4f6; flex-wrap:wrap; gap:0.75rem;">
      <div style="font-size:0.875rem; color:#6b7280;">
        <span x-text="filtered.length"></span> furnizori
        <template x-if="filtered.length !== rows.length"><span> (filtrat din <span x-text="rows.length"></span>)</span></template>
        · mișcări {{ now()->subDays(30)->toDateString() }} – {{ now()->toDateString() }}
      </div>
      <div x-show="totalPages > 1" style="display:flex; align-items:center; gap:0.75rem;">
        <button @click="prevPage()" :disabled="page === 1"
                style="display:inline-flex; align-items:center; justify-content:center; width:2.25rem; height:2.25rem; border-radius:0.5rem; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-size:1.25rem; color:#374151; line-height:1;"
                :style="page === 1 ? 'opacity:0.35; cursor:not-allowed;' : ''">‹</button>
        <span style="font-size:0.9375rem; color:#111827; font-weight:500; font-variant-numeric:tabular-nums;">
          pagina <strong x-text="page"></strong> <span style="color:#9ca3af;">/ <span x-text="totalPages"></span></span>
        </span>
        <button @click="nextPage()" :disabled="page === totalPages"
                style="display:inline-flex; align-items:center; justify-content:center; width:2.25rem; height:2.25rem; border-radius:0.5rem; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-size:1.25rem; color:#374151; line-height:1;"
                :style="page === totalPages ? 'opacity:0.35; cursor:not-allowed;' : ''">›</button>
      </div>
    </div>
  </div>
  @endif

  {{-- Charts --}}
  @livewire(\App\Filament\App\Widgets\BiStockTrendChartWidget::class)
  @livewire(\App\Filament\App\Widgets\BiMarginTrendChartWidget::class)

  {{-- Profitabilitate produse --}}
  <div class="bi-card">
    <div class="bi-card-header">
      <div>
        <div class="bi-card-title">Profitabilitate produse</div>
        <div class="bi-card-subtitle">Marjă pe produs bazată pe preț vânzare vs. preț achiziție</div>
      </div>
      <div class="bi-pills">
        <button wire:click="setMarginTab('top_margin')" class="bi-pill" style="{{ $this->marginTab === 'top_margin' ? 'background:#16a34a;color:#fff;border-color:#16a34a;' : '' }}">Top marjă</button>
        <button wire:click="setMarginTab('negative_margin')" class="bi-pill" style="{{ $this->marginTab === 'negative_margin' ? 'background:#dc2626;color:#fff;border-color:#dc2626;' : '' }}">Marjă negativă/mică</button>
      </div>
    </div>
    @if(count($this->marginRows) === 0)
      <div class="bi-empty">Nicio dată de profitabilitate disponibilă.</div>
    @else
      <div style="overflow-x:auto;">
        <table class="bi-table">
          <thead><tr>
            <th>SKU</th>
            <th>Produs</th>
            <th style="text-align:right;">Preț vânzare</th>
            <th style="text-align:right;">Preț achiziție</th>
            <th style="text-align:right;">Marjă %</th>
            <th style="text-align:right;">Stoc</th>
            <th style="text-align:right;">Marjă totală stoc</th>
            <th>Furnizor</th>
          </tr></thead>
          <tbody>
            @foreach($this->marginRows as $mIdx => $row)
              @php
                $marginColor = $row['margin_pct'] > 20 ? 'color:#16a34a;font-weight:700;' : ($row['margin_pct'] >= 5 ? 'color:#ca8a04;font-weight:600;' : 'color:#dc2626;font-weight:700;');
                $stockQty = (float)$row['stock_qty'];
                $stockFmt = floor($stockQty) == $stockQty ? number_format($stockQty, 0, '.', '') : number_format($stockQty, 2, '.', '');
              @endphp
              <tr wire:key="margin-{{ $mIdx }}" @if($row['product_id'] ?? null) onclick="window.location='{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $row['product_id']]) }}'" style="cursor:pointer;" @endif>
                <td class="copy-sku" style="font-family:monospace; font-size:0.875rem;" onclick="copySku(this,'{{ $row['sku'] }}')">{{ $row['sku'] }}</td>
                <td style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#1f2937;">{{ $row['product_name'] }}</td>
                <td style="text-align:right; color:#374151;">{{ number_format($row['sale_price'], 2, ',', '.') }} RON</td>
                <td style="text-align:right; color:#6b7280;">{{ number_format($row['purchase_price'], 2, ',', '.') }} RON</td>
                <td style="text-align:right; {{ $marginColor }}">{{ number_format($row['margin_pct'], 1, ',', '.') }}%</td>
                <td style="text-align:right; color:#374151;">{{ $stockFmt }} buc</td>
                <td style="text-align:right; font-weight:500; color:#111827;">{{ number_format($row['stock_margin_total'], 2, ',', '.') }} RON</td>
                <td style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#6b7280; font-size:0.8rem;">{{ $row['supplier_name'] ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="bi-footer">Afișate primele 50 de produse · calculat pentru {{ $this->marginRows[0]['calculated_for_day'] ?? '—' }}</div>
    @endif
  </div>

  {{-- Velocity --}}
  <div class="bi-card">
    <div class="bi-card-header">
      <div>
        <div class="bi-card-title">Velocity produse</div>
        <div class="bi-card-subtitle">Ritm de ieșire din stoc bazat pe ultimele 30/90 zile</div>
      </div>
      <div class="bi-pills">
        <button wire:click="setVelocityTab('fast')" class="bi-pill" style="{{ $this->velocityTab === 'fast' ? 'background:#2563eb;color:#fff;border-color:#2563eb;' : '' }}">↑ Rapid mișcătoare</button>
        <button wire:click="setVelocityTab('slow')" class="bi-pill" style="{{ $this->velocityTab === 'slow' ? 'background:#4b5563;color:#fff;border-color:#4b5563;' : '' }}">↓ Fără mișcare ≥30 zile</button>
      </div>
    </div>
    @if(count($this->velocityRows) === 0)
      <div class="bi-empty">Nicio dată velocity.</div>
    @else
      <div style="overflow-x:auto;">
        <table class="bi-table">
          <thead><tr>
            <th>SKU</th><th>Produs</th>
            @if($this->velocityTab === 'fast')
              <th style="text-align:right;">Avg/zi 7d</th><th style="text-align:right;">Avg/zi 30d</th><th style="text-align:right;">Avg/zi 90d</th><th style="text-align:right;">Total ieșit 30d</th>
            @else
              <th style="text-align:right;">Ultima mișcare</th><th style="text-align:right;">Zile fără mișcare</th><th style="text-align:right;">Total ieșit 30d</th><th style="text-align:right;">Total ieșit 90d</th>
            @endif
          </tr></thead>
          <tbody>
            @foreach($this->velocityRows as $vIdx => $row)
              @php $daysNoMove = $row['days_since_last_movement']; @endphp
              <tr wire:key="vel-{{ $vIdx }}" @if($row['product_id'] ?? null) onclick="window.location='{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $row['product_id']]) }}'" style="cursor:pointer;" @endif>
                <td class="copy-sku" style="font-family:monospace; font-size:0.875rem;" onclick="copySku(this,'{{ $row['sku'] }}')">{{ $row['sku'] }}</td>
                <td style="max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#1f2937;">{{ $row['product_name'] ?? '—' }}</td>
                @if($this->velocityTab === 'fast')
                  <td style="text-align:right; color:#4b5563;">{{ (float)$row['avg_out_qty_7d'] > 0 ? number_format($row['avg_out_qty_7d'], 1, '.', '') : '—' }}</td>
                  <td style="text-align:right; color:#2563eb; font-weight:600;">{{ (float)$row['avg_out_qty_30d'] > 0 ? number_format($row['avg_out_qty_30d'], 1, '.', '') : '—' }}</td>
                  <td style="text-align:right; color:#6b7280;">{{ (float)$row['avg_out_qty_90d'] > 0 ? number_format($row['avg_out_qty_90d'], 1, '.', '') : '—' }}</td>
                  <td style="text-align:right; color:#374151;">{{ (float)$row['out_qty_30d'] > 0 ? number_format($row['out_qty_30d'], 0, '.', '') : '0' }}</td>
                @else
                  <td style="text-align:right; color:#6b7280;">{{ $row['last_movement_day'] ?? 'niciodată' }}</td>
                  <td style="text-align:right; {{ $daysNoMove !== null && $daysNoMove >= 90 ? 'color:#dc2626;font-weight:700;' : ($daysNoMove !== null && $daysNoMove >= 60 ? 'color:#ea580c;font-weight:600;' : 'color:#374151;') }}">{{ $daysNoMove !== null ? number_format($daysNoMove, 0, '.', '').' zile' : '—' }}</td>
                  <td style="text-align:right; color:#4b5563;">{{ (float)$row['out_qty_30d'] > 0 ? number_format($row['out_qty_30d'], 0, '.', '') : '0' }}</td>
                  <td style="text-align:right; color:#6b7280;">{{ (float)$row['out_qty_90d'] > 0 ? number_format($row['out_qty_90d'], 0, '.', '') : '0' }}</td>
                @endif
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="bi-footer">Afișate primele 50 de produse · calculat pentru {{ $this->velocityRows[0]['calculated_for_day'] ?? '—' }}</div>
    @endif
  </div>

  {{-- Alerts --}}
  <div class="bi-card">
    <div class="bi-card-header">
      <div>
        <div class="bi-card-title">Candidați la alertare</div>
        <div class="bi-card-subtitle">Ziua: {{ $this->alertDay }}</div>
      </div>
      <div class="bi-pills">
        <button wire:click="setTab('P0')" class="bi-pill" style="{{ $this->tab === 'P0' ? 'background:#dc2626;color:#fff;border-color:#dc2626;' : '' }}">Critice (P0) <span class="bi-pill-count" style="{{ $this->tab === 'P0' ? 'background:rgba(255,255,255,0.3);color:#fff;' : '' }}">{{ $this->countP0 }}</span></button>
        <button wire:click="setTab('P1')" class="bi-pill" style="{{ $this->tab === 'P1' ? 'background:#f97316;color:#fff;border-color:#f97316;' : '' }}">Moderate (P1) <span class="bi-pill-count" style="{{ $this->tab === 'P1' ? 'background:rgba(255,255,255,0.3);color:#fff;' : '' }}">{{ $this->countP1 }}</span></button>
        <button wire:click="setTab('P2')" class="bi-pill" style="{{ $this->tab === 'P2' ? 'background:#eab308;color:#fff;border-color:#eab308;' : '' }}">Capital Blocat (P2) <span class="bi-pill-count" style="{{ $this->tab === 'P2' ? 'background:rgba(255,255,255,0.3);color:#fff;' : '' }}">{{ $this->countP2 }}</span></button>
      </div>
    </div>
    @if(count($this->alertRows) === 0)
      <div class="bi-empty">Niciun candidat {{ $this->tab }} pentru {{ $this->alertDay }}.</div>
    @else
      @php
        $flagStyles = [
          'out_of_stock'=>['Epuizat','bi-flag--red'],'critical_stock'=>['< 7 zile','bi-flag--red'],
          'low_stock'=>['7–14 zile','bi-flag--orange'],'price_spike'=>['Spike preț','bi-flag--yellow'],
          'dead_stock'=>['Dead stock','bi-flag--gray'],'no_consumption_30d'=>['Fără mișcare','bi-flag--gray'],
        ];
      @endphp
      <div style="overflow-x:auto;">
        <table class="bi-table">
          <thead><tr>
            <th>SKU</th><th>Produs</th><th style="text-align:right;">Stoc</th><th style="text-align:right;">Preț</th><th style="text-align:right;">Val. stoc</th>
            @if($this->tab !== 'P2')<th style="text-align:right;">Consum/zi</th><th style="text-align:right;">Zile rămase</th>@else<th style="text-align:right;">Val. blocată</th>@endif
            <th>Motive</th>
          </tr></thead>
          <tbody>
            @foreach($this->alertRows as $aIdx => $row)
              @php $daysLeft = $row['days_left']; @endphp
              <tr wire:key="alert-{{ $aIdx }}" @if($row['product_id'] ?? null) onclick="window.location='{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $row['product_id']]) }}'" style="cursor:pointer;" @endif>
                <td class="copy-sku" style="font-family:monospace; font-size:0.875rem;" onclick="copySku(this,'{{ $row['sku'] }}')">{{ $row['sku'] }}</td>
                <td style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#1f2937;">{{ $row['name'] }}</td>
                <td style="text-align:right;">@if($row['closing_qty'] <= 0)<span style="color:#dc2626; font-weight:600;">0</span>@else{{ floor((float)$row['closing_qty']) == (float)$row['closing_qty'] ? number_format((float)$row['closing_qty'], 0, '.', '') : number_format((float)$row['closing_qty'], 2, '.', '') }}@endif</td>
                <td style="text-align:right; color:#4b5563;">{{ $row['closing_price'] !== null ? number_format($row['closing_price'], 2, ',', '.') . ' RON' : '—' }}</td>
                <td style="text-align:right; font-weight:500; color:#111827;">{{ number_format($row['stock_value'], 0, ',', '.') }} RON</td>
                @if($this->tab !== 'P2')
                  <td style="text-align:right; color:#6b7280;">{{ $row['avg_out_30d'] > 0 ? number_format($row['avg_out_30d'], 2, '.', '') : '—' }}</td>
                  <td style="text-align:right; {{ $daysLeft !== null && $daysLeft <= 7 ? 'color:#dc2626;font-weight:700;' : ($daysLeft !== null && $daysLeft <= 14 ? 'color:#ea580c;font-weight:600;' : 'color:#374151;') }}">{{ $daysLeft !== null ? number_format($daysLeft, 0, '.', '') : '∞' }}</td>
                @else
                  <td style="text-align:right; font-weight:600; color:#a16207;">{{ number_format($row['stock_value'], 0, ',', '.') }} RON</td>
                @endif
                <td><div style="display:flex; flex-wrap:wrap; gap:0.25rem;">
                  @foreach($row['reason_flags'] as $flag)
                    @if(isset($flagStyles[$flag]))<span class="bi-flag {{ $flagStyles[$flag][1] }}">{{ $flagStyles[$flag][0] }}</span>@endif
                  @endforeach
                </div></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @if(count($this->alertRows) >= 200)
        <div class="bi-footer">Afișate primele 200 de produse.</div>
      @endif
    @endif
  </div>

</x-filament-panels::page>
