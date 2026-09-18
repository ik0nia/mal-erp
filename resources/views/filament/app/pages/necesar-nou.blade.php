@php $opts = $this->getFilterOptions(); $data = $this->getData(); $luni=['','I','F','M','A','M','I','I','A','S','O','N','D']; @endphp

<x-filament-panels::page>
<div wire:key="necesar-nou" x-data="{
    selected: [],
    itemData: {},
    toggle(pid, sid, qty) {
        const i = this.selected.indexOf(pid);
        if (i > -1) { this.selected.splice(i,1); delete this.itemData[pid]; }
        else { this.selected.push(pid); this.itemData[pid] = { product_id: pid, supplier_id: sid, qty: qty }; }
    },
    has(pid) { return this.selected.includes(pid); }
}">

  {{-- ============ FILTRE ============ --}}
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 18px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.03);">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <div style="position:relative;flex:1;min-width:240px;">
        <span style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:.9rem;">🔎</span>
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Caută produs, SKU sau cod furnizor…"
               style="width:100%;padding:9px 12px 9px 34px;border:1px solid #d1d5db;border-radius:9px;font-size:.9rem;outline:none;">
      </div>
      <select wire:model.live="supplierId" style="padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.85rem;max-width:230px;background:#fff;">
        <option value="">Toți furnizorii</option>
        @foreach($opts['suppliers'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
      </select>
      <select wire:model.live="categoryId" style="padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.85rem;max-width:230px;background:#fff;">
        <option value="">Toate categoriile</option>
        @foreach($opts['categories'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
      </select>
    </div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-top:12px;">
      @php $segStyle = fn($active) => 'padding:7px 14px;font-size:.8rem;font-weight:600;border:none;cursor:pointer;'.($active?'background:#111827;color:#fff;':'background:#fff;color:#374151;'); @endphp
      {{-- urgență --}}
      <div style="display:flex;align-items:center;gap:7px;">
        <span style="font-size:.72rem;color:#9ca3af;font-weight:700;text-transform:uppercase;">Stoc</span>
        <div style="display:inline-flex;border:1px solid #d1d5db;border-radius:9px;overflow:hidden;">
          @foreach(['all'=>'Toate','zero'=>'0','d7'=>'Sub 7z','d14'=>'Sub 14z'] as $k=>$lbl)
            <button wire:click="$set('urgency','{{ $k }}')" style="{{ $segStyle($urgency===$k) }}{{ !$loop->last?'border-right:1px solid #e5e7eb;':'' }}">{{ $lbl }}</button>
          @endforeach
        </div>
      </div>
      {{-- PO deschis --}}
      <div style="display:flex;align-items:center;gap:7px;">
        <span style="font-size:.72rem;color:#9ca3af;font-weight:700;text-transform:uppercase;">Comandă</span>
        <div style="display:inline-flex;border:1px solid #d1d5db;border-radius:9px;overflow:hidden;">
          @foreach(['all'=>'Toate','with'=>'Are PO deschis','without'=>'Fără PO'] as $k=>$lbl)
            <button wire:click="$set('poState','{{ $k }}')" style="{{ $segStyle($poState===$k) }}{{ !$loop->last?'border-right:1px solid #e5e7eb;':'' }}">{{ $lbl }}</button>
          @endforeach
        </div>
      </div>
      <label style="display:inline-flex;align-items:center;gap:7px;font-size:.82rem;color:#374151;cursor:pointer;">
        <input type="checkbox" wire:model.live="onlyNeeded" style="width:16px;height:16px;accent-color:#dc2626;"> Doar ce trebuie comandat
      </label>
      <button wire:click="resetFilters" style="font-size:.78rem;color:#6b7280;background:none;border:none;text-decoration:underline;cursor:pointer;margin-left:auto;">Șterge filtrele</button>
    </div>
  </div>

  {{-- ============ SUMAR + LEGENDĂ ============ --}}
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 4px 10px;">
    <span style="font-size:1.15rem;font-weight:800;color:#dc2626;">{{ $data['to_order'] }}</span>
    <span style="font-size:.9rem;color:#6b7280;">produse de comandat</span>
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-left:auto;font-size:.7rem;color:#9ca3af;">
      <span><b style="color:#6b7280;">Sezon:</b>
        <span style="display:inline-block;width:8px;height:10px;background:#cbd5e1;border-radius:2px;vertical-align:-1px;"></span> anul
        <span style="display:inline-block;width:8px;height:10px;background:#2563eb;border-radius:2px;vertical-align:-1px;margin-left:4px;"></span> sosire marfă
        <span style="display:inline-block;width:8px;height:10px;border:2px solid #111827;border-radius:2px;vertical-align:-1px;box-sizing:border-box;margin-left:4px;"></span> luna curentă</span>
      <span><b style="color:#6b7280;">Vânzări:</b> <span style="color:#15803d;">▲</span>/<span style="color:#dc2626;">▼</span> trend</span>
    </div>
  </div>

  {{-- ============ TABEL ============ --}}
  @if(empty($data['rows']))
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:36px;text-align:center;color:#15803d;font-weight:600;">✓ Nimic de comandat cu filtrele curente.</div>
  @else
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04);">
    <table style="width:100%;border-collapse:collapse;font-size:.86rem;">
      <thead>
        <tr>
          @php $th='padding:11px 14px;font-size:.66rem;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;border-bottom:1px solid #eef0f2;font-weight:700;background:#fafbfc;'; @endphp
          <th style="{{ $th }}width:38px;text-align:center;"></th>
          <th style="{{ $th }}text-align:left;">Produs</th>
          <th style="{{ $th }}text-align:center;" title="Ritm de vânzare (buc/zi) + cât s-a vândut recent">Vânzări</th>
          <th style="{{ $th }}text-align:center;">Sezon</th>
          <th style="{{ $th }}text-align:center;">Stoc</th>
          <th style="{{ $th }}text-align:right;">De comandat</th>
        </tr>
      </thead>
      <tbody>
      @foreach($data['rows'] as $i => $p)
        @php
          $d = $p['days'];
          $dCol = $d===null ? '#9ca3af' : ($d<7 ? '#dc2626' : ($d<14 ? '#ea580c' : '#16a34a'));
          $win = []; if(!empty($p['win_start']) && !empty($p['win_end'])){ $m=$p['win_start']; for($k=0;$k<13;$k++){ $win[$m]=true; if($m===$p['win_end']) break; $m=$m%12+1; } }
          $qtr=[1=>'I',4=>'A',7=>'I',10=>'O'];
          $poUrl = $p['open_po_id'] ? \App\Filament\App\Resources\PurchaseOrderResource::getUrl('view',['record'=>$p['open_po_id']]) : null;
        @endphp
        <tr :style="has({{ $p['id'] }}) ? 'background:#eff6ff;' : '{{ $i%2 ? 'background:#fcfcfd;' : '' }}'" style="border-bottom:1px solid #f3f4f6;">
          {{-- select --}}
          <td style="text-align:center;vertical-align:middle;padding:0 0 0 6px;">
            <input type="checkbox" :checked="has({{ $p['id'] }})" @change="toggle({{ $p['id'] }}, {{ $p['supplier_id'] }}, {{ $p['qty'] }})"
                   style="width:16px;height:16px;accent-color:#dc2626;cursor:pointer;">
          </td>
          {{-- produs --}}
          <td style="padding:11px 14px;vertical-align:middle;max-width:360px;">
            <div style="font-weight:600;color:#111827;line-height:1.25;">{{ $p['name'] }}</div>
            <div style="font-size:.72rem;color:#9ca3af;margin-top:2px;">{{ $p['sku'] }}@if($p['brand']) · {{ $p['brand'] }}@endif · 🏭 {{ $p['supplier'] }}</div>
            <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:5px;align-items:center;">
              @foreach($p['cues'] as $c)<span style="font-size:.7rem;color:#4b5563;">{{ $c['i'] }} {{ $c['t'] }}</span>@endforeach
              @if($p['open_po_id'])
                <a href="{{ $poUrl }}" target="_blank" style="font-size:.7rem;font-weight:700;color:#1d4ed8;background:#dbeafe;padding:1px 8px;border-radius:999px;text-decoration:none;" title="Există deja o comandă deschisă nerecepționată">
                  📦 în {{ $p['open_po_number'] }} · {{ number_format($p['open_po_qty'],0,'.','') }} buc
                  @if($p['open_po_date']) · {{ \Illuminate\Support\Carbon::parse($p['open_po_date'])->format('d.m') }} @endif
                </a>
              @endif
            </div>
          </td>
          {{-- vanzari --}}
          <td style="text-align:center;padding:11px 14px;white-space:nowrap;vertical-align:middle;">
            <div style="font-weight:800;color:#111827;font-size:1.1rem;line-height:1;">
              {{ number_format($p['sold30'],0,'.','') }}<span style="font-size:.64rem;color:#9ca3af;font-weight:600;"> /lună</span>
              @if($p['vtrend']>0)<span style="color:#15803d;font-size:.85rem;" title="ritm în creștere">▲</span>@elseif($p['vtrend']<0)<span style="color:#dc2626;font-size:.85rem;" title="ritm în scădere">▼</span>@endif
            </div>
            <div style="font-size:.7rem;color:#9ca3af;margin-top:4px;">{{ round($p['sold7']) }} buc în ultima săptămână</div>
          </td>
          {{-- sezon --}}
          <td style="text-align:center;padding:11px 14px;vertical-align:middle;">
            @if($p['curve'])
              @php $sf=$p['season']; if($sf>1.08){$sw='↑ intră în sezon';$swc='#15803d';}elseif($sf<0.92){$sw='↓ iese din sezon';$swc='#0891b2';}else{$sw='constant';$swc='#9ca3af';} @endphp
              <div style="font-size:.7rem;font-weight:700;color:{{ $swc }};margin-bottom:5px;">{{ $sw }}</div>
              <div style="display:inline-block;" title="Cum se vinde categoria pe an. Contur negru = luna curentă · albastru = când sosește marfa.">
                <div style="display:flex;align-items:flex-end;gap:2px;height:30px;">
                  @foreach(range(1,12) as $m)
                    @php $val=$p['curve'][$m]??1; $h=max(4,(int)round(min($val,2.5)/2.5*28)); $bg=isset($win[$m])?'#2563eb':'#cbd5e1'; @endphp
                    <div style="width:8px;height:{{ $h }}px;background:{{ $bg }};border-radius:2px 2px 0 0;{{ $m===$p['cur_month']?'outline:2px solid #111827;outline-offset:1px;':'' }}"></div>
                  @endforeach
                </div>
                <div style="display:flex;gap:2px;margin-top:5px;">
                  @foreach(range(1,12) as $m)<span style="width:8px;font-size:7.5px;color:#9ca3af;text-align:center;">{{ $qtr[$m] ?? '' }}</span>@endforeach
                </div>
              </div>
            @else <span style="color:#e5e7eb;">—</span> @endif
          </td>
          {{-- stoc --}}
          <td style="text-align:center;padding:11px 12px;white-space:nowrap;vertical-align:middle;">
            <div style="font-weight:700;color:#374151;">{{ number_format($p['stock'],0,'.','') }}</div>
            @if($d!==null)<div style="font-size:.72rem;font-weight:700;color:{{ $dCol }};margin-top:1px;">{{ round($d) }} zile</div>@endif
          </td>
          {{-- de comandat --}}
          <td style="text-align:right;padding:11px 16px;white-space:nowrap;vertical-align:middle;">
            <div style="font-size:1.35rem;font-weight:800;color:#111827;line-height:1;">{{ number_format($p['qty'],0,'.','') }}<span style="font-size:.72rem;font-weight:600;color:#9ca3af;"> buc</span></div>
            @if($p['purchase_uom'] && $p['purchase_qty'])<div style="font-size:.74rem;color:#1d4ed8;font-weight:700;margin-top:2px;">≈ {{ $p['purchase_qty'] }} {{ $p['purchase_uom'] }}</div>@endif
            @if($p['est_value'])<div style="font-size:.82rem;color:#15803d;font-weight:800;margin-top:3px;">≈ {{ number_format($p['est_value'],0,',','.') }} lei</div>@endif
            <div style="font-size:.66rem;color:#9ca3af;margin-top:2px;">livrare ~{{ $p['lead'] }} {{ (int)$p['lead']===1?'zi':'zile' }}</div>
            @if(isset($p['confidence']) && $p['confidence'] < 0.7)<div style="font-size:.66rem;color:#b45309;margin-top:1px;" title="Date parțiale">⚠ {{ (int) round($p['confidence']*100) }}%</div>@endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  @endif

  {{-- ============ BARĂ ACȚIUNI (sticky, la selecție) ============ --}}
  <div x-show="selected.length > 0" x-cloak x-transition
       style="position:sticky;bottom:16px;margin-top:16px;display:flex;align-items:center;gap:14px;background:#111827;color:#fff;border-radius:14px;padding:13px 20px;box-shadow:0 8px 24px rgba(0,0,0,.22);">
    <span style="font-weight:700;"><span x-text="selected.length"></span> selectate</span>
    <button @click="selected=[]; itemData={}" style="font-size:.8rem;color:#cbd5e1;background:none;border:none;text-decoration:underline;cursor:pointer;">deselectează</button>
    <div style="margin-left:auto;display:flex;gap:10px;">
      <button @click="$wire.simulateOrders(selected.map(p=>itemData[p]))" wire:loading.attr="disabled"
              style="background:#374151;color:#fff;font-weight:700;font-size:.85rem;padding:9px 16px;border:none;border-radius:9px;cursor:pointer;">Simulează comanda</button>
      <button @click="$wire.createNecesarFromSelection(selected.map(p=>itemData[p])); selected=[]; itemData={}" wire:loading.attr="disabled"
              style="background:#dc2626;color:#fff;font-weight:700;font-size:.85rem;padding:9px 18px;border:none;border-radius:9px;cursor:pointer;">Adaugă la necesar →</button>
    </div>
  </div>

  <div style="margin-top:14px;font-size:.72rem;color:#9ca3af;text-align:center;line-height:1.5;">
    „De comandat" ține cont automat de: viteza reală de vânzare, lead time-ul furnizorului, sezonul în care va sosi marfa, stocul curent și comenzile deja pe drum (PO deschise).<br>Pilot — vizibil doar pentru tine.
  </div>

</div>
</x-filament-panels::page>
