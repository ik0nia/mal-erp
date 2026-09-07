<x-filament-panels::page>
<style>
.sc-card{border-radius:.75rem;border:1px solid #e5e7eb;background:#fff;overflow:hidden;margin-bottom:1.5rem;}
.sc-header{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-bottom:1px solid #f3f4f6;background:#f9fafb;flex-wrap:wrap;gap:.5rem;}
.sc-title{font-size:.875rem;font-weight:600;color:#1f2937;}
.sc-table{width:100%;border-collapse:collapse;font-size:.875rem;}
.sc-table th{padding:.6rem 1rem;text-align:left;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:1px solid #f3f4f6;white-space:nowrap;}
.sc-table td{padding:.55rem 1rem;border-bottom:1px solid #f9fafb;color:#374151;}
.sc-tab{padding:.4rem 1rem;border:1px solid #d1d5db;border-radius:.5rem;background:#fff;font-size:.85rem;cursor:pointer;}
.sc-tab.active{background:#eef2ff;border-color:#6366f1;color:#4338ca;font-weight:600;}
.sc-badge{display:inline-block;padding:.15rem .5rem;border-radius:.375rem;font-size:.72rem;font-weight:700;}
.sc-late{background:#fee2e2;color:#b91c1c;}
.sc-soon{background:#fef3c7;color:#b45309;}
.sc-ok{background:#dcfce7;color:#15803d;}
.sc-aging{display:flex;gap:1rem;flex-wrap:wrap;padding:.75rem 1.25rem;background:#fafafa;border-bottom:1px solid #f3f4f6;font-size:.8rem;}
.sc-aging b{display:block;font-size:.95rem;}
.sc-note{padding:.5rem 1.25rem;font-size:.75rem;color:#9ca3af;background:#fffbeb;border-bottom:1px solid #fef3c7;}
</style>

<div class="sc-card">
  <div class="sc-header">
    <span class="sc-title">Scadențar — calculat local din facturi vs încasări/plăți</span>
    <span style="display:flex;gap:.5rem;">
      <button wire:click="$set('tab','clienti')" class="sc-tab {{ $this->tab === 'clienti' ? 'active' : '' }}">Clienți (de încasat)</button>
      <button wire:click="$set('tab','furnizori')" class="sc-tab {{ $this->tab === 'furnizori' ? 'active' : '' }}">Furnizori (de plătit)</button>
    </span>
  </div>

  @if($this->tab === 'clienti')
    @php $rows = $this->getFacturiClienti(); $aging = $this->getAgingClienti($rows); @endphp
    <div class="sc-aging">
      <span>Neajunse la scadență<b>{{ number_format($aging['neajunse'],0,',','.') }} lei</b></span>
      <span>1–30 zile<b style="color:#b45309;">{{ number_format($aging['1-30'],0,',','.') }} lei</b></span>
      <span>31–60 zile<b style="color:#c2410c;">{{ number_format($aging['31-60'],0,',','.') }} lei</b></span>
      <span>61–90 zile<b style="color:#b91c1c;">{{ number_format($aging['61-90'],0,',','.') }} lei</b></span>
      <span>peste 90 zile<b style="color:#7f1d1d;">{{ number_format($aging['90+'],0,',','.') }} lei</b></span>
      <span>Total sold<b>{{ number_format(array_sum($aging),0,',','.') }} lei</b></span>
    </div>
    <table class="sc-table">
      <thead><tr>
        <th>Factură</th><th>Client</th><th>Emisă</th><th>Scadență</th><th>Întârziere</th>
        <th style="text-align:right;">Valoare</th><th style="text-align:right;">Încasat</th><th style="text-align:right;">Sold</th>
      </tr></thead>
      <tbody>
      @forelse($rows as $r)
        <tr>
          <td style="font-family:monospace;font-weight:600;">{{ $r->serie }}</td>
          <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            @if($r->partner_url)<a href="{{ $r->partner_url }}" style="color:#4f46e5;text-decoration:none;" class="hover:underline">{{ $r->partner_name }}</a>
            @else {{ $r->partner_name }} @endif
          </td>
          <td style="white-space:nowrap;">{{ $r->emisa ? \Carbon\Carbon::parse($r->emisa)->format('d.m.Y') : '—' }}</td>
          <td style="white-space:nowrap;">{{ $r->scadenta ? \Carbon\Carbon::parse($r->scadenta)->format('d.m.Y') : '—' }}</td>
          <td>
            @if($r->zile === null)<span class="sc-badge">—</span>
            @elseif($r->zile > 0)<span class="sc-badge sc-late">{{ $r->zile }} zile</span>
            @elseif($r->zile > -7)<span class="sc-badge sc-soon">în {{ abs($r->zile) }} zile</span>
            @else <span class="sc-badge sc-ok">în {{ abs($r->zile) }} zile</span>@endif
          </td>
          <td style="text-align:right;">{{ number_format($r->valoare,2,',','.') }}</td>
          <td style="text-align:right;color:#15803d;">{{ number_format($r->incasat,2,',','.') }}</td>
          <td style="text-align:right;font-weight:700;">{{ number_format($r->sold,2,',','.') }}</td>
        </tr>
      @empty
        <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:2rem;">Nicio factură cu sold.</td></tr>
      @endforelse
      </tbody>
    </table>
  @else
    @php $rows = $this->getFacturiFurnizori(); @endphp
    <div class="sc-note">Valoarea facturilor de furnizor e estimată din recepții (ex-TVA × 1,21) — orientativ pentru sold, exact pentru identificarea facturilor neplătite.</div>
    <table class="sc-table">
      <thead><tr>
        <th>Factură furnizor</th><th>Furnizor</th><th>Data recepție</th>
        <th style="text-align:right;">Valoare (est. cu TVA)</th><th style="text-align:right;">Plătit</th><th style="text-align:right;">Sold estimat</th>
      </tr></thead>
      <tbody>
      @forelse($rows as $r)
        <tr>
          <td style="font-family:monospace;font-weight:600;">{{ $r->nr_doc }}</td>
          <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            @if($r->partner_url)<a href="{{ $r->partner_url }}" style="color:#4f46e5;text-decoration:none;" class="hover:underline">{{ $r->partner_name }}</a>
            @else {{ $r->partner_name }} @endif
          </td>
          <td style="white-space:nowrap;">{{ $r->data ? \Carbon\Carbon::parse($r->data)->format('d.m.Y') : '—' }}</td>
          <td style="text-align:right;">{{ number_format($r->valoare,2,',','.') }}</td>
          <td style="text-align:right;color:#15803d;">{{ number_format($r->platit,2,',','.') }}</td>
          <td style="text-align:right;font-weight:700;">{{ number_format($r->sold,2,',','.') }}</td>
        </tr>
      @empty
        <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:2rem;">Nicio factură cu sold.</td></tr>
      @endforelse
      </tbody>
    </table>
  @endif
</div>
</x-filament-panels::page>
