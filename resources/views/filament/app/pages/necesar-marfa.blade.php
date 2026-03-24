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
    <div class="flex flex-wrap items-stretch gap-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-500 dark:text-gray-400">Furnizori afectați</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $this->statSuppliers }}</p>
        </div>
        <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-400/20 dark:bg-warning-950/20">
            <p class="text-sm text-warning-700 dark:text-warning-300">Produse afectate</p>
            <p class="mt-1 text-2xl font-bold text-warning-700 dark:text-warning-300">{{ $this->statProducts }}</p>
        </div>
        <div class="rounded-xl border border-danger-200 bg-danger-50 p-4 dark:border-danger-400/20 dark:bg-danger-950/20">
            <p class="text-sm text-danger-700 dark:text-danger-300">Stoc zero</p>
            <p class="mt-1 text-2xl font-bold text-danger-700 dark:text-danger-300">{{ $this->statZeroStock }}</p>
        </div>
        <div class="rounded-xl border border-danger-300 bg-danger-100 p-4 dark:border-danger-400/30 dark:bg-danger-900/30">
            <p class="text-sm font-medium text-danger-800 dark:text-danger-200">🔴 Urgente (&lt;7 zile)</p>
            <p class="mt-1 text-2xl font-bold text-danger-800 dark:text-danger-200">{{ $this->statUrgent }}</p>
        </div>
        <div class="rounded-xl border border-warning-300 bg-warning-100 p-4 dark:border-warning-400/30 dark:bg-warning-900/30">
            <p class="text-sm font-medium text-warning-800 dark:text-warning-200">⚠️ Curând (7–14 zile)</p>
            <p class="mt-1 text-2xl font-bold text-warning-800 dark:text-warning-200">{{ $this->statSoon }}</p>
        </div>

        <div class="ml-auto flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-gray-900">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
                Acoperire comandă (zile):
            </label>
            <input
                type="number" min="7" max="60"
                wire:model.live.debounce.500ms="coverDays"
                class="w-20 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-center
                       focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500
                       dark:border-white/20 dark:bg-gray-800 dark:text-white"
            />
            <span class="text-xs text-gray-400 dark:text-gray-500">zile</span>
        </div>
    </div>

    {{-- Banner selecție + buton Creează necesar --}}
    <div x-show="selected.length > 0" x-cloak x-transition
         class="flex items-center justify-between rounded-xl border border-primary-200 bg-primary-50 px-5 py-3 dark:border-primary-700/40 dark:bg-primary-900/20">
        <div class="flex items-center gap-3">
            <span class="text-sm font-semibold text-primary-700 dark:text-primary-300">
                <span x-text="selected.length"></span> poziții selectate
            </span>
            <button @click="selected = []; itemData = {}"
                    class="text-xs text-primary-500 underline hover:text-primary-700 dark:text-primary-400">
                Deselectează tot
            </button>
        </div>
        <button
            @click="$wire.createNecesarFromSelection(selected.map(pid => itemData[pid]))"
            wire:loading.attr="disabled"
            class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm
                   hover:bg-primary-700 active:bg-primary-800 disabled:opacity-60 transition-colors">
            <svg wire:loading wire:target="createNecesarFromSelection" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
            <svg wire:loading.remove wire:target="createNecesarFromSelection" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            Creează necesar
        </button>
    </div>

    {{-- Tab Navigation --}}
    <div class="flex rounded-xl bg-gray-100 p-1 gap-1 dark:bg-gray-800">
        <button @click="activeTab = 'urgent'"
                class="flex flex-1 items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-all"
                :class="activeTab === 'urgent'
                    ? 'bg-white shadow text-danger-700 dark:bg-gray-900 dark:text-danger-400'
                    : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'">
            <span>🔴 Epuizare &lt;7 zile</span>
            <span class="rounded-full bg-danger-100 px-2 py-0.5 text-xs font-bold text-danger-700 dark:bg-danger-900/50 dark:text-danger-300">
                {{ $this->statUrgent }}
            </span>
        </button>
        <button @click="activeTab = 'soon'"
                class="flex flex-1 items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-all"
                :class="activeTab === 'soon'
                    ? 'bg-white shadow text-warning-700 dark:bg-gray-900 dark:text-warning-400'
                    : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'">
            <span>⚠️ Epuizare 7–14 zile</span>
            <span class="rounded-full bg-warning-100 px-2 py-0.5 text-xs font-bold text-warning-700 dark:bg-warning-900/50 dark:text-warning-300">
                {{ $this->statSoon }}
            </span>
        </button>
        <button @click="activeTab = 'supplier'"
                class="flex flex-1 items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-all"
                :class="activeTab === 'supplier'
                    ? 'bg-white shadow text-primary-700 dark:bg-gray-900 dark:text-primary-400'
                    : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'">
            <span>📦 Situație / Furnizor</span>
        </button>
    </div>

    {{-- ===== TAB 1: URGENȚE <7 ZILE ===== --}}
    <div x-show="activeTab === 'urgent'">
        @if($this->urgentProducts->isEmpty())
            <div class="rounded-xl border border-success-200 bg-success-50 p-6 text-center dark:border-success-400/20 dark:bg-success-950/20">
                <x-heroicon-o-check-circle class="mx-auto h-8 w-8 text-success-500" />
                <p class="mt-2 text-success-700 dark:text-success-300 font-medium">Niciun produs cu stoc critic și mișcare recentă.</p>
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-danger-200 bg-white shadow-sm dark:border-danger-400/20 dark:bg-gray-900">
                <table class="w-full text-base">
                    <thead>
                        <tr class="border-b border-danger-100 bg-danger-50 dark:border-danger-400/20 dark:bg-danger-950/20
                                   text-xs font-medium uppercase tracking-wide text-danger-700 dark:text-danger-400">
                            <th class="w-10 px-3 py-2.5" title="Selectează / deselectează tot">
                                <div class="mx-auto flex h-5 w-5 items-center justify-center rounded-full border-2 cursor-pointer transition-all"
                                     @click="toggleAll(urgentItems)"
                                     :style="allSelected(urgentItems) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                    <svg x-show="allSelected(urgentItems)" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            </th>
                            <th class="px-4 py-2.5 text-left">Furnizor</th>
                            <th class="px-4 py-2.5 text-left">Denumire produs</th>
                            <th class="px-4 py-2.5 text-right" title="Cantitate recomandată pentru {{ $this->coverDays }} zile acoperire + 3 zile safety stock, ajustată cu trendul cererii">De comandat</th>
                            <th class="px-4 py-2.5 text-right" title="Câte zile mai durează stocul la viteza actuală de vânzare">Zile până la epuizare</th>
                            <th class="px-4 py-2.5 text-right">Stoc actual</th>
                            <th class="px-4 py-2.5 text-right" title="Cantitate vândută în ultimele 7 zile">Vândut 7 zile</th>
                            <th class="px-4 py-2.5 text-right" title="Medie zilnică ajustată cu trendul recent (7z vs 30z). ↑ cerere în creștere, ↓ cerere în scădere">Viteză estimată/zi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-white/5">
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
                                :class="! selected.includes({{ $pid }}) ? 'hover:bg-danger-50/40 dark:hover:bg-danger-950/20' : ''" :style="selected.includes({{ $pid }}) ? 'background-color:#dcfce7' : ''"
                                class="cursor-pointer transition-colors">
                                <td class="px-3 py-3">
                                    <div class="mx-auto flex h-5 w-5 items-center justify-center rounded-full border-2 transition-all"
                                         :class="true" :style="selected.includes({{ $pid }}) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                        <svg x-show="selected.includes({{ $pid }})" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                </td>
                                <td class="px-4 py-3 font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                    @if($noSupplier)
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">N/A</span>
                                    @else
                                        {{ $product->supplier_name }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $product->name }}</span>
                                    @if($product->brand)
                                        <span class="text-gray-500 dark:text-gray-400"> ({{ $product->brand }})</span>
                                    @endif
                                    @if($product->sku)
                                        <span class="block font-mono text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $product->sku }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    @if($product->recommended_qty > 0)
                                        <span class="font-bold text-gray-900 dark:text-white">{{ number_format($product->recommended_qty, 0) }}</span>
                                        <span class="ml-1 text-sm text-gray-400 dark:text-gray-500">{{ $product->unit ?? 'buc' }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold">
                                    @if($days === null)
                                        <span class="text-gray-400">—</span>
                                    @elseif($days < 1)
                                        <span class="text-danger-600 dark:text-danger-400">&lt;1 zi 🔴</span>
                                    @else
                                        <span class="text-danger-600 dark:text-danger-400">~{{ round($days) }} {{ round($days) == 1 ? 'zi' : 'zile' }} 🔴</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    @if((float)$product->stock <= 0)
                                        <span class="inline-flex items-center rounded-full bg-danger-100 px-2 py-0.5 text-sm font-semibold text-danger-700 dark:bg-danger-900/40 dark:text-danger-300">{{ number_format((float)$product->stock, 0) }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-sm font-semibold text-warning-700 dark:bg-warning-900/40 dark:text-warning-300">{{ number_format((float)$product->stock, 0) }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400 tabular-nums">
                                    {{ $product->consumed_7d > 0 ? number_format($product->consumed_7d, $product->consumed_7d < 10 ? 1 : 0) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium">
                                    @if($adj > 0)
                                        <span class="{{ ($product->trend_direction ?? 0) > 0 ? 'text-danger-600 dark:text-danger-400' : (($product->trend_direction ?? 0) < 0 ? 'text-success-600 dark:text-success-400' : 'text-primary-600 dark:text-primary-400') }}">
                                            {{ number_format($adj, $adj < 10 ? 2 : 1) }}
                                            @if(($product->trend_direction ?? 0) > 0) ↑@elseif(($product->trend_direction ?? 0) < 0) ↓@endif
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="px-4 py-2 text-right text-xs text-gray-400 dark:text-gray-600 border-t border-gray-100 dark:border-white/5">
                    * De comandat = max(avg7z, avg30z, avg90z) × {{ $this->coverDays }}z + safety 3z. ↑/↓ = cerere în creștere/scădere față de medie.
                </p>
            </div>
        @endif
    </div>

    {{-- ===== TAB 2: CURÂND 7–14 ZILE ===== --}}
    <div x-show="activeTab === 'soon'">
        @if($this->soonProducts->isEmpty())
            <div class="rounded-xl border border-success-200 bg-success-50 p-6 text-center dark:border-success-400/20 dark:bg-success-950/20">
                <x-heroicon-o-check-circle class="mx-auto h-8 w-8 text-success-500" />
                <p class="mt-2 text-success-700 dark:text-success-300 font-medium">Niciun produs cu epuizare iminentă în 7–14 zile.</p>
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-warning-200 bg-white shadow-sm dark:border-warning-400/20 dark:bg-gray-900">
                <table class="w-full text-base">
                    <thead>
                        <tr class="border-b border-warning-100 bg-warning-50 dark:border-warning-400/20 dark:bg-warning-950/20
                                   text-xs font-medium uppercase tracking-wide text-warning-700 dark:text-warning-400">
                            <th class="w-10 px-3 py-2.5" title="Selectează / deselectează tot">
                                <div class="mx-auto flex h-5 w-5 items-center justify-center rounded-full border-2 cursor-pointer transition-all"
                                     @click="toggleAll(soonItems)"
                                     :style="allSelected(soonItems) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                    <svg x-show="allSelected(soonItems)" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            </th>
                            <th class="px-4 py-2.5 text-left">Furnizor</th>
                            <th class="px-4 py-2.5 text-left">Denumire produs</th>
                            <th class="px-4 py-2.5 text-right" title="Cantitate recomandată pentru {{ $this->coverDays }} zile acoperire + 3 zile safety stock, ajustată cu trendul cererii">De comandat</th>
                            <th class="px-4 py-2.5 text-right" title="Câte zile mai durează stocul la viteza actuală de vânzare">Zile până la epuizare</th>
                            <th class="px-4 py-2.5 text-right">Stoc actual</th>
                            <th class="px-4 py-2.5 text-right" title="Cantitate vândută în ultimele 7 zile">Vândut 7 zile</th>
                            <th class="px-4 py-2.5 text-right" title="Medie zilnică ajustată cu trendul recent (7z vs 30z). ↑ cerere în creștere, ↓ cerere în scădere">Viteză estimată/zi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-white/5">
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
                                :class="! selected.includes({{ $pid }}) ? 'hover:bg-warning-50/40 dark:hover:bg-warning-950/20' : ''" :style="selected.includes({{ $pid }}) ? 'background-color:#dcfce7' : ''"
                                class="cursor-pointer transition-colors">
                                <td class="px-3 py-3">
                                    <div class="mx-auto flex h-5 w-5 items-center justify-center rounded-full border-2 transition-all"
                                         :class="true" :style="selected.includes({{ $pid }}) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                        <svg x-show="selected.includes({{ $pid }})" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                </td>
                                <td class="px-4 py-3 font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                    @if($noSupplier)
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">N/A</span>
                                    @else
                                        {{ $product->supplier_name }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $product->name }}</span>
                                    @if($product->brand)
                                        <span class="text-gray-500 dark:text-gray-400"> ({{ $product->brand }})</span>
                                    @endif
                                    @if($product->sku)
                                        <span class="block font-mono text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $product->sku }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    @if($product->recommended_qty > 0)
                                        <span class="font-bold text-gray-900 dark:text-white">{{ number_format($product->recommended_qty, 0) }}</span>
                                        <span class="ml-1 text-sm text-gray-400 dark:text-gray-500">{{ $product->unit ?? 'buc' }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold">
                                    @if($days === null)
                                        <span class="text-gray-400">—</span>
                                    @else
                                        <span class="text-warning-600 dark:text-warning-400">~{{ round($days) }} {{ round($days) == 1 ? 'zi' : 'zile' }} ⚠️</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    @if((float)$product->stock <= 0)
                                        <span class="inline-flex items-center rounded-full bg-danger-100 px-2 py-0.5 text-sm font-semibold text-danger-700 dark:bg-danger-900/40 dark:text-danger-300">{{ number_format((float)$product->stock, 0) }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-sm font-semibold text-warning-700 dark:bg-warning-900/40 dark:text-warning-300">{{ number_format((float)$product->stock, 0) }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400 tabular-nums">
                                    {{ $product->consumed_7d > 0 ? number_format($product->consumed_7d, $product->consumed_7d < 10 ? 1 : 0) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium">
                                    @if($adj > 0)
                                        <span class="{{ ($product->trend_direction ?? 0) > 0 ? 'text-danger-600 dark:text-danger-400' : (($product->trend_direction ?? 0) < 0 ? 'text-success-600 dark:text-success-400' : 'text-primary-600 dark:text-primary-400') }}">
                                            {{ number_format($adj, $adj < 10 ? 2 : 1) }}
                                            @if(($product->trend_direction ?? 0) > 0) ↑@elseif(($product->trend_direction ?? 0) < 0) ↓@endif
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="px-4 py-2 text-right text-xs text-gray-400 dark:text-gray-600 border-t border-gray-100 dark:border-white/5">
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
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                {{-- Header furnizor --}}
                <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                    <div class="flex items-center gap-3">
                        @if($selSupplier->logo)
                            <img src="{{ Storage::url($selSupplier->logo) }}" alt="{{ $selSupplier->name }}" class="h-8 w-8 rounded object-contain">
                        @endif
                        <span class="text-base font-semibold text-gray-900 dark:text-white">{{ $selSupplier->name }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($extraCount > 0)
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                +{{ $extraCount }} noi
                            </span>
                        @endif
                        <span class="rounded-full bg-warning-100 px-2.5 py-0.5 text-xs font-medium text-warning-800 dark:bg-warning-900/30 dark:text-warning-300">
                            {{ $primaryCount }} {{ $primaryCount === 1 ? 'produs' : 'produse' }}
                        </span>
                    </div>
                </div>

                <table class="w-full text-base">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-white/10
                                   text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            <th class="w-10 px-3 py-2.5"></th>
                            <th class="px-6 py-2.5 text-left">Denumire produs</th>
                            <th class="px-4 py-2.5 text-right" title="Cantitate recomandată pentru {{ $this->coverDays }} zile acoperire + 3 zile safety stock, ajustată cu trendul cererii">De comandat</th>
                            <th class="px-4 py-2.5 text-right" title="Câte zile mai durează stocul la viteza actuală de vânzare">Zile până la epuizare</th>
                            <th class="px-4 py-2.5 text-right">Stoc actual</th>
                            <th class="px-4 py-2.5 text-right" title="Cantitate vândută în ultimele 7 zile">Vândut 7 zile</th>
                            <th class="px-4 py-2.5 text-right" title="Medie zilnică ajustată cu trendul recent (7z vs 30z). ↑ cerere în creștere, ↓ cerere în scădere">Viteză estimată/zi</th>
                        </tr>
                    </thead>

                    @if($primaryCount > 0)
                        <tbody class="divide-y divide-gray-50 dark:divide-white/5">
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
                                    :class="! selected.includes({{ $pid }}) ? 'hover:bg-gray-50 dark:hover:bg-white/5' : ''" :style="selected.includes({{ $pid }}) ? 'background-color:#dcfce7' : ''"
                                    class="cursor-pointer transition-colors">
                                    <td class="px-3 py-3">
                                        <div class="mx-auto flex h-5 w-5 items-center justify-center rounded-full border-2 transition-all"
                                             :class="true" :style="selected.includes({{ $pid }}) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                            <svg x-show="selected.includes({{ $pid }})" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $product->name }}</span>
                                        @if($product->brand)
                                            <span class="text-gray-500 dark:text-gray-400"> ({{ $product->brand }})</span>
                                        @endif
                                        @if($product->sku)
                                            <span class="block font-mono text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $product->sku }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        @if($product->recommended_qty > 0)
                                            <span class="font-bold text-gray-900 dark:text-white">{{ number_format($product->recommended_qty, 0) }}</span>
                                            <span class="ml-1 text-sm text-gray-400 dark:text-gray-500">{{ $product->unit ?? 'buc' }}</span>
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums font-semibold">
                                        @if($days === null)
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @elseif($days < 1)
                                            <span class="text-danger-600 dark:text-danger-400">&lt;1 zi 🔴</span>
                                        @elseif($days < 7)
                                            <span class="text-danger-600 dark:text-danger-400">~{{ round($days) }} {{ round($days) == 1 ? 'zi' : 'zile' }} 🔴</span>
                                        @elseif($days < 14)
                                            <span class="text-warning-600 dark:text-warning-400">~{{ round($days) }} zile ⚠️</span>
                                        @elseif($days < 21)
                                            <span class="text-warning-500 dark:text-warning-500">~{{ round($days) }} zile</span>
                                        @else
                                            <span class="text-gray-500 dark:text-gray-400">~{{ round($days) }} zile</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        @if((float)$product->stock <= 0)
                                            <span class="inline-flex items-center rounded-full bg-danger-100 px-2 py-0.5 text-sm font-semibold text-danger-700 dark:bg-danger-900/40 dark:text-danger-300">{{ number_format((float)$product->stock, 0) }}</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-sm font-semibold text-warning-700 dark:bg-warning-900/40 dark:text-warning-300">{{ number_format((float)$product->stock, 0) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400 tabular-nums">
                                        {{ $product->consumed_7d > 0 ? number_format($product->consumed_7d, $product->consumed_7d < 10 ? 1 : 0) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums font-medium">
                                        @if($adj > 0)
                                            <span class="{{ ($product->trend_direction ?? 0) > 0 ? 'text-danger-600 dark:text-danger-400' : (($product->trend_direction ?? 0) < 0 ? 'text-success-600 dark:text-success-400' : 'text-primary-600 dark:text-primary-400') }}">
                                                {{ number_format($adj, $adj < 10 ? 2 : 1) }}
                                                @if(($product->trend_direction ?? 0) > 0) ↑@elseif(($product->trend_direction ?? 0) < 0) ↓@endif
                                            </span>
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endif

                    @if($extraCount > 0)
                        <tbody x-data="{ expanded: false }">
                            <tr class="border-t border-dashed border-gray-200 dark:border-white/10">
                                <td colspan="7" class="p-0">
                                    <button type="button" @click.stop="expanded = !expanded"
                                        class="flex w-full items-center gap-2 px-6 py-2 text-left text-xs font-medium text-gray-500 transition hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 transition-transform duration-200" :class="expanded ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
                                    :class="! selected.includes({{ $pid }}) ? 'opacity-75 bg-gray-50/50 hover:bg-gray-100 dark:bg-white/[0.02] dark:hover:bg-white/5' : ''" :style="selected.includes({{ $pid }}) ? 'background-color:#dcfce7' : ''"
                                    class="cursor-pointer transition-colors">
                                    <td class="px-3 py-2.5">
                                        <div class="mx-auto flex h-5 w-5 items-center justify-center rounded-full border-2 transition-all"
                                             :class="true" :style="selected.includes({{ $pid }}) ? 'border-color:#16a34a;background-color:#16a34a' : 'border-color:#d1d5db'">
                                            <svg x-show="selected.includes({{ $pid }})" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                    </td>
                                    <td class="px-6 py-2.5">
                                        <span class="text-gray-600 dark:text-gray-400">{{ $product->name }}</span>
                                        @if($product->brand)
                                            <span class="text-gray-400 dark:text-gray-500"> ({{ $product->brand }})</span>
                                        @endif
                                        @if($product->sku)
                                            <span class="block font-mono text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $product->sku }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-gray-300 dark:text-gray-600">—</td>
                                    <td class="px-4 py-2.5 text-right text-gray-300 dark:text-gray-600">—</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">
                                        @if((float)$product->stock <= 0)
                                            <span class="inline-flex items-center rounded-full bg-danger-100 px-2 py-0.5 text-sm font-semibold text-danger-700 dark:bg-danger-900/40 dark:text-danger-300">{{ number_format((float)$product->stock, 0) }}</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-sm font-semibold text-warning-700 dark:bg-warning-900/40 dark:text-warning-300">{{ number_format((float)$product->stock, 0) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-gray-300 dark:text-gray-600">—</td>
                                    <td class="px-4 py-2.5 text-right text-gray-300 dark:text-gray-600">—</td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endif
                </table>
            </div>
        @elseif($this->suppliers->isNotEmpty())
            <div style="border:1.5px dashed #d1d5db;border-radius:0.75rem;padding:3rem;text-align:center;background-color:#f9fafb;">
                <div style="margin:0 auto 0.75rem;width:3rem;height:3rem;border-radius:9999px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;">
                    <x-heroicon-o-building-storefront style="width:1.5rem;height:1.5rem;color:#6b7280;" />
                </div>
                <p style="font-size:1rem;font-weight:600;color:#374151;">Niciun furnizor selectat</p>
                <p style="margin-top:0.25rem;font-size:0.875rem;color:#6b7280;">Folosește lista de mai sus pentru a alege furnizorul.</p>
            </div>
        @endif
    </div>

</div>
</x-filament-panels::page>
