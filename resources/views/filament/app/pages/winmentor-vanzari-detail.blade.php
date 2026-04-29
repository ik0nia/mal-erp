<x-filament-panels::page>
<style>
.wm-back{display:inline-flex;align-items:center;gap:.375rem;font-size:.875rem;color:#6b7280;text-decoration:none;margin-bottom:1rem;padding:.375rem .75rem;border:1px solid #e5e7eb;border-radius:.5rem;background:#fff;}
.wm-back:hover{background:#f9fafb;color:#374151;}
.wm-card{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;overflow:hidden;margin-bottom:1.25rem;}
.wm-card-header{padding:.75rem 1.25rem;border-bottom:1px solid #f3f4f6;background:#f9fafb;display:flex;align-items:center;justify-content:space-between;}
.wm-card-title{font-size:.875rem;font-weight:600;color:#1f2937;}
.wm-antet{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;padding:1.25rem;}
.wm-antet-row{display:flex;flex-direction:column;gap:.25rem;}
.wm-antet-label{font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;}
.wm-antet-value{font-size:.9375rem;color:#111827;font-weight:500;}
.wm-badge{display:inline-flex;padding:.125rem .5rem;border-radius:9999px;font-size:.75rem;font-weight:600;}
.wm-badge--blue{background:#dbeafe;color:#1d4ed8;}
.wm-badge--amber{background:#fef3c7;color:#92400e;}
.wm-badge--green{background:#d1fae5;color:#065f46;}
.wm-badge--gray{background:#f3f4f6;color:#4b5563;}
.wm-table{width:100%;border-collapse:collapse;font-size:.875rem;}
.wm-table th{padding:.625rem 1rem;text-align:left;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:1px solid #f3f4f6;white-space:nowrap;}
.wm-table td{padding:.625rem 1rem;border-bottom:1px solid #f9fafb;color:#374151;}
.wm-table tfoot td{padding:.75rem 1rem;font-weight:700;color:#111827;border-top:2px solid #e5e7eb;background:#f9fafb;}
.wm-negative{color:#b91c1c;}
.wm-empty{display:flex;align-items:center;justify-content:center;padding:3rem;color:#9ca3af;}
</style>

<a href="{{ \App\Filament\App\Pages\WinmentorVanzariPage::getUrl() }}" class="wm-back">
  ← Înapoi la Vânzări
</a>

@php
  $doc   = $this->getDocument();
  $lines = $this->getLines();
  $tipColor = match($doc?->tip_doc ?? '') {
    'Factură'  => 'blue',
    'Bon casă' => 'green',
    'Aviz'     => 'amber',
    default    => 'gray',
  };
@endphp

@if(! $doc)
  <div class="wm-card">
    <div class="wm-empty">Documentul nu a fost găsit.</div>
  </div>
@else

{{-- Antet document --}}
<div class="wm-card">
  <div class="wm-card-header">
    <span class="wm-card-title">
      <span class="wm-badge wm-badge--{{ $tipColor }}">{{ $doc->tip_doc }}</span>
      &nbsp; Nr. <strong>{{ $doc->nr_factura }}</strong>
    </span>
    <span style="font-size:.875rem;color:#6b7280;">{{ $doc->date_str }}</span>
  </div>
  <div class="wm-antet">
    <div class="wm-antet-row">
      <span class="wm-antet-label">Partener</span>
      <span class="wm-antet-value">{{ $doc->partner_name }}</span>
    </div>
    @if($doc->part_id)
    <div class="wm-antet-row">
      <span class="wm-antet-label">ID Partener (WM)</span>
      <span class="wm-antet-value" style="font-family:monospace;">{{ $doc->part_id }}</span>
    </div>
    @endif
    <div class="wm-antet-row">
      <span class="wm-antet-label">Data document</span>
      <span class="wm-antet-value">{{ $doc->date_str }}</span>
    </div>
    <div class="wm-antet-row">
      <span class="wm-antet-label">Număr linii</span>
      <span class="wm-antet-value">{{ $doc->nr_linii }}</span>
    </div>
    <div class="wm-antet-row">
      <span class="wm-antet-label">Total document</span>
      <span class="wm-antet-value" style="{{ $doc->total < 0 ? 'color:#b91c1c;' : '' }}">
        {{ number_format($doc->total, 2, ',', '.') }} RON
      </span>
    </div>
  </div>
</div>

{{-- Linii document --}}
<div class="wm-card">
  <div class="wm-card-header">
    <span class="wm-card-title">Poziții document</span>
    <span style="font-size:.75rem;color:#9ca3af;">{{ count($lines) }} poziții</span>
  </div>
  @if(empty($lines))
    <div class="wm-empty">Nicio poziție.</div>
  @else
  <div style="overflow-x:auto;">
    <table class="wm-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Produs</th>
          <th>SKU / GTIN</th>
          <th>Gestiune</th>
          <th style="text-align:right;">Cantitate</th>
          <th>UM</th>
          <th style="text-align:right;">Preț (RON)</th>
          <th style="text-align:right;">Total (RON)</th>
        </tr>
      </thead>
      <tbody>
        @foreach($lines as $i => $line)
        <tr>
          <td style="color:#9ca3af;">{{ $i + 1 }}</td>
          <td style="max-width:280px;">
            {{ $line->display_name }}
            @if(! $line->product_name && $line->sku)
              <span style="font-size:.7rem;color:#d97706;display:block;">neidentificat în ERP</span>
            @endif
          </td>
          <td style="font-family:monospace;font-size:.8rem;color:#6b7280;">{{ $line->sku ?? '—' }}</td>
          <td style="font-size:.8rem;color:#6b7280;">{{ $line->den_gestiune ?? '—' }}</td>
          <td style="text-align:right;{{ $line->cantitate < 0 ? 'color:#b91c1c;' : '' }}">
            {{ number_format($line->cantitate, 3, ',', '.') }}
          </td>
          <td style="color:#6b7280;">{{ $line->uom ?? '—' }}</td>
          <td style="text-align:right;">{{ number_format($line->pret, 4, ',', '.') }}</td>
          <td style="text-align:right;font-weight:600;{{ $line->total < 0 ? 'color:#b91c1c;' : '' }}">
            {{ number_format($line->total, 2, ',', '.') }}
          </td>
        </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr>
          <td colspan="7" style="text-align:right;">TOTAL</td>
          <td style="text-align:right;{{ $doc->total < 0 ? 'color:#b91c1c;' : '' }}">
            {{ number_format($doc->total, 2, ',', '.') }} RON
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
  @endif
</div>

@endif
</x-filament-panels::page>
