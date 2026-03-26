<x-filament-panels::page>
@php
$urgentAllItems = $this->urgentProducts->map(fn($p) => [
    'product_id' => (int)$p->product_id,
    'supplier_id' => (int)$p->supplier_id,
    'qty'        => max(1, (int)($p->recommended_qty ?? 1)),
])->values()->all();
$soonAllItems = $this->soonProducts->map(fn($p) => [
    'product_id' => (int)$p->product_id,
    'supplier_id' => (int)$p->supplier_id,
    'qty'        => max(1, (int)($p->recommended_qty ?? 1)),
])->values()->all();
@endphp
<div x-data="{
    selected: [],
    itemData: {},
    activeTab: 'urgent',
    urgentItems: {{ Illuminate\Support\Js::from($urgentAllItems) }},
    soonItems:   {{ Illuminate\Support\Js::from($soonAllItems) }},
    allSelected(items) {
        return items.length > 0 && items.every(i => this.selected.includes(i.product_id));
    },
    toggleAll(items) {
        if (this.allSelected(items)) {
            items.forEach(i => {
                let idx = this.selected.indexOf(i.product_id);
                if (idx > -1) this.selected.splice(idx, 1);
                delete this.itemData[i.product_id];
            });
        } else {
            items.forEach(i => {
                if (!this.selected.includes(i.product_id)) {
                    this.selected.push(i.product_id);
                    this.itemData[i.product_id] = i;
                }
            });
        }
    }
}">

    {{-- Stat cards + filtru acoperire --}}
    <div style="display:flex;flex-wrap:wrap;align-items:stretch;gap:12px;margin-bottom:20px;">
        <div style="flex:1;min-width:160px;border-radius:12px;border:1px solid #e5e7eb;background-color:#ffffff;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
            <div style="padding:20px 24px;">
                <p style="font-size:0.875rem;color:#6b7280;">Furnizori afectați</p>
                <p style="margin-top:4px;font-size:1.5rem;font-weight:700;color:#111827;">{{ $this->statSuppliers }}</p>
            </div>
        </div>
        <div style="flex:1;min-width:160px;border-radius:12px;border:1px solid #fde68a;background-color:#fff7ed;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
            <div style="padding:20px 24px;">
                <p style="font-size:0.875rem;color:#c2410c;">Produse afectate</p>
                <p style="margin-top:4px;font-size:1.5rem;font-weight:700;color:#c2410c;">{{ $this->statProducts }}</p>
            </div>
        </div>
        <div style="flex:1;min-width:160px;border-radius:12px;border:1px solid #fecaca;background-color:#fef2f2;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
            <div style="padding:20px 24px;">
                <p style="font-size:0.875rem;color:#b91c1c;">Stoc zero</p>
                <p style="margin-top:4px;font-size:1.5rem;font-weight:700;color:#b91c1c;">{{ $this->statZeroStock }}</p>
            </div>
        </div>
        <div style="flex:1;min-width:160px;border-radius:12px;border:1px solid #fca5a5;background-color:#fee2e2;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
            <div style="padding:20px 24px;">
                <p style="font-size:0.875rem;font-weight:500;color:#991b1b;">Urgente (&lt;7 zile)</p>
                <p style="margin-top:4px;font-size:1.5rem;font-weight:700;color:#991b1b;">{{ $this->statUrgent }}</p>
            </div>
        </div>
        <div style="flex:1;min-width:160px;border-radius:12px;border:1px solid #fdba74;background-color:#ffedd5;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
            <div style="padding:20px 24px;">
                <p style="font-size:0.875rem;font-weight:500;color:#9a3412;">Curând (7–14 zile)</p>
                <p style="margin-top:4px;font-size:1.5rem;font-weight:700;color:#9a3412;">{{ $this->statSoon }}</p>
            </div>
        </div>

        <div style="flex:1;min-width:160px;border-radius:12px;border:1px solid #e5e7eb;background-color:#ffffff;overflow:hidden;margin-left:auto;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
            <div style="display:flex;align-items:center;gap:12px;padding:20px 24px;">
                <label style="font-size:0.875rem;font-weight:500;color:#374151;white-space:nowrap;">
                    Acoperire comandă (zile):
                </label>
                <input
                    type="number" min="7" max="60"
                    wire:model.live.debounce.500ms="coverDays"
                    style="width:5rem;border-radius:8px;border:1px solid #d1d5db;background-color:#ffffff;padding:6px 12px;font-size:0.875rem;text-align:center;outline:none;"
                />
                <span style="font-size:0.75rem;color:#9ca3af;">zile</span>
            </div>
        </div>
    </div>

    {{-- Banner selecție + buton Creează necesar --}}
    <div x-show="selected.length > 0" x-cloak x-transition
         style="display:flex;align-items:center;justify-content:space-between;border-radius:12px;border:1px solid #e8c8c8;background-color:#fdf2f2;padding:12px 20px;margin:16px 0;">
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:0.875rem;font-weight:600;color:#8B1A1A;">
                <span x-text="selected.length"></span> poziții selectate
            </span>
            <button @click="selected = []; itemData = {}"
                    style="font-size:0.75rem;color:#8B1A1A;text-decoration:underline;background:none;border:none;cursor:pointer;">
                Deselectează tot
            </button>
        </div>
        <button
            @click="$wire.createNecesarFromSelection(selected.map(pid => itemData[pid]))"
            wire:loading.attr="disabled"
            style="display:inline-flex;align-items:center;gap:8px;border-radius:8px;background-color:#8B1A1A;padding:8px 16px;font-size:0.875rem;font-weight:600;color:#ffffff;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:none;cursor:pointer;">
            <svg wire:loading wire:target="createNecesarFromSelection" style="height:16px;width:16px;animation:spin 1s linear infinite;" fill="none" viewBox="0 0 24 24">
                <circle style="opacity:0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path style="opacity:0.75;" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
            <svg wire:loading.remove wire:target="createNecesarFromSelection" style="height:16px;width:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            Creează necesar
        </button>
    </div>

    {{-- Tab Navigation --}}
    <div style="display:flex;border-radius:12px;background-color:#f3f4f6;padding:4px;gap:8px;margin-bottom:16px;">
        <button @click="activeTab = 'urgent'"
                style="flex:1;display:flex;align-items:center;justify-content:center;gap:8px;border-radius:8px;padding:8px 20px;font-size:0.875rem;font-weight:600;border:none;cursor:pointer;transition:all 0.2s;"
                :style="activeTab === 'urgent'
                    ? 'background-color:#ffffff;box-shadow:0 1px 3px rgba(0,0,0,0.1);color:#b91c1c;'
                    : 'background-color:transparent;color:#6b7280;'">
            <span>🔴 Epuizare &lt;7 zile</span>
            <span style="border-radius:9999px;padding:2px 8px;font-size:0.75rem;font-weight:700;background-color:#fee2e2;color:#b91c1c;">
                {{ $this->statUrgent }}
            </span>
        </button>
        <button @click="activeTab = 'soon'"
                style="flex:1;display:flex;align-items:center;justify-content:center;gap:8px;border-radius:8px;padding:8px 20px;font-size:0.875rem;font-weight:600;border:none;cursor:pointer;transition:all 0.2s;"
                :style="activeTab === 'soon'
                    ? 'background-color:#ffffff;box-shadow:0 1px 3px rgba(0,0,0,0.1);color:#c2410c;'
                    : 'background-color:transparent;color:#6b7280;'">
            <span>⚠️ Epuizare 7–14 zile</span>
            <span style="border-radius:9999px;padding:2px 8px;font-size:0.75rem;font-weight:700;background-color:#ffedd5;color:#c2410c;">
                {{ $this->statSoon }}
            </span>
        </button>
        <button @click="activeTab = 'supplier'"
                style="flex:1;display:flex;align-items:center;justify-content:center;gap:8px;border-radius:8px;padding:8px 20px;font-size:0.875rem;font-weight:600;border:none;cursor:pointer;transition:all 0.2s;"
                :style="activeTab === 'supplier'
                    ? 'background-color:#ffffff;box-shadow:0 1px 3px rgba(0,0,0,0.1);color:#8B1A1A;'
                    : 'background-color:transparent;color:#6b7280;'">
            <span>📦 Situație / Furnizor</span>
        </button>
    </div>

    {{-- ===== TAB 1: URGENȚE <7 ZILE ===== --}}
    <div x-show="activeTab === 'urgent'">
        @if($this->urgentProducts->isEmpty())
            <div style="border-radius:12px;border:1px solid #bbf7d0;background-color:#f0fdf4;padding:24px;text-align:center;">
                <x-filament::icon icon="heroicon-o-check-circle" style="margin:0 auto;height:32px;width:32px;color:#22c55e;" />
                <p style="margin-top:8px;color:#16a34a;font-weight:500;">Niciun produs cu stoc critic și mișcare recentă.</p>
            </div>
        @else
            <div style="overflow:hidden;border-radius:12px;border:1px solid #fecaca;background-color:#ffffff;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                <table style="width:100%;font-size:1rem;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid #f3f4f6;background-color:#f8f9fa;font-size:0.75rem;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:#374151;">
                            <th style="width:40px;padding:10px 12px;" title="Selectează / deselectează tot">
                                <div style="margin:0 auto;display:flex;height:20px;width:20px;align-items:center;justify-content:center;border-radius:9999px;border:2px solid #d1d5db;cursor:pointer;transition:all 0.2s;"
                                     @click="toggleAll(urgentItems)"
                                     :style="allSelected(urgentItems) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                    <svg x-show="allSelected(urgentItems)" style="height:12px;width:12px;color:#ffffff;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            </th>
                            <th style="padding:10px 12px;text-align:left;">Furnizor</th>
                            <th style="padding:10px 12px;text-align:left;">Denumire produs</th>
                            <th style="padding:10px 12px;text-align:right;" title="Cantitate recomandată pentru {{ $this->coverDays }} zile acoperire + 3 zile safety stock, ajustată cu trendul cererii">De comandat</th>
                            <th style="padding:10px 12px;text-align:right;" title="Câte zile mai durează stocul la viteza actuală de vânzare">Zile până la epuizare</th>
                            <th style="padding:10px 12px;text-align:right;">Stoc actual</th>
                            <th style="padding:10px 12px;text-align:right;" title="Cantitate vândută în ultimele 7 zile">Vândut 7 zile</th>
                            <th style="padding:10px 12px;text-align:right;" title="Medie zilnică ajustată cu trendul recent (7z vs 30z). ↑ cerere în creștere, ↓ cerere în scădere">Viteză estimată/zi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->urgentProducts as $product)
                            @php
                                $days       = $product->days_until_stockout;
                                $adj        = $product->adjusted_daily ?? 0;
                                $noSupplier = (int)$product->supplier_id === 0;
                                $pid        = (int)$product->product_id;
                                $qty        = max(1, (int)($product->recommended_qty ?? 1));
                                $sid        = (int)$product->supplier_id;
                            @endphp
                            <tr @click="selected.includes({{ $pid }})
                                    ? (selected.splice(selected.indexOf({{ $pid }}), 1), delete itemData[{{ $pid }}])
                                    : (selected.push({{ $pid }}), itemData[{{ $pid }}] = { product_id: {{ $pid }}, supplier_id: {{ $sid }}, qty: {{ $qty }} })"
                                :style="selected.includes({{ $pid }}) ? 'background-color:#dcfce7;cursor:pointer;transition:background-color 0.2s;border-bottom:1px solid #f3f4f6;' : 'cursor:pointer;transition:background-color 0.2s;border-bottom:1px solid #f3f4f6;'"
                                onmouseover="if(!this.__alpine_selected) this.style.backgroundColor='#fff5f5';"
                                onmouseout="if(!this.__alpine_selected) this.style.backgroundColor='';">
                                <td style="padding:10px 12px;">
                                    <div style="margin:0 auto;display:flex;height:20px;width:20px;align-items:center;justify-content:center;border-radius:9999px;border:2px solid #d1d5db;transition:all 0.2s;"
                                         :style="selected.includes({{ $pid }}) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                        <svg x-show="selected.includes({{ $pid }})" style="height:12px;width:12px;color:#ffffff;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                </td>
                                <td style="padding:10px 12px;font-weight:500;color:#374151;white-space:nowrap;">
                                    @if($noSupplier)
                                        <span style="display:inline-flex;align-items:center;border-radius:9999px;background-color:#f3f4f6;padding:2px 8px;font-size:0.75rem;font-weight:500;color:#6b7280;">N/A</span>
                                    @else
                                        {{ $product->supplier_name }}
                                    @endif
                                </td>
                                <td style="padding:10px 12px;">
                                    <span style="font-weight:500;color:#111827;">{{ $product->name }}</span>
                                    @if($product->brand)
                                        <span style="color:#6b7280;"> ({{ $product->brand }})</span>
                                    @endif
                                    @if($product->sku)
                                        <span style="display:block;font-family:monospace;font-size:0.75rem;color:#9ca3af;margin-top:2px;">{{ $product->sku }}</span>
                                    @endif
                                </td>
                                <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">
                                    @if($product->recommended_qty > 0)
                                        <span style="font-weight:700;color:#111827;">{{ number_format($product->recommended_qty, 0, '.', '') }}</span>
                                        <span style="margin-left:4px;font-size:0.875rem;color:#9ca3af;">{{ $product->unit ?? 'buc' }}</span>
                                    @else
                                        <span style="color:#9ca3af;">—</span>
                                    @endif
                                </td>
                                <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;font-weight:600;">
                                    @if($days === null)
                                        <span style="color:#9ca3af;">—</span>
                                    @elseif($days < 1)
                                        <span style="color:#dc2626;">&lt;1 zi 🔴</span>
                                    @else
                                        <span style="color:#dc2626;">~{{ round($days) }} {{ round($days) == 1 ? 'zi' : 'zile' }} 🔴</span>
                                    @endif
                                </td>
                                <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">
                                    @if((float)$product->stock <= 0)
                                        <span style="display:inline-flex;align-items:center;border-radius:9999px;background-color:#fee2e2;padding:2px 8px;font-size:0.875rem;font-weight:600;color:#b91c1c;">{{ number_format((float)$product->stock, 0, '.', '') }}</span>
                                    @else
                                        <span style="display:inline-flex;align-items:center;border-radius:9999px;background-color:#ffedd5;padding:2px 8px;font-size:0.875rem;font-weight:600;color:#c2410c;">{{ number_format((float)$product->stock, 0, '.', '') }}</span>
                                    @endif
                                </td>
                                <td style="padding:10px 12px;text-align:right;color:#4b5563;font-variant-numeric:tabular-nums;">
                                    {{ $product->consumed_7d > 0 ? number_format($product->consumed_7d, $product->consumed_7d < 10 ? 1 : 0, '.', '') : '—' }}
                                </td>
                                <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;font-weight:500;">
                                    @if($adj > 0)
                                        <span style="color:{{ ($product->trend_direction ?? 0) > 0 ? '#dc2626' : (($product->trend_direction ?? 0) < 0 ? '#16a34a' : '#8B1A1A') }};">
                                            {{ number_format($adj, $adj < 10 ? 2 : 1, '.', '') }}
                                            @if(($product->trend_direction ?? 0) > 0) ↑@elseif(($product->trend_direction ?? 0) < 0) ↓@endif
                                        </span>
                                    @else
                                        <span style="color:#9ca3af;">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p style="padding:10px 12px;text-align:right;font-size:0.75rem;color:#9ca3af;border-top:1px solid #f3f4f6;">
                    * De comandat = max(avg7z, avg30z, avg90z) × {{ $this->coverDays }}z + safety 3z. ↑/↓ = cerere în creștere/scădere față de medie.
                </p>
            </div>
        @endif
    </div>

    {{-- ===== TAB 2: CURÂND 7–14 ZILE ===== --}}
    <div x-show="activeTab === 'soon'">
        @if($this->soonProducts->isEmpty())
            <div style="border-radius:12px;border:1px solid #bbf7d0;background-color:#f0fdf4;padding:24px;text-align:center;">
                <x-filament::icon icon="heroicon-o-check-circle" style="margin:0 auto;height:32px;width:32px;color:#22c55e;" />
                <p style="margin-top:8px;color:#16a34a;font-weight:500;">Niciun produs cu epuizare iminentă în 7–14 zile.</p>
            </div>
        @else
            <div style="overflow:hidden;border-radius:12px;border:1px solid #fde68a;background-color:#ffffff;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                <table style="width:100%;font-size:1rem;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid #f3f4f6;background-color:#f8f9fa;font-size:0.75rem;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:#374151;">
                            <th style="width:40px;padding:10px 12px;" title="Selectează / deselectează tot">
                                <div style="margin:0 auto;display:flex;height:20px;width:20px;align-items:center;justify-content:center;border-radius:9999px;border:2px solid #d1d5db;cursor:pointer;transition:all 0.2s;"
                                     @click="toggleAll(soonItems)"
                                     :style="allSelected(soonItems) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                    <svg x-show="allSelected(soonItems)" style="height:12px;width:12px;color:#ffffff;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            </th>
                            <th style="padding:10px 12px;text-align:left;">Furnizor</th>
                            <th style="padding:10px 12px;text-align:left;">Denumire produs</th>
                            <th style="padding:10px 12px;text-align:right;" title="Cantitate recomandată pentru {{ $this->coverDays }} zile acoperire + 3 zile safety stock, ajustată cu trendul cererii">De comandat</th>
                            <th style="padding:10px 12px;text-align:right;" title="Câte zile mai durează stocul la viteza actuală de vânzare">Zile până la epuizare</th>
                            <th style="padding:10px 12px;text-align:right;">Stoc actual</th>
                            <th style="padding:10px 12px;text-align:right;" title="Cantitate vândută în ultimele 7 zile">Vândut 7 zile</th>
                            <th style="padding:10px 12px;text-align:right;" title="Medie zilnică ajustată cu trendul recent (7z vs 30z). ↑ cerere în creștere, ↓ cerere în scădere">Viteză estimată/zi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->soonProducts as $product)
                            @php
                                $days       = $product->days_until_stockout;
                                $adj        = $product->adjusted_daily ?? 0;
                                $noSupplier = (int)$product->supplier_id === 0;
                                $pid        = (int)$product->product_id;
                                $qty        = max(1, (int)($product->recommended_qty ?? 1));
                                $sid        = (int)$product->supplier_id;
                            @endphp
                            <tr @click="selected.includes({{ $pid }})
                                    ? (selected.splice(selected.indexOf({{ $pid }}), 1), delete itemData[{{ $pid }}])
                                    : (selected.push({{ $pid }}), itemData[{{ $pid }}] = { product_id: {{ $pid }}, supplier_id: {{ $sid }}, qty: {{ $qty }} })"
                                :style="selected.includes({{ $pid }}) ? 'background-color:#dcfce7;cursor:pointer;transition:background-color 0.2s;border-bottom:1px solid #f3f4f6;' : 'cursor:pointer;transition:background-color 0.2s;border-bottom:1px solid #f3f4f6;'"
                                onmouseover="if(!this.__alpine_selected) this.style.backgroundColor='#fff7ed';"
                                onmouseout="if(!this.__alpine_selected) this.style.backgroundColor='';">
                                <td style="padding:10px 12px;">
                                    <div style="margin:0 auto;display:flex;height:20px;width:20px;align-items:center;justify-content:center;border-radius:9999px;border:2px solid #d1d5db;transition:all 0.2s;"
                                         :style="selected.includes({{ $pid }}) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                        <svg x-show="selected.includes({{ $pid }})" style="height:12px;width:12px;color:#ffffff;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                </td>
                                <td style="padding:10px 12px;font-weight:500;color:#374151;white-space:nowrap;">
                                    @if($noSupplier)
                                        <span style="display:inline-flex;align-items:center;border-radius:9999px;background-color:#f3f4f6;padding:2px 8px;font-size:0.75rem;font-weight:500;color:#6b7280;">N/A</span>
                                    @else
                                        {{ $product->supplier_name }}
                                    @endif
                                </td>
                                <td style="padding:10px 12px;">
                                    <span style="font-weight:500;color:#111827;">{{ $product->name }}</span>
                                    @if($product->brand)
                                        <span style="color:#6b7280;"> ({{ $product->brand }})</span>
                                    @endif
                                    @if($product->sku)
                                        <span style="display:block;font-family:monospace;font-size:0.75rem;color:#9ca3af;margin-top:2px;">{{ $product->sku }}</span>
                                    @endif
                                </td>
                                <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">
                                    @if($product->recommended_qty > 0)
                                        <span style="font-weight:700;color:#111827;">{{ number_format($product->recommended_qty, 0, '.', '') }}</span>
                                        <span style="margin-left:4px;font-size:0.875rem;color:#9ca3af;">{{ $product->unit ?? 'buc' }}</span>
                                    @else
                                        <span style="color:#9ca3af;">—</span>
                                    @endif
                                </td>
                                <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;font-weight:600;">
                                    @if($days === null)
                                        <span style="color:#9ca3af;">—</span>
                                    @else
                                        <span style="color:#ea580c;">~{{ round($days) }} {{ round($days) == 1 ? 'zi' : 'zile' }} ⚠️</span>
                                    @endif
                                </td>
                                <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">
                                    @if((float)$product->stock <= 0)
                                        <span style="display:inline-flex;align-items:center;border-radius:9999px;background-color:#fee2e2;padding:2px 8px;font-size:0.875rem;font-weight:600;color:#b91c1c;">{{ number_format((float)$product->stock, 0, '.', '') }}</span>
                                    @else
                                        <span style="display:inline-flex;align-items:center;border-radius:9999px;background-color:#ffedd5;padding:2px 8px;font-size:0.875rem;font-weight:600;color:#c2410c;">{{ number_format((float)$product->stock, 0, '.', '') }}</span>
                                    @endif
                                </td>
                                <td style="padding:10px 12px;text-align:right;color:#4b5563;font-variant-numeric:tabular-nums;">
                                    {{ $product->consumed_7d > 0 ? number_format($product->consumed_7d, $product->consumed_7d < 10 ? 1 : 0, '.', '') : '—' }}
                                </td>
                                <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;font-weight:500;">
                                    @if($adj > 0)
                                        <span style="color:{{ ($product->trend_direction ?? 0) > 0 ? '#dc2626' : (($product->trend_direction ?? 0) < 0 ? '#16a34a' : '#8B1A1A') }};">
                                            {{ number_format($adj, $adj < 10 ? 2 : 1, '.', '') }}
                                            @if(($product->trend_direction ?? 0) > 0) ↑@elseif(($product->trend_direction ?? 0) < 0) ↓@endif
                                        </span>
                                    @else
                                        <span style="color:#9ca3af;">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p style="padding:10px 12px;text-align:right;font-size:0.75rem;color:#9ca3af;border-top:1px solid #f3f4f6;">
                    * De comandat = max(avg7z, avg30z, avg90z) × {{ $this->coverDays }}z + safety 3z. ↑/↓ = cerere în creștere/scădere față de medie.
                </p>
            </div>
        @endif
    </div>

    {{-- ===== TAB 3: SITUAȚIE / FURNIZOR ===== --}}
    <div x-show="activeTab === 'supplier'">
        @php
            $dropdownSuppliers = $this->suppliers->map(fn($s) => [
                'id'     => $s->id,
                'name'   => $s->name,
                'urgent' => $s->urgent_count,
                'soon'   => $s->soon_count,
                'zero'   => $s->zero_count,
                'total'  => $s->products->count() + $s->extraProducts->count(),
            ])->values()->toArray();
            $selectedName = $this->selectedSupplierId
                ? ($this->suppliers->firstWhere('id', $this->selectedSupplierId)?->name ?? '')
                : '';
        @endphp

        <div style="background-color:#fffef9;border:1.5px solid #eedfc6;border-radius:0.75rem;padding:1.25rem 1.5rem;"
             x-data="{
                open: false,
                search: '{{ addslashes($selectedName) }}',
                selected: {{ $this->selectedSupplierId ? 'true' : 'false' }},
                suppliers: @js($dropdownSuppliers),
                get filtered() {
                    if (!this.search.trim()) return this.suppliers;
                    const q = this.search.toLowerCase();
                    return this.suppliers.filter(s => s.name.toLowerCase().includes(q));
                },
                pick(s) {
                    this.search = s.name;
                    this.selected = true;
                    this.open = false;
                    $wire.set('selectedSupplierId', s.id);
                },
                clear() {
                    this.search = '';
                    this.selected = false;
                    this.open = false;
                    $wire.set('selectedSupplierId', null);
                }
             }"
             @click.outside="open = false">
            <label style="display:block;margin-bottom:0.5rem;font-size:0.875rem;font-weight:600;color:#5a3e28;">
                Alege furnizorul pentru care vrei să vezi situația stocurilor:
            </label>
            <div style="position:relative;">
                <input
                    type="text"
                    x-model="search"
                    @focus="open = true"
                    @input="open = true; selected = false"
                    @keydown.escape="open = false"
                    placeholder="Caută furnizor..."
                    style="width:100%;border:1.5px solid #d4b88a;border-radius:0.5rem;background:#ffffff;padding:0.625rem 2.5rem 0.625rem 1rem;font-size:0.875rem;font-weight:500;color:#3b2108;outline:none;box-sizing:border-box;"
                />
                <button x-show="search.length > 0" @click="clear()" type="button"
                    style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);color:#b08060;font-size:1rem;line-height:1;background:none;border:none;cursor:pointer;">✕</button>

                <div x-show="open && filtered.length > 0" x-cloak
                     style="position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:100;background:#ffffff;border:1.5px solid #d4b88a;border-radius:0.5rem;box-shadow:0 8px 24px rgba(0,0,0,0.10);max-height:20rem;overflow-y:auto;">
                    <template x-for="s in filtered" :key="s.id">
                        <div @click="pick(s)"
                             style="padding:0.625rem 1rem;cursor:pointer;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f5ece0;"
                             @mouseenter="$el.style.background='#fffef2'"
                             @mouseleave="$el.style.background='white'">
                            <span x-text="s.name" style="font-size:0.875rem;font-weight:500;color:#3b2108;flex:1;min-width:0;margin-right:0.75rem;"></span>
                            <div style="display:flex;gap:0.3rem;align-items:center;flex-shrink:0;">
                                <template x-if="s.urgent > 0">
                                    <span style="background:#fee2e2;color:#991b1b;border-radius:9999px;padding:0.1rem 0.45rem;font-size:0.7rem;font-weight:700;" x-text="'🔴 ' + s.urgent"></span>
                                </template>
                                <template x-if="s.soon > 0">
                                    <span style="background:#fef3c7;color:#92400e;border-radius:9999px;padding:0.1rem 0.45rem;font-size:0.7rem;font-weight:700;" x-text="'⚠️ ' + s.soon"></span>
                                </template>
                                <template x-if="s.zero > 0">
                                    <span style="background:#f1f5f9;color:#475569;border-radius:9999px;padding:0.1rem 0.45rem;font-size:0.7rem;font-weight:700;" x-text="'📦 ' + s.zero"></span>
                                </template>
                            </div>
                        </div>
                    </template>
                    <template x-if="filtered.length === 0">
                        <div style="padding:1rem;text-align:center;color:#b08060;font-size:0.875rem;">Niciun furnizor găsit</div>
                    </template>
                </div>
            </div>
        </div>

        @php
            $selSupplier = $this->selectedSupplierId
                ? $this->suppliers->firstWhere('id', $this->selectedSupplierId)
                : null;
        @endphp

        @if($selSupplier)
            @php
                $primaryCount = $selSupplier->products->count();
                $extraCount   = $selSupplier->extraProducts->count();
            @endphp
            <div style="overflow:hidden;border-radius:12px;border:1px solid #e5e7eb;background-color:#ffffff;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                {{-- Header furnizor --}}
                <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f3f4f6;background-color:#f9fafb;padding:16px 24px;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        @if($selSupplier->logo)
                            <img src="{{ Storage::url($selSupplier->logo) }}" alt="{{ $selSupplier->name }}" style="height:32px;width:32px;border-radius:4px;object-fit:contain;">
                        @endif
                        <span style="font-size:1rem;font-weight:600;color:#111827;">{{ $selSupplier->name }}</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        @if($extraCount > 0)
                            <span style="border-radius:9999px;background-color:#f3f4f6;padding:2px 10px;font-size:0.75rem;font-weight:500;color:#6b7280;">
                                +{{ $extraCount }} noi
                            </span>
                        @endif
                        <span style="border-radius:9999px;background-color:#ffedd5;padding:2px 10px;font-size:0.75rem;font-weight:500;color:#9a3412;">
                            {{ $primaryCount }} {{ $primaryCount === 1 ? 'produs' : 'produse' }}
                        </span>
                    </div>
                </div>

                <table style="width:100%;font-size:1rem;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid #f3f4f6;background-color:#f8f9fa;font-size:0.75rem;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:#374151;">
                            <th style="width:40px;padding:10px 12px;"></th>
                            <th style="padding:10px 12px;text-align:left;">Denumire produs</th>
                            <th style="padding:10px 12px;text-align:right;" title="Cantitate recomandată pentru {{ $this->coverDays }} zile acoperire + 3 zile safety stock, ajustată cu trendul cererii">De comandat</th>
                            <th style="padding:10px 12px;text-align:right;" title="Câte zile mai durează stocul la viteza actuală de vânzare">Zile până la epuizare</th>
                            <th style="padding:10px 12px;text-align:right;">Stoc actual</th>
                            <th style="padding:10px 12px;text-align:right;" title="Cantitate vândută în ultimele 7 zile">Vândut 7 zile</th>
                            <th style="padding:10px 12px;text-align:right;" title="Medie zilnică ajustată cu trendul recent (7z vs 30z). ↑ cerere în creștere, ↓ cerere în scădere">Viteză estimată/zi</th>
                        </tr>
                    </thead>

                    @if($primaryCount > 0)
                        <tbody>
                            @foreach($selSupplier->products as $product)
                                @php
                                    $days = $product->days_until_stockout;
                                    $adj  = $product->adjusted_daily ?? 0;
                                    $pid  = (int)$product->product_id;
                                    $qty  = max(1, (int)($product->recommended_qty ?? 1));
                                    $sid  = (int)$product->supplier_id;
                                @endphp
                                <tr @click="selected.includes({{ $pid }})
                                        ? (selected.splice(selected.indexOf({{ $pid }}), 1), delete itemData[{{ $pid }}])
                                        : (selected.push({{ $pid }}), itemData[{{ $pid }}] = { product_id: {{ $pid }}, supplier_id: {{ $sid }}, qty: {{ $qty }} })"
                                    :style="selected.includes({{ $pid }}) ? 'background-color:#dcfce7;cursor:pointer;transition:background-color 0.2s;border-bottom:1px solid #f3f4f6;' : 'cursor:pointer;transition:background-color 0.2s;border-bottom:1px solid #f3f4f6;'"
                                    onmouseover="if(!this.__alpine_selected) this.style.backgroundColor='#f9fafb';"
                                    onmouseout="if(!this.__alpine_selected) this.style.backgroundColor='';">
                                    <td style="padding:10px 12px;">
                                        <div style="margin:0 auto;display:flex;height:20px;width:20px;align-items:center;justify-content:center;border-radius:9999px;border:2px solid #d1d5db;transition:all 0.2s;"
                                             :style="selected.includes({{ $pid }}) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                            <svg x-show="selected.includes({{ $pid }})" style="height:12px;width:12px;color:#ffffff;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                    </td>
                                    <td style="padding:10px 12px;">
                                        <span style="font-weight:500;color:#111827;">{{ $product->name }}</span>
                                        @if($product->brand)
                                            <span style="color:#6b7280;"> ({{ $product->brand }})</span>
                                        @endif
                                        @if($product->sku)
                                            <span style="display:block;font-family:monospace;font-size:0.75rem;color:#9ca3af;margin-top:2px;">{{ $product->sku }}</span>
                                        @endif
                                    </td>
                                    <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">
                                        @if($product->recommended_qty > 0)
                                            <span style="font-weight:700;color:#111827;">{{ number_format($product->recommended_qty, 0, '.', '') }}</span>
                                            <span style="margin-left:4px;font-size:0.875rem;color:#9ca3af;">{{ $product->unit ?? 'buc' }}</span>
                                        @else
                                            <span style="color:#d1d5db;">—</span>
                                        @endif
                                    </td>
                                    <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;font-weight:600;">
                                        @if($days === null)
                                            <span style="color:#d1d5db;">—</span>
                                        @elseif($days < 1)
                                            <span style="color:#dc2626;">&lt;1 zi 🔴</span>
                                        @elseif($days < 7)
                                            <span style="color:#dc2626;">~{{ round($days) }} {{ round($days) == 1 ? 'zi' : 'zile' }} 🔴</span>
                                        @elseif($days < 14)
                                            <span style="color:#ea580c;">~{{ round($days) }} zile ⚠️</span>
                                        @elseif($days < 21)
                                            <span style="color:#f97316;">~{{ round($days) }} zile</span>
                                        @else
                                            <span style="color:#6b7280;">~{{ round($days) }} zile</span>
                                        @endif
                                    </td>
                                    <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">
                                        @if((float)$product->stock <= 0)
                                            <span style="display:inline-flex;align-items:center;border-radius:9999px;background-color:#fee2e2;padding:2px 8px;font-size:0.875rem;font-weight:600;color:#b91c1c;">{{ number_format((float)$product->stock, 0, '.', '') }}</span>
                                        @else
                                            <span style="display:inline-flex;align-items:center;border-radius:9999px;background-color:#ffedd5;padding:2px 8px;font-size:0.875rem;font-weight:600;color:#c2410c;">{{ number_format((float)$product->stock, 0, '.', '') }}</span>
                                        @endif
                                    </td>
                                    <td style="padding:10px 12px;text-align:right;color:#4b5563;font-variant-numeric:tabular-nums;">
                                        {{ $product->consumed_7d > 0 ? number_format($product->consumed_7d, $product->consumed_7d < 10 ? 1 : 0, '.', '') : '—' }}
                                    </td>
                                    <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;font-weight:500;">
                                        @if($adj > 0)
                                            <span style="color:{{ ($product->trend_direction ?? 0) > 0 ? '#dc2626' : (($product->trend_direction ?? 0) < 0 ? '#16a34a' : '#8B1A1A') }};">
                                                {{ number_format($adj, $adj < 10 ? 2 : 1, '.', '') }}
                                                @if(($product->trend_direction ?? 0) > 0) ↑@elseif(($product->trend_direction ?? 0) < 0) ↓@endif
                                            </span>
                                        @else
                                            <span style="color:#d1d5db;">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endif

                    @if($extraCount > 0)
                        <tbody x-data="{ expanded: false }">
                            <tr style="border-top:1px dashed #e5e7eb;">
                                <td colspan="7" style="padding:0;">
                                    <button type="button" @click.stop="expanded = !expanded"
                                        style="display:flex;width:100%;align-items:center;gap:8px;padding:8px 24px;text-align:left;font-size:0.75rem;font-weight:500;color:#6b7280;background:none;border:none;cursor:pointer;transition:background-color 0.2s;"
                                        onmouseover="this.style.backgroundColor='#f9fafb';"
                                        onmouseout="this.style.backgroundColor='';">
                                        <svg xmlns="http://www.w3.org/2000/svg" style="height:14px;width:14px;transition:transform 0.2s;" :style="expanded ? 'transform:rotate(90deg);' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                        </svg>
                                        <span x-show="!expanded">Arată {{ $extraCount }} {{ $extraCount === 1 ? 'produs nou' : 'produse noi' }} (fără istoric mișcare)</span>
                                        <span x-show="expanded" x-cloak>Ascunde produsele noi</span>
                                    </button>
                                </td>
                            </tr>
                            @foreach($selSupplier->extraProducts as $product)
                                @php
                                    $pid = (int)$product->product_id;
                                    $sid = (int)$product->supplier_id;
                                @endphp
                                <tr x-show="expanded" x-transition
                                    @click="selected.includes({{ $pid }})
                                        ? (selected.splice(selected.indexOf({{ $pid }}), 1), delete itemData[{{ $pid }}])
                                        : (selected.push({{ $pid }}), itemData[{{ $pid }}] = { product_id: {{ $pid }}, supplier_id: {{ $sid }}, qty: 1 })"
                                    :style="selected.includes({{ $pid }}) ? 'background-color:#dcfce7;cursor:pointer;transition:background-color 0.2s;border-bottom:1px solid #f3f4f6;' : 'opacity:0.75;background-color:#fcfcfc;cursor:pointer;transition:background-color 0.2s;border-bottom:1px solid #f3f4f6;'"
                                    onmouseover="this.style.backgroundColor=this.style.backgroundColor==='rgb(220, 252, 231)' ? '#dcfce7' : '#f3f4f6';"
                                    onmouseout="this.style.backgroundColor=this.style.backgroundColor==='rgb(220, 252, 231)' ? '#dcfce7' : '#fcfcfc';">
                                    <td style="padding:10px 12px;">
                                        <div style="margin:0 auto;display:flex;height:20px;width:20px;align-items:center;justify-content:center;border-radius:9999px;border:2px solid #d1d5db;transition:all 0.2s;"
                                             :style="selected.includes({{ $pid }}) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                            <svg x-show="selected.includes({{ $pid }})" style="height:12px;width:12px;color:#ffffff;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                    </td>
                                    <td style="padding:10px 12px;">
                                        <span style="color:#4b5563;">{{ $product->name }}</span>
                                        @if($product->brand)
                                            <span style="color:#9ca3af;"> ({{ $product->brand }})</span>
                                        @endif
                                        @if($product->sku)
                                            <span style="display:block;font-family:monospace;font-size:0.75rem;color:#9ca3af;margin-top:2px;">{{ $product->sku }}</span>
                                        @endif
                                    </td>
                                    <td style="padding:10px 12px;text-align:right;color:#d1d5db;">—</td>
                                    <td style="padding:10px 12px;text-align:right;color:#d1d5db;">—</td>
                                    <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">
                                        @if((float)$product->stock <= 0)
                                            <span style="display:inline-flex;align-items:center;border-radius:9999px;background-color:#fee2e2;padding:2px 8px;font-size:0.875rem;font-weight:600;color:#b91c1c;">{{ number_format((float)$product->stock, 0, '.', '') }}</span>
                                        @else
                                            <span style="display:inline-flex;align-items:center;border-radius:9999px;background-color:#ffedd5;padding:2px 8px;font-size:0.875rem;font-weight:600;color:#c2410c;">{{ number_format((float)$product->stock, 0, '.', '') }}</span>
                                        @endif
                                    </td>
                                    <td style="padding:10px 12px;text-align:right;color:#d1d5db;">—</td>
                                    <td style="padding:10px 12px;text-align:right;color:#d1d5db;">—</td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endif
                </table>
            </div>
        @elseif($this->suppliers->isNotEmpty())
            <div style="border:1.5px dashed #d1d5db;border-radius:0.75rem;padding:3rem;text-align:center;background-color:#f9fafb;">
                <div style="margin:0 auto 0.75rem;width:3rem;height:3rem;border-radius:9999px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;">
                    <x-filament::icon icon="heroicon-o-building-storefront" style="width:1.5rem;height:1.5rem;color:#6b7280;" />
                </div>
                <p style="font-size:1rem;font-weight:600;color:#374151;">Niciun furnizor selectat</p>
                <p style="margin-top:0.25rem;font-size:0.875rem;color:#6b7280;">Folosește lista de mai sus pentru a alege furnizorul.</p>
            </div>
        @endif
    </div>

</div>
</x-filament-panels::page>
