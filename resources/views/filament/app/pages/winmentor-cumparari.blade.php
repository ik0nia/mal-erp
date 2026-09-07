<x-filament-panels::page>
<style>
.wm-filters{display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;padding:1rem 1.25rem;background:#f9fafb;border-bottom:1px solid #e5e7eb;}
.wm-filter-group{display:flex;flex-direction:column;gap:.25rem;}
.wm-filter-label{font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.wm-filter-input{border:1px solid #d1d5db;border-radius:.5rem;padding:.375rem .75rem;font-size:.875rem;color:#111827;background:#fff;outline:none;}
.wm-filter-input:focus{border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15);}
.wm-card{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;overflow:hidden;margin-bottom:1.5rem;}
.wm-card-header{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-bottom:1px solid #f3f4f6;background:#f9fafb;}
.wm-card-title{font-size:.875rem;font-weight:600;color:#1f2937;}
.wm-card-count{font-size:.75rem;color:#9ca3af;}
.wm-table{width:100%;border-collapse:collapse;font-size:.875rem;}
.wm-table th{padding:.625rem 1rem;text-align:left;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:1px solid #f3f4f6;white-space:nowrap;}
.wm-table td{padding:.625rem 1rem;border-bottom:1px solid #f9fafb;color:#374151;}
.wm-table tr:hover td{background:#f9fafb;cursor:pointer;}
.wm-total{font-weight:600;color:#111827;white-space:nowrap;}
.wm-empty{display:flex;align-items:center;justify-content:center;padding:3rem;color:#9ca3af;font-size:.875rem;}
</style>

<div class="wm-card">
  <div class="wm-filters">
    <div class="wm-filter-group">
      <label class="wm-filter-label">De la</label>
      <input type="date" class="wm-filter-input" wire:model.blur="dateFrom">
    </div>
    <div class="wm-filter-group">
      <label class="wm-filter-label">Până la</label>
      <input type="date" class="wm-filter-input" wire:model.blur="dateTo">
    </div>
    <div style="display:flex;gap:.35rem;align-items:center;padding-bottom:.1rem;">
      <button wire:click="goPrevMonth" class="wm-filter-input" style="cursor:pointer;" title="Luna anterioară">&laquo;</button>
      <button wire:click="goToday" class="wm-filter-input" style="cursor:pointer;">Azi</button>
      <button wire:click="goYesterday" class="wm-filter-input" style="cursor:pointer;">Ieri</button>
      <button wire:click="goThisMonth" class="wm-filter-input" style="cursor:pointer;">Luna curentă</button>
      <button wire:click="goLastMonth" class="wm-filter-input" style="cursor:pointer;">Luna trecută</button>
      <button wire:click="goNextMonth" class="wm-filter-input" style="cursor:pointer;" title="Luna următoare">&raquo;</button>
    </div>
  </div>

  @php $rows = $this->getRows(); @endphp
  <div class="wm-card-header">
    <span class="wm-card-title">Recepții / Intrări marfă</span>
    <span class="wm-card-count" style="display:flex;gap:.5rem;align-items:center;">
      <span>pagina {{ $this->page }} · {{ count($rows) }} documente</span>
      @if($this->page > 1)
        <button wire:click="prevPage" class="wm-filter-input" style="cursor:pointer;padding:.15rem .6rem;">&lsaquo; mai noi</button>
      @endif
      @if($this->hasMore)
        <button wire:click="nextPage" class="wm-filter-input" style="cursor:pointer;padding:.15rem .6rem;">mai vechi &rsaquo;</button>
      @endif
    </span>
  </div>

  @if(empty($rows))
    <div class="wm-empty">Niciun document pentru intervalul selectat.</div>
  @else
  <div style="overflow-x:auto;">
    <table class="wm-table">
      <thead>
        <tr>
          <th>Nr. Factură</th>
          <th>Nr. Recepție</th>
          <th>Data</th>
          <th>Furnizor</th>
          <th>PO asociat</th>
          <th style="text-align:right;">Nr. linii</th>
          <th style="text-align:right;">Total (RON)</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $row)
        <tr wire:click="openDocument('{{ $row->nr_doc }}', '{{ $row->nr_receptie }}', {{ $row->an }}, {{ $row->luna }})">
          <td style="font-weight:600;font-family:monospace;">{{ $row->nr_doc ?? '—' }}{!! $row->are_eur ? ' <span style="font-size:.65rem;color:#b45309;font-weight:700;">EUR</span>' : '' !!}</td>
          <td style="font-family:monospace;color:#6b7280;">{{ $row->nr_receptie ?? '—' }}</td>
          <td style="white-space:nowrap;">{{ $row->date_str }}</td>
          <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            @if($row->supplier_id)
              <a href="{{ \App\Filament\App\Resources\SupplierResource::getUrl('view', ['record' => $row->supplier_id]) }}"
                 onclick="event.stopPropagation();" style="color:#4f46e5;text-decoration:none;" class="hover:underline">
                {{ $row->partner_name }}
              </a>
            @else
              {{ $row->partner_name }}
            @endif
          </td>
          <td>
            @if($row->po_url)
              <a href="{{ $row->po_url }}"
                 onclick="event.stopPropagation();"
                 style="font-size:.75rem;font-weight:600;color:#4f46e5;font-family:monospace;white-space:nowrap;text-decoration:none;"
                 class="hover:underline">
                {{ $row->po_number }}
              </a>
            @else
              <span style="color:#d1d5db;font-size:.75rem;">—</span>
            @endif
          </td>
          <td style="text-align:right;">{{ $row->nr_linii }}</td>
          <td style="text-align:right;" class="wm-total">
            {{ number_format($row->total, 2, ',', '.') }}
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @endif
</div>
</x-filament-panels::page>
