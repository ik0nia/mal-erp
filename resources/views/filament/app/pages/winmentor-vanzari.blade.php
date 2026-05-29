<x-filament-panels::page>
<style>
.wv-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;}
.wv-stat{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;padding:1rem 1.25rem;display:flex;flex-direction:column;gap:.25rem;}
.wv-stat-label{font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.wv-stat-value{font-size:1.5rem;font-weight:700;color:#111827;font-variant-numeric:tabular-nums;}
.wv-stat-sub{font-size:.75rem;color:#9ca3af;}
.wv-stat--blue{border-left:4px solid #3b82f6;}
.wv-stat--amber{border-left:4px solid #f59e0b;}
.wv-stat--green{border-left:4px solid #10b981;}
.wv-stat--purple{border-left:4px solid #8b5cf6;}
.wm-filters{display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;padding:1rem 1.25rem;background:#f9fafb;border-bottom:1px solid #e5e7eb;}
.wm-filter-group{display:flex;flex-direction:column;gap:.25rem;}
.wm-filter-label{font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.wm-filter-input{border:1px solid #d1d5db;border-radius:.5rem;padding:.375rem .75rem;font-size:.875rem;color:#111827;background:#fff;outline:none;min-width:130px;}
.wm-filter-input:focus{border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15);}
.wm-search{flex:1;min-width:200px;}
.wm-card{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;overflow:hidden;margin-bottom:1.5rem;}
.wm-card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding:.75rem 1.25rem;border-bottom:1px solid #f3f4f6;background:#f9fafb;}
.wm-card-title{font-size:.875rem;font-weight:600;color:#1f2937;}
.wm-card-count{font-size:.75rem;color:#9ca3af;}
.wm-table{width:100%;border-collapse:collapse;font-size:.875rem;}
.wm-table th{padding:.625rem 1rem;text-align:left;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:1px solid #f3f4f6;white-space:nowrap;}
.wm-table td{padding:.625rem 1rem;border-bottom:1px solid #f9fafb;color:#374151;}
.wm-table tbody tr:hover td{background:#f9fafb;cursor:pointer;}
.wm-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.125rem .5rem;border-radius:9999px;font-size:.75rem;font-weight:600;}
.wm-badge--blue{background:#dbeafe;color:#1d4ed8;}
.wm-badge--amber{background:#fef3c7;color:#92400e;}
.wm-badge--green{background:#d1fae5;color:#065f46;}
.wm-badge--gray{background:#f3f4f6;color:#4b5563;}
.wm-total{font-weight:600;color:#111827;white-space:nowrap;font-variant-numeric:tabular-nums;}
.wm-negative{color:#b91c1c;}
.wm-empty{display:flex;align-items:center;justify-content:center;padding:3rem;color:#9ca3af;font-size:.875rem;}
.wm-pagination{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-top:1px solid #f3f4f6;font-size:.875rem;color:#6b7280;}
.wm-page-btn{padding:.375rem .75rem;border:1px solid #d1d5db;border-radius:.5rem;background:#fff;font-size:.8rem;color:#374151;cursor:pointer;}
.wm-page-btn:hover{background:#f9fafb;}
.wm-page-btn:disabled{opacity:.4;cursor:default;}
.wm-obs{font-size:.7rem;color:#9ca3af;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.wm-loc{font-size:.75rem;color:#6b7280;}
.wm-export-btn{padding:.375rem .75rem;border:1px solid #d1d5db;border-radius:.5rem;background:#fff;font-size:.8rem;color:#374151;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.25rem;}
.wm-export-btn:hover{background:#f9fafb;}
.wv-chart-wrap{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;padding:1rem 1.25rem;margin-bottom:1.5rem;}
.wv-chart-title{font-size:.875rem;font-weight:600;color:#1f2937;margin-bottom:.75rem;}
</style>

@php
  $isSuperAdmin = auth()->user()?->isSuperAdmin();
  $stats      = $isSuperAdmin ? $this->getStats() : [];
  $chart      = $isSuperAdmin ? $this->getDailyChart() : [];
  $rows       = $this->getRows();
  $total      = $this->getTotalCount();
  $totalPages = max(1, (int) ceil($total / $this->perPage));
  $from       = (($this->page - 1) * $this->perPage) + 1;
  $to         = min($this->page * $this->perPage, $total);
  $gestiuni   = $this->getGestiuni();
  $agenti     = $this->getAgenti();
@endphp

{{-- Stat Cards (super_admin only) --}}
@if($isSuperAdmin && !empty($stats))
<div class="wv-stats">
  <div class="wv-stat wv-stat--blue">
    <span class="wv-stat-label">Facturi</span>
    <span class="wv-stat-value">{{ number_format($stats['val_facturi'] ?? 0, 0, ',', '.') }} <small style="font-size:.6em;color:#6b7280">RON</small></span>
    <span class="wv-stat-sub">{{ $stats['nr_facturi'] ?? 0 }} documente</span>
  </div>
  <div class="wv-stat wv-stat--amber">
    <span class="wv-stat-label">Avize</span>
    <span class="wv-stat-value">{{ number_format($stats['val_avize'] ?? 0, 0, ',', '.') }} <small style="font-size:.6em;color:#6b7280">RON</small></span>
    <span class="wv-stat-sub">{{ $stats['nr_avize'] ?? 0 }} documente</span>
  </div>
  <div class="wv-stat wv-stat--green">
    <span class="wv-stat-label">Bonuri de casa</span>
    <span class="wv-stat-value">{{ number_format($stats['val_bonuri'] ?? 0, 0, ',', '.') }} <small style="font-size:.6em;color:#6b7280">RON</small></span>
    <span class="wv-stat-sub">{{ $stats['nr_bonuri'] ?? 0 }} documente</span>
  </div>
  <div class="wv-stat wv-stat--purple">
    <span class="wv-stat-label">Total luna</span>
    <span class="wv-stat-value">{{ number_format($stats['val_total'] ?? 0, 0, ',', '.') }} <small style="font-size:.6em;color:#6b7280">RON</small></span>
    <span class="wv-stat-sub">{{ ($stats['nr_facturi'] ?? 0) + ($stats['nr_avize'] ?? 0) + ($stats['nr_bonuri'] ?? 0) }} documente total</span>
  </div>
</div>

{{-- Grafic zilnic --}}
@if(!empty($chart['labels']))
<div class="wv-chart-wrap">
  <div class="wv-chart-title">Vanzari zilnice —
    @if($this->dateFrom === $this->dateTo)
      {{ \Carbon\Carbon::parse($this->dateFrom)->translatedFormat('d F Y') }}
    @else
      {{ \Carbon\Carbon::parse($this->dateFrom)->translatedFormat('d M') }} &ndash; {{ \Carbon\Carbon::parse($this->dateTo)->translatedFormat('d M Y') }}
    @endif
  </div>
  <canvas id="dailyChart" height="80"></canvas>
</div>
@endif
@endif

{{-- Navigare rapida --}}
<div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;align-items:center;">
  <div style="display:flex;gap:2px;">
    <button wire:click="goPrevMonth" class="wm-page-btn" title="Luna anterioara">&laquo;</button>
    <button wire:click="goPrevDay" class="wm-page-btn" title="Ziua anterioara">&lsaquo;</button>
  </div>
  <button wire:click="goToday" class="wm-page-btn" style="{{ $this->dateFrom === now()->format('Y-m-d') && $this->dateTo === now()->format('Y-m-d') ? 'background:#dbeafe;border-color:#3b82f6;color:#1d4ed8;' : '' }}">Azi</button>
  <button wire:click="goYesterday" class="wm-page-btn">Ieri</button>
  <button wire:click="goThisMonth" class="wm-page-btn" style="{{ $this->dateFrom === now()->startOfMonth()->format('Y-m-d') && $this->dateTo === now()->format('Y-m-d') ? 'background:#dbeafe;border-color:#3b82f6;color:#1d4ed8;' : '' }}">Luna curenta</button>
  <button wire:click="goLastMonth" class="wm-page-btn">Luna trecuta</button>
  <div style="display:flex;gap:2px;">
    <button wire:click="goNextDay" class="wm-page-btn" title="Ziua urmatoare">&rsaquo;</button>
    <button wire:click="goNextMonth" class="wm-page-btn" title="Luna urmatoare">&raquo;</button>
  </div>
  <span style="font-size:.875rem;font-weight:600;color:#374151;margin-left:.5rem;">
    @if($this->dateFrom === $this->dateTo)
      {{ \Carbon\Carbon::parse($this->dateFrom)->translatedFormat('d F Y') }}
    @else
      {{ \Carbon\Carbon::parse($this->dateFrom)->translatedFormat('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($this->dateTo)->translatedFormat('d M Y') }}
    @endif
  </span>
</div>

<div class="wm-card">
  {{-- Filters --}}
  <div class="wm-filters">
    <div class="wm-filter-group wm-search">
      <label class="wm-filter-label">Cauta (nr, client, CUI, produs, SKU, localitate)</label>
      <input type="text" class="wm-filter-input" placeholder="ex: 165190, Decor Door, ciment, Oradea" wire:model.live.debounce.400ms="search">
    </div>
    <div class="wm-filter-group">
      <label class="wm-filter-label">De la</label>
      <input type="date" class="wm-filter-input" wire:model.blur="dateFrom">
    </div>
    <div class="wm-filter-group">
      <label class="wm-filter-label">Pana la</label>
      <input type="date" class="wm-filter-input" wire:model.blur="dateTo">
    </div>
    <div class="wm-filter-group">
      <label class="wm-filter-label">Tip document</label>
      <select class="wm-filter-input" wire:model.live="tipDoc">
        <option value="">Toate tipurile</option>
        <option value="factura">Factura</option>
        <option value="aviz">Aviz</option>
        <option value="bon_casa">Bon de casa</option>
      </select>
    </div>
    <div class="wm-filter-group">
      <label class="wm-filter-label">Status plata</label>
      <select class="wm-filter-input" wire:model.live="statusPlata">
        <option value="">Toate</option>
        <option value="scadent">In termen</option>
      </select>
    </div>
    <div class="wm-filter-group">
      <label class="wm-filter-label">Sortare</label>
      <select class="wm-filter-input" wire:model.live="sortBy">
        <option value="recent">Cele mai recente</option>
        <option value="total_desc">Total descrescator</option>
        <option value="total_asc">Total crescator</option>
        <option value="scadenta">Scadenta (apropiata)</option>
      </select>
    </div>
    @if(count($agenti) > 1)
    <div class="wm-filter-group">
      <label class="wm-filter-label">Agent</label>
      <select class="wm-filter-input" wire:model.live="agent">
        <option value="">Toti</option>
        @foreach($agenti as $a)
          <option value="{{ $a['marca'] }}">{{ $a['name'] }}</option>
        @endforeach
      </select>
    </div>
    @endif
  </div>

  {{-- Header with summary --}}
  @php $summary = $this->getPeriodSummary(); @endphp
  <div class="wm-card-header" style="flex-direction:column;align-items:stretch;gap:.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
      <span class="wm-card-title">Documente vanzare</span>
      <div style="display:flex;align-items:center;gap:.75rem;">
        <span class="wm-card-count">
          {{ $total > 0 ? "{$from}-{$to} din {$total} documente" : '0 documente' }}
        </span>
        @if($total > 0)
          <button wire:click="exportCsv" class="wm-export-btn" title="Export CSV">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
            CSV
          </button>
        @endif
      </div>
    </div>
    @if($total > 0)
    <div style="display:flex;flex-wrap:wrap;gap:1.25rem;font-size:.8rem;color:#6b7280;">
      <span>Total: <strong style="color:#111827;">{{ number_format($summary['val_total'] ?? 0, 0, ',', '.') }} RON</strong></span>
      @if(($summary['nr_facturi'] ?? 0) > 0)
        <span>{{ $summary['nr_facturi'] }} facturi</span>
      @endif
      @if(($summary['nr_avize'] ?? 0) > 0)
        <span>{{ $summary['nr_avize'] }} avize</span>
      @endif
      @if(($summary['nr_bonuri'] ?? 0) > 0)
        <span>{{ $summary['nr_bonuri'] }} bonuri</span>
      @endif
    </div>
    @endif
  </div>

  {{-- Table --}}
  @if(empty($rows))
    <div class="wm-empty">Niciun document pentru filtrele selectate.</div>
  @else
  <div style="overflow-x:auto;">
    <table class="wm-table">
      <thead>
        <tr>
          <th>Tip</th>
          <th>Nr. Document</th>
          <th>Data</th>
          <th>Partener</th>
          <th>Agent</th>
          <th style="text-align:right;">Total cu TVA</th>
          <th>Status plata</th>
          <th>Detalii</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $row)
        <tr wire:click="openDocument('{{ $row->nr_factura }}', {{ $row->an }}, {{ $row->luna }})">
          <td style="white-space:nowrap;">
            <span class="wm-badge wm-badge--{{ $row->tip_color }}">{{ $row->tip_doc }}</span>
          </td>
          <td style="font-weight:600;font-family:monospace;">
            {{ $row->nr_factura }}
            @if($row->serie_document)
              <span style="font-size:.7rem;color:#9ca3af;font-weight:400;"> {{ $row->serie_document }}</span>
            @endif
          </td>
          <td style="white-space:nowrap;">{{ $row->date_str }}</td>
          <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $row->partner_name }}">
            {{ $row->partner_name }}
            @if($row->localitate_client)
              <br><span class="wm-loc">{{ $row->localitate_client }}</span>
            @endif
          </td>
          <td style="font-size:.8rem;color:#6b7280;white-space:nowrap;">{{ $row->agent_name ?? '' }}</td>
          <td style="text-align:right;" class="wm-total {{ $row->total_cu_tva < 0 ? 'wm-negative' : '' }}">
            {{ number_format($row->total_cu_tva, 2, ',', '.') }}
            <div style="font-size:.7rem;color:#9ca3af;font-weight:400;">{{ $row->nr_linii }} poz.</div>
          </td>
          <td style="white-space:nowrap;">
            @if($row->tip_doc === 'Bon casă')
              <span class="wm-badge wm-badge--green" style="font-size:.65rem;">Incasat</span>
            @elseif($row->plata_tip)
              @php
                $plataBadge = match($row->plata_tip) {
                  'BF' => ['green', 'Bon fiscal'],
                  'CH' => ['green', 'Chitanta'],
                  'EX' => ['blue', 'Banca'],
                  'DI' => ['amber', 'DI'],
                  default => ['gray', $row->plata_tip],
                };
              @endphp
              <span class="wm-badge wm-badge--{{ $plataBadge[0] }}" style="font-size:.65rem;">{{ $plataBadge[1] }}</span>
              @if($row->scadenta_str)
                <div style="font-size:.65rem;color:#059669;">{{ $row->scadenta_str }}</div>
              @endif
            @elseif($row->scadenta_str && $row->tip_doc === 'Factură')
              <span class="wm-badge" style="background:#fef3c7;color:#92400e;font-size:.65rem;">Neincasat</span>
              <div style="font-size:.65rem;color:#6b7280;">scad. {{ $row->scadenta_str }}</div>
            @elseif($row->tip_doc === 'Aviz')
              <span style="font-size:.7rem;color:#9ca3af;">-</span>
            @endif
          </td>
          <td>
            @if($row->observatii)
              <span class="wm-obs" title="{{ $row->observatii }}">{{ $row->observatii }}</span>
            @else
              <span style="font-size:12px;color:#d1d5db;">
                {{ $row->detected_at ? \Carbon\Carbon::parse($row->detected_at)->format('d.m H:i') : '' }}
              </span>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Pagination --}}
  @if($totalPages > 1)
  <div class="wm-pagination">
    <button class="wm-page-btn" wire:click="prevPage" @disabled($this->page <= 1)>&larr; Anterior</button>
    <span>Pagina {{ $this->page }} din {{ $totalPages }}</span>
    <button class="wm-page-btn" wire:click="nextPage" @disabled($this->page >= $totalPages)>Urmator &rarr;</button>
  </div>
  @endif
  @endif
</div>

{{-- Chart.js --}}
@if($isSuperAdmin && !empty($chart['labels']))
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const ctx = document.getElementById('dailyChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: @json($chart['labels']),
      datasets: [
        { label: 'Avize', data: @json($chart['avize']), backgroundColor: 'rgba(245,158,11,.7)', borderRadius: 3 },
        { label: 'Facturi', data: @json($chart['facturi']), backgroundColor: 'rgba(59,130,246,.7)', borderRadius: 3 },
        { label: 'Bonuri', data: @json($chart['bonuri']), backgroundColor: 'rgba(16,185,129,.7)', borderRadius: 3 },
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'top', labels: { boxWidth: 12, font: { size: 12 } } },
        tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + new Intl.NumberFormat('ro-RO').format(c.parsed.y) + ' RON' } }
      },
      scales: {
        x: { stacked: true, grid: { display: false } },
        y: { stacked: true, ticks: { callback: (v) => new Intl.NumberFormat('ro-RO', { notation: 'compact' }).format(v) } }
      }
    }
  });
});
</script>
@endif
</x-filament-panels::page>
