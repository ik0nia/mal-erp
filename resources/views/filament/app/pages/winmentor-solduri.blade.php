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
.sc-late{background:#fee2e2;color:#b91c1c;} .sc-mid{background:#fef3c7;color:#b45309;} .sc-ok{background:#dcfce7;color:#15803d;}
.sc-tot{display:flex;gap:2rem;flex-wrap:wrap;padding:.75rem 1.25rem;background:#fafafa;border-bottom:1px solid #f3f4f6;font-size:.8rem;color:#6b7280;}
.sc-tot b{display:block;font-size:1.05rem;color:#111827;}
</style>

<div class="sc-card">
  <div class="sc-header">
    <span class="sc-title">
      Scadențar — solduri nete per partener, direct din contabilitatea WinMentor
      @if($this->lastSync())<span style="font-weight:400;color:#9ca3af;"> · sincronizat {{ $this->lastSync() }}</span>@endif
    </span>
    <span style="display:flex;gap:.5rem;">
      <button wire:click="$set('tab','clienti')" class="sc-tab {{ $this->tab === 'clienti' ? 'active' : '' }}">Clienți (de încasat)</button>
      <button wire:click="$set('tab','furnizori')" class="sc-tab {{ $this->tab === 'furnizori' ? 'active' : '' }}">Furnizori (de plătit)</button>
    </span>
  </div>

  @php
    $directie = $this->tab === 'clienti' ? 'client' : 'furnizor';
    $data = $this->getSolduri($directie);
  @endphp

  <div class="sc-tot">
    <span>{{ $this->tab === 'clienti' ? 'De încasat (documente < ' . $this->luniOperational . ' luni)' : 'De plătit (documente < ' . $this->luniOperational . ' luni)' }}<b>{{ number_format($data['total_net'], 0, ',', '.') }} lei</b></span>
    <span>Sold istoric necompensat (mai vechi — de verificat în contabilitate)<b style="color:#9ca3af;">{{ number_format($data['total_vechi'], 0, ',', '.') }} lei</b></span>
    <span>{{ $this->tab === 'clienti' ? 'Avansuri / solduri în favoarea clienților' : 'Avansuri plătite furnizorilor (marfă nerecepționată)' }}<b style="color:#b45309;">{{ number_format($data['total_avans'], 0, ',', '.') }} lei</b></span>
    <span>Conturi interne (consum/istoric — clasa Mentor)<b style="color:#9ca3af;">{{ number_format($data['total_interne'] ?? 0, 0, ',', '.') }} lei</b></span>
    <span>Parteneri cu sold<b>{{ count($data['rows']) }}</b></span>
  </div>

  <table class="sc-table">
    <thead><tr>
      <th>Partener</th><th>CUI</th>
      <th style="text-align:right;">Documente</th>
      <th>Cel mai vechi doc. neînchis</th>
      <th>Vechime</th>
      <th style="text-align:right;">Sold recent (lei)</th>
      <th style="text-align:right;">Istoric vechi (lei)</th>
    </tr></thead>
    <tbody>
    @forelse($data['rows'] as $r)
      <tr>
        <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
          @if($r->partner_url)<a href="{{ $r->partner_url }}" style="color:#4f46e5;text-decoration:none;" class="hover:underline">{{ $r->partner_name }}</a>
          @else {{ $r->partner_name }} @endif
        </td>
        <td style="font-family:monospace;font-size:.78rem;color:#9ca3af;">{{ $r->cui ?: '—' }}</td>
        <td style="text-align:right;">{{ $r->docs }}</td>
        <td style="white-space:nowrap;">{{ $r->vechi ? \Carbon\Carbon::parse($r->vechi)->format('d.m.Y') : '—' }}</td>
        <td>
          @if($r->zile_vechime === null)<span class="sc-badge">—</span>
          @elseif($r->zile_vechime > 90)<span class="sc-badge sc-late">{{ $r->zile_vechime }} zile</span>
          @elseif($r->zile_vechime > 30)<span class="sc-badge sc-mid">{{ $r->zile_vechime }} zile</span>
          @else <span class="sc-badge sc-ok">{{ $r->zile_vechime }} zile</span>@endif
        </td>
        <td style="text-align:right;font-weight:700;">{{ number_format($r->net_recent, 2, ',', '.') }}{!! $r->are_eur ? ' <span style="font-size:.65rem;color:#b45309;font-weight:700;">+EUR</span>' : '' !!}</td>
        <td style="text-align:right;color:#9ca3af;">{{ $r->net_vechi != 0 ? number_format($r->net_vechi, 2, ',', '.') : '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem;">Niciun partener cu sold. Rulează winmentor:fetch-solduri.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
</x-filament-panels::page>
