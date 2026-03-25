<x-filament-panels::page>

  <style>
  .repl-kpi { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1rem; }
  @@media(min-width:640px){ .repl-kpi { grid-template-columns:repeat(4, minmax(0, 1fr)); } }
  .repl-kpi-card { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; padding:1rem 1.25rem; }
  .repl-kpi-label { font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#9ca3af; }
  .repl-kpi-value { margin-top:0.25rem; font-size:1.5rem; font-weight:700; color:#111827; }
  .repl-kpi-sub { margin-top:0.25rem; font-size:0.75rem; color:#9ca3af; }
  .repl-card { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; overflow:hidden; }
  .repl-card-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.75rem; padding:0.75rem 1.25rem; border-bottom:1px solid #f3f4f6; background:#f9fafb; }
  .repl-card-title { font-size:0.875rem; font-weight:600; color:#1f2937; }
  .repl-card-subtitle { font-size:0.75rem; color:#9ca3af; margin-top:0.125rem; }
  .repl-pills { display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; }
  .repl-pill { display:inline-flex; align-items:center; gap:0.375rem; border-radius:9999px; padding:0.375rem 0.75rem; font-size:0.75rem; font-weight:600; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer; transition:all 0.15s; }
  .repl-pill:focus { outline:none; }
  .repl-pill-count { border-radius:9999px; padding:0.125rem 0.375rem; font-size:0.75rem; font-weight:700; background:#f3f4f6; }
  .repl-table { width:100%; font-size:0.875rem; border-collapse:collapse; }
  .repl-table th { padding:0.625rem 1rem; text-align:left; font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; border-bottom:1px solid #f3f4f6; white-space:nowrap; }
  .repl-table td { padding:0.625rem 1rem; border-bottom:1px solid #f9fafb; }
  .repl-table tr:hover td { background:#f9fafb; }
  .repl-badge { display:inline-flex; padding:0.125rem 0.5rem; border-radius:9999px; font-size:0.75rem; font-weight:600; }
  .repl-badge--urgent { background:#fee2e2; color:#b91c1c; }
  .repl-badge--soon { background:#ffedd5; color:#9a3412; }
  .repl-badge--planned { background:#dbeafe; color:#1e40af; }
  .repl-abc { display:inline-flex; align-items:center; justify-content:center; width:1.5rem; height:1.5rem; border-radius:0.25rem; font-size:0.75rem; font-weight:700; }
  .repl-abc--A { background:#dcfce7; color:#15803d; }
  .repl-abc--B { background:#fef3c7; color:#92400e; }
  .repl-abc--C { background:#f3f4f6; color:#6b7280; }
  .repl-empty { display:flex; align-items:center; justify-content:center; padding:3rem 0; color:#9ca3af; font-size:0.875rem; }
  .repl-footer { padding:0.5rem 1.25rem; font-size:0.75rem; color:#9ca3af; border-top:1px solid #f3f4f6; }
  .repl-copy-sku { cursor:pointer; color:#111827; }
  </style>
  <script>function replCopySku(el,sku){event.stopPropagation();navigator.clipboard.writeText(sku);new FilamentNotification().title('Copiat!').success().duration(2000).send()}</script>

  @php
    $dataMissing = $this->calcDay === '—';
    $totalCount = $this->countUrgent + $this->countSoon + $this->countPlanned;
  @endphp

  {{-- KPI Cards --}}
  <div class="repl-kpi">
    <div class="repl-kpi-card">
      <div class="repl-kpi-label">Total de comandat</div>
      @if($dataMissing)
        <div class="repl-kpi-value" style="color:#d1d5db;">—</div>
      @else
        <div class="repl-kpi-value">{{ number_format($this->totalQty, 0, ',', '.') }} <span style="font-size:0.875rem; font-weight:400; color:#9ca3af;">buc</span></div>
        <div class="repl-kpi-sub">{{ $totalCount }} produse</div>
      @endif
    </div>
    <div class="repl-kpi-card">
      <div class="repl-kpi-label">Cost estimat total</div>
      @if($dataMissing)
        <div class="repl-kpi-value" style="color:#d1d5db;">—</div>
      @else
        <div class="repl-kpi-value">{{ number_format($this->totalCost, 0, ',', '.') }} <span style="font-size:0.875rem; font-weight:400; color:#9ca3af;">RON</span></div>
        <div class="repl-kpi-sub">Data: {{ $this->calcDay }}</div>
      @endif
    </div>
    <div class="repl-kpi-card" style="border-color:#fecaca; background:#fef2f2;">
      <div class="repl-kpi-label" style="color:#ef4444;">Produse urgente</div>
      <div class="repl-kpi-value" style="color:#b91c1c;">{{ number_format($this->countUrgent, 0, '.', '') }}</div>
      <div class="repl-kpi-sub" style="color:#ef4444;">&lt; 7 zile stoc</div>
    </div>
    <div class="repl-kpi-card" style="border-color:#fed7aa; background:#fff7ed;">
      <div class="repl-kpi-label" style="color:#f97316;">Produse curând</div>
      <div class="repl-kpi-value" style="color:#c2410c;">{{ number_format($this->countSoon, 0, '.', '') }}</div>
      <div class="repl-kpi-sub" style="color:#f97316;">7–14 zile stoc</div>
    </div>
  </div>

  {{-- Table --}}
  <div class="repl-card">
    <div class="repl-card-header">
      <div>
        <div class="repl-card-title">Sugestii reaprovizionare</div>
        <div class="repl-card-subtitle">Calculat pentru: {{ $this->calcDay }}</div>
      </div>
      <div class="repl-pills">
        <button wire:click="setTab('urgent')" class="repl-pill" style="{{ $this->tab === 'urgent' ? 'background:#dc2626;color:#fff;border-color:#dc2626;' : '' }}">Urgent <span class="repl-pill-count" style="{{ $this->tab === 'urgent' ? 'background:rgba(255,255,255,0.3);color:#fff;' : '' }}">{{ $this->countUrgent }}</span></button>
        <button wire:click="setTab('soon')" class="repl-pill" style="{{ $this->tab === 'soon' ? 'background:#f97316;color:#fff;border-color:#f97316;' : '' }}">Curând <span class="repl-pill-count" style="{{ $this->tab === 'soon' ? 'background:rgba(255,255,255,0.3);color:#fff;' : '' }}">{{ $this->countSoon }}</span></button>
        <button wire:click="setTab('planned')" class="repl-pill" style="{{ $this->tab === 'planned' ? 'background:#2563eb;color:#fff;border-color:#2563eb;' : '' }}">Planificat <span class="repl-pill-count" style="{{ $this->tab === 'planned' ? 'background:rgba(255,255,255,0.3);color:#fff;' : '' }}">{{ $this->countPlanned }}</span></button>
        <button wire:click="setTab('all')" class="repl-pill" style="{{ $this->tab === 'all' ? 'background:#4b5563;color:#fff;border-color:#4b5563;' : '' }}">Toate <span class="repl-pill-count" style="{{ $this->tab === 'all' ? 'background:rgba(255,255,255,0.3);color:#fff;' : '' }}">{{ $totalCount }}</span></button>
      </div>
    </div>

    @if(count($this->rows) === 0)
      <div class="repl-empty">Nicio sugestie de reaprovizionare{{ $this->tab !== 'all' ? ' pentru categoria selectată' : '' }}.</div>
    @else
      <div style="overflow-x:auto;">
        <table class="repl-table">
          <thead><tr>
            <th>SKU</th>
            <th>Produs</th>
            <th style="text-align:right;">Stoc curent</th>
            <th style="text-align:right;">Zile stoc</th>
            <th style="text-align:right;">Consum/zi</th>
            <th style="text-align:right;">Punct reaprov.</th>
            <th style="text-align:right;">Cant. sugerată</th>
            <th style="text-align:right;">Cost estimat</th>
            <th style="text-align:right;">Marjă %</th>
            <th style="text-align:center;">ABC</th>
            @if($this->tab === 'all')<th style="text-align:center;">Prioritate</th>@endif
            <th>Furnizor</th>
          </tr></thead>
          <tbody>
            @foreach($this->rows as $rIdx => $row)
              @php
                $daysStock = $row['days_of_stock'];
                $daysColor = $daysStock < 3 ? 'color:#dc2626;font-weight:700;' : ($daysStock < 7 ? 'color:#ea580c;font-weight:600;' : 'color:#374151;');
                $marginColor = ($row['margin_pct'] !== null && $row['margin_pct'] < 15) ? 'color:#dc2626;' : 'color:#4b5563;';
              @endphp
              <tr wire:key="repl-{{ $rIdx }}" onclick="window.location='{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $row['woo_product_id']]) }}'" style="cursor:pointer;">
                <td class="repl-copy-sku" style="font-family:monospace; font-size:0.875rem;" onclick="replCopySku(this,'{{ $row['sku'] }}')">{{ $row['sku'] }}</td>
                <td style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#1f2937;">{{ $row['name'] }}</td>
                <td style="text-align:right; {{ $row['current_stock'] <= 0 ? 'color:#dc2626;font-weight:600;' : 'color:#374151;' }}">{{ $row['current_stock'] == intval($row['current_stock']) ? number_format($row['current_stock'], 0, '.', '') : number_format($row['current_stock'], 1, '.', '') }}</td>
                <td style="text-align:right; {{ $daysColor }}">{{ number_format($daysStock, 1, '.', '') }}</td>
                <td style="text-align:right; color:#6b7280;">{{ number_format($row['avg_daily_consumption'], 2, '.', '') }}</td>
                <td style="text-align:right; color:#6b7280;">{{ $row['reorder_point'] == intval($row['reorder_point']) ? number_format($row['reorder_point'], 0, '.', '') : number_format($row['reorder_point'], 1, '.', '') }}</td>
                <td style="text-align:right; font-weight:600; color:#1e40af;">{{ $row['suggested_qty'] == intval($row['suggested_qty']) ? number_format($row['suggested_qty'], 0, '.', '') : number_format($row['suggested_qty'], 1, '.', '') }}</td>
                <td style="text-align:right; font-weight:500; color:#111827;">{{ $row['estimated_cost'] > 0 ? number_format($row['estimated_cost'], 0, ',', '.') . ' RON' : '—' }}</td>
                <td style="text-align:right; {{ $marginColor }}">{{ $row['margin_pct'] !== null ? number_format($row['margin_pct'], 1, '.', '') . '%' : '—' }}</td>
                <td style="text-align:center;">
                  @if($row['abc_class'])
                    <span class="repl-abc repl-abc--{{ $row['abc_class'] }}">{{ $row['abc_class'] }}</span>
                  @else
                    <span style="color:#d1d5db;">—</span>
                  @endif
                </td>
                @if($this->tab === 'all')
                  <td style="text-align:center;">
                    <span class="repl-badge repl-badge--{{ $row['priority'] }}">
                      @if($row['priority'] === 'urgent') Urgent @elseif($row['priority'] === 'soon') Curând @else Planificat @endif
                    </span>
                  </td>
                @endif
                <td style="color:#6b7280; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $row['supplier_name'] ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @if(count($this->rows) >= 300)
        <div class="repl-footer">Afișate primele 300 de produse.</div>
      @endif
    @endif
  </div>

</x-filament-panels::page>
