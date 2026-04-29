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
.wm-table{width:100%;border-collapse:collapse;font-size:.875rem;}
.wm-table th{padding:.625rem 1rem;text-align:left;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:1px solid #f3f4f6;white-space:nowrap;}
.wm-table td{padding:.625rem 1rem;border-bottom:1px solid #f9fafb;color:#374151;}
.wm-table tfoot td{padding:.75rem 1rem;font-weight:700;color:#111827;border-top:2px solid #e5e7eb;background:#f9fafb;}
.wm-empty{display:flex;align-items:center;justify-content:center;padding:3rem;color:#9ca3af;}
.wm-badge-eur{display:inline-flex;padding:.125rem .375rem;border-radius:.25rem;font-size:.7rem;font-weight:600;background:#fef3c7;color:#92400e;margin-left:.25rem;}
</style>

<a href="{{ \App\Filament\App\Pages\WinmentorCumparariPage::getUrl() }}" class="wm-back">
  ← Înapoi la Cumpărări
</a>

@php
  $doc   = $this->getDocument();
  $lines = $this->getLines();
@endphp

@if(! $doc)
  <div class="wm-card">
    <div class="wm-empty">Documentul nu a fost găsit.</div>
  </div>
@else

<div class="wm-card">
  <div class="wm-card-header">
    <span class="wm-card-title">Recepție / Intrare marfă &mdash; Nr. factură <strong>{{ $doc->nr_doc }}</strong></span>
    <span style="font-size:.875rem;color:#6b7280;">{{ $doc->date_str }}</span>
  </div>
  <div class="wm-antet">
    <div class="wm-antet-row">
      <span class="wm-antet-label">Furnizor</span>
      <span class="wm-antet-value">{{ $doc->partner_name }}</span>
    </div>
    @if($doc->nr_receptie)
    <div class="wm-antet-row">
      <span class="wm-antet-label">Nr. Recepție</span>
      <span class="wm-antet-value" style="font-family:monospace;">{{ $doc->nr_receptie }}</span>
    </div>
    @endif
    <div class="wm-antet-row">
      <span class="wm-antet-label">Data intrare</span>
      <span class="wm-antet-value">{{ $doc->date_str }}</span>
    </div>
    <div class="wm-antet-row">
      <span class="wm-antet-label">Număr linii</span>
      <span class="wm-antet-value">{{ $doc->nr_linii }}</span>
    </div>
    <div class="wm-antet-row">
      <span class="wm-antet-label">Total intrare</span>
      <span class="wm-antet-value">{{ number_format($doc->total, 2, ',', '.') }} RON</span>
    </div>
  </div>
</div>

<div class="wm-card">
  <div class="wm-card-header">
    <span class="wm-card-title">Poziții recepție</span>
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
          <th>SKU / Cod</th>
          <th>Gestiune</th>
          <th style="text-align:right;">Cantitate</th>
          <th>UM</th>
          <th style="text-align:right;">Preț intrare</th>
          <th style="text-align:right;">Preț vânzare</th>
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
          <td style="text-align:right;">{{ number_format($line->cantitate, 3, ',', '.') }}</td>
          <td style="color:#6b7280;">{{ $line->uom ?? '—' }}</td>
          <td style="text-align:right;">
            {{ number_format($line->pret, 4, ',', '.') }}
            @if($line->moneda === 'EUR')
              <span class="wm-badge-eur">EUR</span>
              @if($line->curs_bnr)
                <span style="display:block;font-size:.7rem;color:#9ca3af;">curs {{ number_format($line->curs_bnr, 4, ',', '.') }}</span>
              @endif
            @endif
          </td>
          <td style="text-align:right;">
            {{ $line->pret_vanzare ? number_format($line->pret_vanzare, 4, ',', '.') : '—' }}
          </td>
          <td style="text-align:right;font-weight:600;">
            {{ number_format($line->total, 2, ',', '.') }}
          </td>
        </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr>
          <td colspan="8" style="text-align:right;">TOTAL</td>
          <td style="text-align:right;">{{ number_format($doc->total, 2, ',', '.') }} RON</td>
        </tr>
      </tfoot>
    </table>
  </div>
  @endif
</div>

@endif
</x-filament-panels::page>
