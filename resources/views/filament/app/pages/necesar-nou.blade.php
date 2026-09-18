@php $opts = $this->getFilterOptions(); $data = $this->getData(); @endphp

<x-filament-panels::page>
<div style="max-width:1080px;" wire:key="necesar-nou">

  {{-- ============ FILTRE ============ --}}
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 16px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.03);">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      {{-- căutare --}}
      <div style="position:relative;flex:1;min-width:200px;">
        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;">🔎</span>
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Caută produs, SKU sau cod furnizor…"
               style="width:100%;padding:9px 12px 9px 32px;border:1px solid #d1d5db;border-radius:9px;font-size:.9rem;outline:none;">
      </div>
      {{-- furnizor --}}
      <select wire:model.live="supplierId" style="padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.85rem;max-width:200px;background:#fff;">
        <option value="">Toți furnizorii</option>
        @foreach($opts['suppliers'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
      </select>
      {{-- categorie --}}
      <select wire:model.live="categoryId" style="padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.85rem;max-width:200px;background:#fff;">
        <option value="">Toate categoriile</option>
        @foreach($opts['categories'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
      </select>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:11px;">
      {{-- urgență segmentat --}}
      @php $segs = ['all'=>'Toate','zero'=>'Stoc 0','d7'=>'Sub 7 zile','d14'=>'Sub 14 zile']; @endphp
      <div style="display:inline-flex;border:1px solid #d1d5db;border-radius:9px;overflow:hidden;">
        @foreach($segs as $k => $lbl)
          <button wire:click="$set('urgency','{{ $k }}')"
                  style="padding:7px 13px;font-size:.8rem;font-weight:600;border:none;cursor:pointer;{{ $urgency===$k ? 'background:#111827;color:#fff;' : 'background:#fff;color:#374151;' }}{{ !$loop->last ? 'border-right:1px solid #e5e7eb;' : '' }}">
            {{ $lbl }}
          </button>
        @endforeach
      </div>
      {{-- doar necesar --}}
      <label style="display:inline-flex;align-items:center;gap:7px;font-size:.82rem;color:#374151;cursor:pointer;">
        <input type="checkbox" wire:model.live="onlyNeeded" style="width:16px;height:16px;accent-color:#dc2626;">
        Doar ce trebuie comandat
      </label>
      <button wire:click="resetFilters" style="font-size:.78rem;color:#6b7280;background:none;border:none;text-decoration:underline;cursor:pointer;margin-left:auto;">
        Șterge filtrele
      </button>
    </div>
  </div>

  {{-- ============ SUMAR ============ --}}
  <div style="display:flex;align-items:baseline;gap:8px;margin:0 4px 12px;">
    <span style="font-size:1.05rem;font-weight:800;color:#111827;">{{ $data['to_order'] }}</span>
    <span style="font-size:.9rem;color:#6b7280;">produse de comandat</span>
    @if($data['total'] > count($data['rows']))
      <span style="font-size:.78rem;color:#9ca3af;margin-left:auto;">se afișează primele {{ count($data['rows']) }} din {{ $data['total'] }}</span>
    @endif
  </div>

  {{-- ============ LISTĂ ============ --}}
  @if(empty($data['rows']))
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:30px;text-align:center;color:#15803d;font-weight:600;">
      ✓ Nimic de comandat cu filtrele curente.
    </div>
  @else
    <div style="display:flex;flex-direction:column;gap:9px;">
      @foreach($data['rows'] as $p)
        @php
          // culoarea zilelor de stoc
          $d = $p['days'];
          $dCol = $d===null ? '#9ca3af' : ($d<7 ? '#dc2626' : ($d<14 ? '#ea580c' : '#16a34a'));
          // luni din fereastra de livrare (poate depăși anul)
          $win = [];
          if($p['win_start'] && $p['win_end']){
            $m=$p['win_start']; for($i=0;$i<13;$i++){ $win[$m]=true; if($m===$p['win_end']) break; $m=$m%12+1; }
          }
          $luni=['','I','F','M','A','M','I','I','A','S','O','N','D'];
        @endphp
        <div style="display:flex;align-items:center;gap:16px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 16px;box-shadow:0 1px 2px rgba(0,0,0,.03);">

          {{-- produs --}}
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;color:#111827;font-size:.95rem;">{{ $p['name'] }}</div>
            <div style="font-size:.75rem;color:#9ca3af;margin-top:1px;">
              {{ $p['sku'] }}@if($p['brand']) · {{ $p['brand'] }}@endif · 🏭 {{ $p['supplier'] }}
            </div>
            {{-- indicii simple --}}
            @if($p['cues'])
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:5px;">
                @foreach($p['cues'] as $cue)
                  <span style="font-size:.72rem;color:#4b5563;display:inline-flex;align-items:center;gap:3px;">{{ $cue['i'] }} {{ $cue['t'] }}</span>
                @endforeach
              </div>
            @endif
          </div>

          {{-- mini-grafic sezon --}}
          @if($p['curve'])
            <div style="flex:0 0 auto;text-align:center;" title="Cum se vinde categoria pe parcursul anului. Marcat: luna curentă (contur) și fereastra când sosește marfa (albastru).">
              <div style="display:flex;align-items:flex-end;gap:1px;height:30px;">
                @foreach(range(1,12) as $m)
                  @php
                    $val = $p['curve'][$m] ?? 1; $h = max(3, (int) round(min($val,2.5)/2.5*28));
                    $isCur = $m===$p['cur_month']; $inWin = isset($win[$m]);
                    $bg = $inWin ? '#2563eb' : '#cbd5e1';
                  @endphp
                  <div style="width:6px;height:{{ $h }}px;background:{{ $bg }};border-radius:2px 2px 0 0;{{ $isCur ? 'outline:1.5px solid #111827;outline-offset:1px;' : '' }}"></div>
                @endforeach
              </div>
              <div style="display:flex;gap:1px;margin-top:2px;">
                @foreach(range(1,12) as $m)
                  <span style="width:6px;font-size:6.5px;color:{{ $m===$p['cur_month'] ? '#111827' : '#cbd5e1' }};text-align:center;font-weight:{{ $m===$p['cur_month']?'700':'400' }};">{{ $luni[$m] }}</span>
                @endforeach
              </div>
            </div>
          @endif

          {{-- stoc + zile --}}
          <div style="flex:0 0 auto;text-align:center;min-width:74px;">
            <div style="font-size:.7rem;color:#9ca3af;">stoc</div>
            <div style="font-weight:600;color:#374151;">{{ number_format($p['stock'],0,'.','') }}</div>
            @if($d!==null)
              <div style="font-size:.72rem;font-weight:700;color:{{ $dCol }};margin-top:1px;">{{ round($d) }} zile</div>
            @endif
          </div>

          {{-- de comandat --}}
          <div style="flex:0 0 auto;text-align:right;min-width:118px;">
            <div style="font-size:.68rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;">De comandat</div>
            <div style="font-size:1.35rem;font-weight:800;color:#111827;line-height:1.1;">
              {{ number_format($p['qty'],0,'.','') }}
              <span style="font-size:.8rem;font-weight:600;color:#6b7280;">buc</span>
            </div>
            @if($p['purchase_uom'] && $p['purchase_qty'])
              <div style="font-size:.75rem;color:#1d4ed8;font-weight:700;">≈ {{ $p['purchase_qty'] }} {{ $p['purchase_uom'] }}</div>
            @endif
            @if(isset($p['confidence']) && $p['confidence'] < 0.7)
              <div style="font-size:.68rem;color:#b45309;" title="Recomandare cu date parțiale">⚠ încredere {{ (int) round($p['confidence']*100) }}%</div>
            @endif
          </div>

        </div>
      @endforeach
    </div>
  @endif

  <div style="margin-top:14px;font-size:.72rem;color:#9ca3af;text-align:center;">
    Cantitatea ține cont automat de: viteza reală de vânzare, lead time-ul furnizorului, sezonul în care va sosi marfa, stocul curent și comenzile deja pe drum. Pilot — vizibil doar pentru tine.
  </div>

</div>
</x-filament-panels::page>
