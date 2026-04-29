<x-filament-panels::page>
<style>
.wm-filters{display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;padding:1rem 1.25rem;background:#f9fafb;border-bottom:1px solid #e5e7eb;}
.wm-filter-group{display:flex;flex-direction:column;gap:.25rem;}
.wm-filter-label{font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.wm-filter-input{border:1px solid #d1d5db;border-radius:.5rem;padding:.375rem .75rem;font-size:.875rem;color:#111827;background:#fff;outline:none;min-width:140px;}
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
.wm-badge{display:inline-flex;padding:.125rem .5rem;border-radius:9999px;font-size:.75rem;font-weight:600;}
.wm-badge--blue{background:#dbeafe;color:#1d4ed8;}
.wm-badge--amber{background:#fef3c7;color:#92400e;}
.wm-badge--green{background:#d1fae5;color:#065f46;}
.wm-badge--gray{background:#f3f4f6;color:#4b5563;}
.wm-total{font-weight:600;color:#111827;white-space:nowrap;}
.wm-negative{color:#b91c1c;}
.wm-empty{display:flex;align-items:center;justify-content:center;padding:3rem;color:#9ca3af;font-size:.875rem;}
.wm-pagination{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-top:1px solid #f3f4f6;font-size:.875rem;color:#6b7280;}
.wm-page-btn{padding:.375rem .75rem;border:1px solid #d1d5db;border-radius:.5rem;background:#fff;font-size:.8rem;color:#374151;cursor:pointer;}
.wm-page-btn:hover{background:#f9fafb;}
.wm-page-btn:disabled{opacity:.4;cursor:default;}
</style>

@php
  $rows       = $this->getRows();
  $total      = $this->getTotalCount();
  $totalPages = max(1, (int) ceil($total / $this->perPage));
  $from       = (($this->page - 1) * $this->perPage) + 1;
  $to         = min($this->page * $this->perPage, $total);
@endphp

<div class="wm-card">
  {{-- Filters --}}
  <div class="wm-filters">
    <div class="wm-filter-group wm-search">
      <label class="wm-filter-label">Caută (nr. document, denumire, CUI)</label>
      <input type="text" class="wm-filter-input" placeholder="ex: 165190, Decor Door, RO31959468" wire:model.live.debounce.400ms="search">
    </div>
    <div class="wm-filter-group">
      <label class="wm-filter-label">De la</label>
      <input type="date" class="wm-filter-input" wire:model.blur="dateFrom">
    </div>
    <div class="wm-filter-group">
      <label class="wm-filter-label">Până la</label>
      <input type="date" class="wm-filter-input" wire:model.blur="dateTo">
    </div>
    <div class="wm-filter-group">
      <label class="wm-filter-label">Tip document</label>
      <select class="wm-filter-input" wire:model.live="tipDoc">
        <option value="">Toate tipurile</option>
        <option value="factura">Factură</option>
        <option value="aviz">Aviz</option>
        <option value="bon_casa">Bon casă</option>
      </select>
    </div>
  </div>

  {{-- Header --}}
  <div class="wm-card-header">
    <span class="wm-card-title">Documente vânzare</span>
    <span class="wm-card-count">
      {{ $total > 0 ? "{$from}–{$to} din {$total} documente" : '0 documente' }}
    </span>
  </div>

  {{-- Table --}}
  @if(empty($rows))
    <div class="wm-empty">Niciun document pentru filtrele selectate.</div>
  @else
  <div style="overflow-x:auto;">
    <table class="wm-table">
      <thead>
        <tr>
          <th>Nr. Document</th>
          <th>Data</th>
          <th>Tip</th>
          <th>Partener</th>
          <th style="text-align:right;">Nr. linii</th>
          <th style="text-align:right;">Total (RON)</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $row)
        <tr wire:click="openDocument('{{ $row->nr_factura }}', {{ $row->an }}, {{ $row->luna }})">
          <td style="font-weight:600;font-family:monospace;">{{ $row->nr_factura }}</td>
          <td style="white-space:nowrap;">{{ $row->date_str }}</td>
          <td><span class="wm-badge wm-badge--{{ $row->tip_color }}">{{ $row->tip_doc }}</span></td>
          <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $row->partner_name }}">
            {{ $row->partner_name }}
          </td>
          <td style="text-align:right;">{{ $row->nr_linii }}</td>
          <td style="text-align:right;" class="wm-total {{ $row->total < 0 ? 'wm-negative' : '' }}">
            {{ number_format($row->total, 2, ',', '.') }}
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Pagination --}}
  @if($totalPages > 1)
  <div class="wm-pagination">
    <button class="wm-page-btn" wire:click="prevPage" @disabled($this->page <= 1)>← Anterior</button>
    <span>Pagina {{ $this->page }} din {{ $totalPages }}</span>
    <button class="wm-page-btn" wire:click="nextPage" @disabled($this->page >= $totalPages)>Următor →</button>
  </div>
  @endif
  @endif
</div>
</x-filament-panels::page>
