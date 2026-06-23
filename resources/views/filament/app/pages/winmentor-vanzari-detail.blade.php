<x-filament-panels::page>
<style>
.wm-back{display:inline-flex;align-items:center;gap:.375rem;font-size:.875rem;color:#6b7280;text-decoration:none;margin-bottom:1rem;padding:.375rem .75rem;border:1px solid #e5e7eb;border-radius:.5rem;background:#fff;}
.wm-back:hover{background:#f9fafb;color:#374151;}
.wm-card{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;overflow:hidden;margin-bottom:1.25rem;}
.wm-card-header{padding:.75rem 1.25rem;border-bottom:1px solid #f3f4f6;background:#f9fafb;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;}
.wm-card-title{font-size:.875rem;font-weight:600;color:#1f2937;}
.wm-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0;padding:0;}
.wm-detail-item{padding:1rem 1.25rem;border-bottom:1px solid #f9fafb;border-right:1px solid #f9fafb;}
.wm-detail-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:.25rem;}
.wm-detail-value{font-size:.9375rem;color:#111827;font-weight:500;}
.wm-detail-sub{font-size:.75rem;color:#6b7280;margin-top:.125rem;}
.wm-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.125rem .5rem;border-radius:9999px;font-size:.75rem;font-weight:600;}
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
.wm-obs-box{padding:1rem 1.25rem;background:#fffbeb;border-left:4px solid #f59e0b;margin:0;}
.wm-obs-label{font-size:.7rem;font-weight:600;text-transform:uppercase;color:#92400e;margin-bottom:.25rem;}
.wm-obs-text{font-size:.875rem;color:#78350f;}
.wm-print-btn{display:inline-flex;align-items:center;gap:.375rem;font-size:.875rem;color:#fff;text-decoration:none;margin-bottom:1rem;margin-left:.5rem;padding:.375rem .85rem;border:none;border-radius:.5rem;background:#b91c1c;}
.wm-print-btn:hover{background:#991b1b;}
</style>

<a href="{{ \App\Filament\App\Pages\WinmentorVanzariPage::getUrl() }}" class="wm-back">
  &larr; Inapoi la Vanzari
</a>

@php
  $linkedOrder = $this->getLinkedOrder();
@endphp
@if($linkedOrder)
  <a href="{{ $linkedOrder['url'] }}" class="wm-back" style="margin-left:.75rem;color:#1d4ed8;">
    &rarr; Comanda online #{{ $linkedOrder['number'] }}
  </a>
@endif
<a href="{{ route('print.winmentor-factura', ['nr' => $this->nr, 'an' => $this->an, 'luna' => $this->luna]) }}" target="_blank" class="wm-print-btn">&#128424; Printează factura</a>

@php
  $doc   = $this->getDocument();
  $lines = $this->getLines();
  $tipColor = match($doc?->tip_doc ?? '') {
    'Factura'  => 'blue',
    'Bon casa' => 'green',
    'Aviz'     => 'amber',
    default    => 'gray',
  };
@endphp

@if(! $doc)
  <div class="wm-card">
    <div class="wm-empty">Documentul nu a fost gasit.</div>
  </div>
@else

{{-- Header --}}
<div class="wm-card">
  <div class="wm-card-header">
    <span class="wm-card-title">
      <span class="wm-badge wm-badge--{{ $tipColor }}">{{ $doc->tip_doc }}</span>
      @if($doc->is_cash ?? false)
        <span class="wm-badge wm-badge--green">CASH</span>
      @endif
      &nbsp; Nr. <strong>{{ $doc->nr_factura }}</strong>
      @if($doc->serie_document)
        <span style="font-size:.8rem;color:#6b7280;font-weight:400;">{{ $doc->serie_document }}</span>
      @endif
    </span>
    <span style="font-size:.875rem;color:#6b7280;">{{ $doc->date_str }}</span>
  </div>

  <div class="wm-detail-grid">
    {{-- Partener --}}
    <div class="wm-detail-item">
      <div class="wm-detail-label">Partener</div>
      <div class="wm-detail-value">{{ $doc->partner_name }}</div>
      @if($doc->partner_cui)
        <div class="wm-detail-sub">CUI: {{ $doc->partner_cui }}</div>
      @endif
    </div>

    {{-- Adresa --}}
    <div class="wm-detail-item">
      <div class="wm-detail-label">Adresa</div>
      <div class="wm-detail-value">{{ $doc->partner_loc ?: '-' }}</div>
      @if($doc->partner_addr)
        <div class="wm-detail-sub">{{ $doc->partner_addr }}</div>
      @endif
      @if($doc->partner_judet)
        <div class="wm-detail-sub">Judet: {{ $doc->partner_judet }}</div>
      @endif
    </div>

    {{-- Contact --}}
    <div class="wm-detail-item">
      <div class="wm-detail-label">Contact</div>
      <div class="wm-detail-value">{{ $doc->partner_phone ?: '-' }}</div>
      @if($doc->partner_email)
        <div class="wm-detail-sub">{{ $doc->partner_email }}</div>
      @endif
    </div>

    {{-- Agent --}}
    @if($doc->agent_name)
    <div class="wm-detail-item">
      <div class="wm-detail-label">Agent</div>
      <div class="wm-detail-value">{{ $doc->agent_name }}</div>
      @if($doc->marca_agent)
        <div class="wm-detail-sub">Marca: {{ $doc->marca_agent }}</div>
      @endif
    </div>
    @endif

    {{-- Document --}}
    <div class="wm-detail-item">
      <div class="wm-detail-label">Data document</div>
      <div class="wm-detail-value">{{ $doc->date_str }}</div>
      @if($doc->part_id)
        <div class="wm-detail-sub">ID WM: {{ $doc->part_id }}</div>
      @endif
    </div>

    {{-- Total --}}
    <div class="wm-detail-item">
      <div class="wm-detail-label">Total fara TVA</div>
      <div class="wm-detail-value" style="{{ $doc->total < 0 ? 'color:#b91c1c;' : '' }}">
        {{ number_format($doc->total, 2, ',', '.') }} RON
      </div>
      <div class="wm-detail-sub">{{ $doc->nr_linii }} pozitii</div>
    </div>
    <div class="wm-detail-item">
      <div class="wm-detail-label">Total cu TVA (21%)</div>
      <div class="wm-detail-value" style="{{ $doc->total < 0 ? 'color:#b91c1c;' : '' }}font-size:1.25rem;font-weight:700;">
        {{ number_format($doc->total_cu_tva, 2, ',', '.') }} RON
      </div>
      <div class="wm-detail-sub">TVA: {{ number_format($doc->tva_estimat, 2, ',', '.') }} RON</div>
    </div>

    {{-- Discount --}}
    @if($doc->discount && $doc->discount != '0')
    <div class="wm-detail-item">
      <div class="wm-detail-label">Discount</div>
      <div class="wm-detail-value">{{ $doc->discount }}%</div>
    </div>
    @endif
  </div>

  {{-- Observatii --}}
  @if($doc->observatii)
  <div class="wm-obs-box">
    <div class="wm-obs-label">Observatii</div>
    <div class="wm-obs-text">{{ $doc->observatii }}</div>
  </div>
  @endif
</div>

{{-- Incasari --}}
@if(!empty($doc->incasari))
<div class="wm-card">
  <div class="wm-card-header">
    <span class="wm-card-title">Incasari / Plati</span>
    <span style="font-size:.75rem;color:#9ca3af;">{{ count($doc->incasari) }} {{ count($doc->incasari) === 1 ? 'incasare' : 'incasari' }}</span>
  </div>
  <div style="overflow-x:auto;">
    <table class="wm-table">
      <thead>
        <tr>
          <th>Document</th>
          <th>Tip plata</th>
          <th>Data</th>
          <th style="text-align:right;">Suma (RON)</th>
        </tr>
      </thead>
      <tbody>
        @foreach($doc->incasari as $inc)
        <tr>
          <td style="font-family:monospace;font-weight:600;">{{ $inc->document_ref }}</td>
          <td>
            @php
              $incColor = match(true) {
                str_contains($inc->tip_plata, 'cash') => 'green',
                str_contains($inc->tip_plata, 'banca') || str_contains($inc->tip_plata, 'card') => 'blue',
                default => 'gray',
              };
            @endphp
            <span class="wm-badge wm-badge--{{ $incColor }}">{{ $inc->tip_plata }}</span>
          </td>
          <td>{{ $inc->data }}</td>
          <td style="text-align:right;font-weight:600;">{{ $inc->suma }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endif

{{-- Linii document --}}
<div class="wm-card">
  <div class="wm-card-header">
    <span class="wm-card-title">Pozitii document</span>
    <span style="font-size:.75rem;color:#9ca3af;">{{ count($lines) }} pozitii</span>
  </div>
  @if(empty($lines))
    <div class="wm-empty">Nicio pozitie.</div>
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
          <th style="text-align:right;">Pret (RON)</th>
          @if(collect($lines)->contains(fn($l) => $l->discount && $l->discount != '0'))
          <th style="text-align:right;">Disc.</th>
          @endif
          <th style="text-align:right;">Total (RON)</th>
        </tr>
      </thead>
      <tbody>
        @php $hasDiscount = collect($lines)->contains(fn($l) => $l->discount && $l->discount != '0'); @endphp
        @foreach($lines as $i => $line)
        <tr>
          <td style="color:#9ca3af;">{{ $i + 1 }}</td>
          <td style="max-width:280px;">
            @if($line->woo_product_id)
              <a href="{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $line->woo_product_id]) }}" style="color:#1d4ed8;text-decoration:none;" title="Deschide produs">{{ $line->display_name }}</a>
            @else
              {{ $line->display_name }}
              @if(! $line->product_name && $line->sku)
                <span style="font-size:.7rem;color:#d97706;display:block;">neidentificat in ERP</span>
              @endif
            @endif
          </td>
          <td style="font-family:monospace;font-size:.8rem;color:#6b7280;">{{ $line->sku ?? '-' }}</td>
          <td style="font-size:.8rem;color:#6b7280;">{{ $line->den_gestiune ?? '-' }}</td>
          <td style="text-align:right;{{ $line->cantitate < 0 ? 'color:#b91c1c;' : '' }}">
            {{ number_format($line->cantitate, 3, ',', '.') }}
          </td>
          <td style="color:#6b7280;">{{ $line->uom ?? '-' }}</td>
          <td style="text-align:right;">{{ number_format($line->pret, 4, ',', '.') }}</td>
          @if($hasDiscount)
          <td style="text-align:right;font-size:.8rem;color:#6b7280;">
            {{ ($line->discount && $line->discount != '0') ? $line->discount . '%' : '' }}
          </td>
          @endif
          <td style="text-align:right;font-weight:600;{{ $line->total < 0 ? 'color:#b91c1c;' : '' }}">
            {{ number_format($line->total, 2, ',', '.') }}
          </td>
        </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr>
          <td colspan="{{ $hasDiscount ? 8 : 7 }}" style="text-align:right;">Total fara TVA</td>
          <td style="text-align:right;{{ $doc->total < 0 ? 'color:#b91c1c;' : '' }}">
            {{ number_format($doc->total, 2, ',', '.') }} RON
          </td>
        </tr>
        <tr>
          <td colspan="{{ $hasDiscount ? 8 : 7 }}" style="text-align:right;font-weight:500;color:#6b7280;">TVA (21%)</td>
          <td style="text-align:right;font-weight:500;color:#6b7280;">
            {{ number_format($doc->tva_estimat, 2, ',', '.') }} RON
          </td>
        </tr>
        <tr>
          <td colspan="{{ $hasDiscount ? 8 : 7 }}" style="text-align:right;font-size:1rem;">TOTAL cu TVA</td>
          <td style="text-align:right;font-size:1rem;{{ $doc->total_cu_tva < 0 ? 'color:#b91c1c;' : '' }}">
            {{ number_format($doc->total_cu_tva, 2, ',', '.') }} RON
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
  @endif
</div>

@endif
</x-filament-panels::page>
