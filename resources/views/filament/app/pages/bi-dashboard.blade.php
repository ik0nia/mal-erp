<x-filament-panels::page>

  @php
    $deltaPositive = $this->stockDelta >= 0;
    $deltaColor    = $deltaPositive ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
    $deltaSign     = $deltaPositive ? '+' : '';
    $kpiDataMissing = $this->kpiDay === '—';
  @endphp

  {{-- ── KPI Cards ────────────────────────────────────────────────────────── --}}
  <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">

    {{-- Valoare stoc --}}
    <div class="col-span-2 sm:col-span-1 xl:col-span-2 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 px-5 py-4">
      <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Valoare stoc</div>
      @if($kpiDataMissing)
        <div class="mt-1 text-2xl font-bold text-gray-300 dark:text-gray-600">—</div>
      @else
        <div class="mt-1 text-2xl font-bold text-gray-800 dark:text-gray-100">
          {{ number_format($this->stockValue, 0, ',', '.') }}
          <span class="text-sm font-normal text-gray-400">RON</span>
        </div>
        <div class="mt-1 flex items-center gap-2">
          <span class="text-sm font-medium {{ $deltaColor }}">
            {{ $deltaSign }}{{ number_format($this->stockDelta, 0, ',', '.') }} RON
          </span>
          <span class="text-xs text-gray-400">
            ({{ $deltaSign }}{{ number_format($this->stockDeltaPct, 2, ',', '.') }}% față de ieri)
          </span>
        </div>
        <div class="mt-1 text-xs text-gray-400">Data: {{ $this->kpiDay }}</div>
      @endif
    </div>

    {{-- În stoc / Fără stoc --}}
    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 px-5 py-4">
      <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Produse în stoc</div>
      <div class="mt-1 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ number_format($this->inStock) }}</div>
      <div class="mt-1 text-xs text-gray-400">{{ number_format($this->outOfStock) }} fără stoc</div>
    </div>

    {{-- P0 --}}
    <div class="rounded-xl border border-red-100 dark:border-red-900/40 bg-red-50 dark:bg-red-950/30 px-5 py-4">
      <div class="text-xs font-semibold uppercase tracking-wide text-red-400">Critice (P0)</div>
      <div class="mt-1 text-2xl font-bold text-red-700 dark:text-red-400">{{ number_format($this->countP0) }}</div>
      <div class="mt-1 text-xs text-red-400">stoc 0 sau &lt; 7 zile</div>
    </div>

    {{-- P1 --}}
    <div class="rounded-xl border border-orange-100 dark:border-orange-900/40 bg-orange-50 dark:bg-orange-950/30 px-5 py-4">
      <div class="text-xs font-semibold uppercase tracking-wide text-orange-400">Moderate (P1)</div>
      <div class="mt-1 text-2xl font-bold text-orange-700 dark:text-orange-400">{{ number_format($this->countP1) }}</div>
      <div class="mt-1 text-xs text-orange-400">7–14 zile rămase</div>
    </div>

    {{-- P2 --}}
    <div class="rounded-xl border border-yellow-100 dark:border-yellow-900/40 bg-yellow-50 dark:bg-yellow-950/20 px-5 py-4">
      <div class="text-xs font-semibold uppercase tracking-wide text-yellow-600 dark:text-yellow-400">Capital Blocat — Dead Stock (P2)</div>
      <div class="mt-1 text-2xl font-bold text-yellow-700 dark:text-yellow-400">{{ number_format($this->countP2) }}</div>
      <div class="mt-1 text-xs text-yellow-600 dark:text-yellow-500">capital blocat ≥ 300 RON</div>
    </div>

  </div>

  {{-- ── Chart trend stoc ──────────────────────────────────────────────────── --}}
  @livewire(\App\Filament\App\Widgets\BiStockTrendChartWidget::class)

  {{-- ── Velocity ────────────────────────────────────────────────────────────── --}}
  <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 overflow-hidden">

    {{-- Header --}}
    <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-white/5 bg-gray-50 dark:bg-gray-800/60 flex-wrap gap-3">
      <div>
        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Velocity produse</h2>
        <p class="text-xs text-gray-400 mt-0.5">Ritm de ieșire din stoc bazat pe ultimele 30/90 zile</p>
      </div>

      {{-- Tab pills --}}
      <div class="flex items-center gap-2">
        <button
          wire:click="setVelocityTab('fast')"
          class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition focus:outline-none"
          style="{{ $this->velocityTab === 'fast' ? 'background:#2563eb;color:#fff;' : 'border:1px solid #d1d5db;color:#6b7280;background:#fff;' }}"
        >
          <span>↑</span> Rapid mișcătoare
        </button>
        <button
          wire:click="setVelocityTab('slow')"
          class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition focus:outline-none"
          style="{{ $this->velocityTab === 'slow' ? 'background:#4b5563;color:#fff;' : 'border:1px solid #d1d5db;color:#6b7280;background:#fff;' }}"
        >
          <span>↓</span> Fără mișcare ≥30 zile
        </button>
      </div>
    </div>

    {{-- Tabel velocity --}}
    @if(count($this->velocityRows) === 0)
      <div class="flex items-center justify-center py-12 text-gray-400 text-sm">
        Nicio dată velocity. Rulează <code class="font-mono ml-1">bi:compute-velocity</code>.
      </div>
    @else
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-gray-100 dark:border-white/5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
              <th class="px-4 py-2.5 text-left">SKU</th>
              <th class="px-4 py-2.5 text-left">Produs</th>
              @if($this->velocityTab === 'fast')
                <th class="px-4 py-2.5 text-right">Avg/zi 7d</th>
                <th class="px-4 py-2.5 text-right">Avg/zi 30d</th>
                <th class="px-4 py-2.5 text-right">Avg/zi 90d</th>
                <th class="px-4 py-2.5 text-right">Total ieșit 30d</th>
              @else
                <th class="px-4 py-2.5 text-right">Ultima mișcare</th>
                <th class="px-4 py-2.5 text-right">Zile fără mișcare</th>
                <th class="px-4 py-2.5 text-right">Total ieșit 30d</th>
                <th class="px-4 py-2.5 text-right">Total ieșit 90d</th>
              @endif
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50 dark:divide-white/5">
            @foreach($this->velocityRows as $idx => $row)
              @php
                $avg30 = (float) $row['avg_out_qty_30d'];
                $daysNoMove = $row['days_since_last_movement'];
                $daysColor = match(true) {
                  $daysNoMove === null              => 'text-gray-400',
                  $daysNoMove >= 90                 => 'text-red-600 dark:text-red-400 font-bold',
                  $daysNoMove >= 60                 => 'text-orange-600 dark:text-orange-400 font-semibold',
                  default                           => 'text-gray-700 dark:text-gray-300',
                };
                $rankColor = match(true) {
                  $idx < 5  => 'text-blue-600 dark:text-blue-400 font-bold',
                  $idx < 15 => 'text-blue-500 dark:text-blue-400',
                  default   => 'text-gray-400',
                };
              @endphp
              <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition">
                <td class="px-4 py-2.5">
                  <span class="font-mono text-xs text-gray-600 dark:text-gray-400">{{ $row['sku'] }}</span>
                </td>
                <td class="px-4 py-2.5 max-w-[300px]">
                  <span class="block truncate text-gray-800 dark:text-gray-200" title="{{ $row['product_name'] ?? $row['sku'] }}">
                    {{ $row['product_name'] ?? '—' }}
                  </span>
                </td>
                @if($this->velocityTab === 'fast')
                  <td class="px-4 py-2.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                    {{ (float)$row['avg_out_qty_7d'] > 0 ? number_format($row['avg_out_qty_7d'], 3, ',', '.') : '—' }}
                  </td>
                  <td class="px-4 py-2.5 text-right tabular-nums {{ $rankColor }}">
                    {{ $avg30 > 0 ? number_format($avg30, 3, ',', '.') : '—' }}
                  </td>
                  <td class="px-4 py-2.5 text-right tabular-nums text-gray-500 dark:text-gray-400">
                    {{ (float)$row['avg_out_qty_90d'] > 0 ? number_format($row['avg_out_qty_90d'], 3, ',', '.') : '—' }}
                  </td>
                  <td class="px-4 py-2.5 text-right tabular-nums text-gray-700 dark:text-gray-300">
                    {{ (float)$row['out_qty_30d'] > 0 ? number_format($row['out_qty_30d'], 0, ',', '.') : '0' }}
                  </td>
                @else
                  <td class="px-4 py-2.5 text-right tabular-nums text-gray-500 dark:text-gray-400">
                    {{ $row['last_movement_day'] ?? 'niciodată' }}
                  </td>
                  <td class="px-4 py-2.5 text-right tabular-nums {{ $daysColor }}">
                    {{ $daysNoMove !== null ? number_format($daysNoMove).' zile' : '—' }}
                  </td>
                  <td class="px-4 py-2.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                    {{ (float)$row['out_qty_30d'] > 0 ? number_format($row['out_qty_30d'], 0, ',', '.') : '0' }}
                  </td>
                  <td class="px-4 py-2.5 text-right tabular-nums text-gray-500 dark:text-gray-400">
                    {{ (float)$row['out_qty_90d'] > 0 ? number_format($row['out_qty_90d'], 0, ',', '.') : '0' }}
                  </td>
                @endif
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="px-5 py-2 text-xs text-gray-400 border-t border-gray-100 dark:border-white/5">
        Afișate primele 50 de produse · calculat pentru {{ $this->velocityRows[0]['calculated_for_day'] ?? '—' }}
      </div>
    @endif

  </div>

  {{-- ── Tabel alerte ──────────────────────────────────────────────────────── --}}
  <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 overflow-hidden">

    {{-- Header cu tabs --}}
    <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-white/5 bg-gray-50 dark:bg-gray-800/60 flex-wrap gap-3">

      <div>
        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Candidați la alertare</h2>
        <p class="text-xs text-gray-400 mt-0.5">Ziua: {{ $this->alertDay }}</p>
      </div>

      {{-- Tab pills --}}
      <div class="flex items-center gap-2">
        <button
          wire:click="setTab('P0')"
          class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition focus:outline-none"
          style="{{ $this->tab === 'P0' ? 'background:#dc2626;color:#fff;' : 'border:1px solid #d1d5db;color:#6b7280;background:#fff;' }}"
        >
          Critice (P0)
          <span class="rounded-full px-1.5 py-0.5 text-xs font-bold" style="{{ $this->tab === 'P0' ? 'background:rgba(255,255,255,0.3);' : 'background:#f3f4f6;' }}">{{ $this->countP0 }}</span>
        </button>
        <button
          wire:click="setTab('P1')"
          class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition focus:outline-none"
          style="{{ $this->tab === 'P1' ? 'background:#f97316;color:#fff;' : 'border:1px solid #d1d5db;color:#6b7280;background:#fff;' }}"
        >
          Moderate (P1)
          <span class="rounded-full px-1.5 py-0.5 text-xs font-bold" style="{{ $this->tab === 'P1' ? 'background:rgba(255,255,255,0.3);' : 'background:#f3f4f6;' }}">{{ $this->countP1 }}</span>
        </button>
        <button
          wire:click="setTab('P2')"
          class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition focus:outline-none"
          style="{{ $this->tab === 'P2' ? 'background:#eab308;color:#fff;' : 'border:1px solid #d1d5db;color:#6b7280;background:#fff;' }}"
        >
          Capital Blocat (P2)
          <span class="rounded-full px-1.5 py-0.5 text-xs font-bold" style="{{ $this->tab === 'P2' ? 'background:rgba(255,255,255,0.3);' : 'background:#f3f4f6;' }}">{{ $this->countP2 }}</span>
        </button>
      </div>

    </div>

    {{-- Tabel --}}
    @if(count($this->alertRows) === 0)
      <div class="flex items-center justify-center py-12 text-gray-400 text-sm">
        Niciun candidat {{ $this->tab }} pentru {{ $this->alertDay }}.
      </div>
    @else
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-gray-100 dark:border-white/5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
              <th class="px-4 py-2.5 text-left">SKU</th>
              <th class="px-4 py-2.5 text-left">Produs</th>
              <th class="px-4 py-2.5 text-right">Stoc (buc)</th>
              <th class="px-4 py-2.5 text-right">Preț</th>
              <th class="px-4 py-2.5 text-right">Valoare stoc</th>
              @if($this->tab !== 'P2')
                <th class="px-4 py-2.5 text-right">Consum/zi</th>
                <th class="px-4 py-2.5 text-right">Zile rămase</th>
              @else
                <th class="px-4 py-2.5 text-right">Valoare blocată</th>
              @endif
              <th class="px-4 py-2.5 text-left">Motive</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50 dark:divide-white/5">
            @foreach($this->alertRows as $row)
              @php
                $daysLeft = $row['days_left'];
                $daysColor = match(true) {
                  $daysLeft === null || $row['closing_qty'] <= 0 => 'text-gray-400',
                  $daysLeft <= 7  => 'text-red-600 dark:text-red-400 font-bold',
                  $daysLeft <= 14 => 'text-orange-600 dark:text-orange-400 font-semibold',
                  default         => 'text-gray-700 dark:text-gray-300',
                };

                $flagLabels = [
                  'out_of_stock'       => ['label' => 'Epuizat',       'class' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'],
                  'critical_stock'     => ['label' => '< 7 zile',      'class' => 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400'],
                  'low_stock'          => ['label' => '7–14 zile',     'class' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300'],
                  'price_spike'        => ['label' => 'Spike preț',    'class' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300'],
                  'dead_stock'         => ['label' => 'Dead stock',    'class' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400'],
                  'no_consumption_30d' => ['label' => 'Fără mișcare',  'class' => 'bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-500'],
                ];
              @endphp
              <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition">
                <td class="px-4 py-2.5">
                  <span class="font-mono text-xs text-gray-600 dark:text-gray-400">{{ $row['sku'] }}</span>
                </td>
                <td class="px-4 py-2.5 max-w-[280px]">
                  <span class="block truncate text-gray-800 dark:text-gray-200" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                </td>
                <td class="px-4 py-2.5 text-right tabular-nums">
                  @if($row['closing_qty'] <= 0)
                    <span class="text-red-500 font-semibold">0</span>
                  @else
                    {{ number_format($row['closing_qty'], 0, ',', '.') }}
                  @endif
                </td>
                <td class="px-4 py-2.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                  {{ $row['closing_price'] !== null ? number_format($row['closing_price'], 2, ',', '.') . ' RON' : '—' }}
                </td>
                <td class="px-4 py-2.5 text-right tabular-nums font-medium text-gray-800 dark:text-gray-200">
                  {{ number_format($row['stock_value'], 0, ',', '.') }} RON
                </td>
                @if($this->tab !== 'P2')
                  <td class="px-4 py-2.5 text-right tabular-nums text-gray-500 dark:text-gray-400">
                    {{ $row['avg_out_30d'] > 0 ? number_format($row['avg_out_30d'], 2, ',', '.') : '—' }}
                  </td>
                  <td class="px-4 py-2.5 text-right tabular-nums {{ $daysColor }}">
                    {{ $daysLeft !== null ? number_format($daysLeft, 0, ',', '.') : '∞' }}
                  </td>
                @else
                  <td class="px-4 py-2.5 text-right tabular-nums font-semibold text-yellow-700 dark:text-yellow-400">
                    {{ number_format($row['stock_value'], 0, ',', '.') }} RON
                  </td>
                @endif
                <td class="px-4 py-2.5">
                  <div class="flex flex-wrap gap-1">
                    @foreach($row['reason_flags'] as $flag)
                      @if(isset($flagLabels[$flag]))
                        <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium {{ $flagLabels[$flag]['class'] }}">
                          {{ $flagLabels[$flag]['label'] }}
                        </span>
                      @endif
                    @endforeach
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      @if(count($this->alertRows) >= 200)
        <div class="px-5 py-2 text-xs text-gray-400 border-t border-gray-100 dark:border-white/5">
          Afișate primele 200 de produse. Rulează <code class="font-mono">bi:compute-alerts</code> pentru date actualizate.
        </div>
      @endif
    @endif

  </div>

</x-filament-panels::page>
