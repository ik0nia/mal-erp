<x-filament-panels::page>

  @php
    $typeCfg = [
      'weekly'     => ['label' => 'Săptămânal',  'bg' => 'bg-blue-100 dark:bg-blue-900/40',     'text' => 'text-blue-700 dark:text-blue-300'],
      'monthly'    => ['label' => 'Lunar',        'bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-700 dark:text-violet-300'],
      'quarterly'  => ['label' => 'Trimestrial',  'bg' => 'bg-orange-100 dark:bg-orange-900/40', 'text' => 'text-orange-700 dark:text-orange-300'],
      'semiannual' => ['label' => 'Semestrial',   'bg' => 'bg-rose-100 dark:bg-rose-900/40',     'text' => 'text-rose-700 dark:text-rose-300'],
      'annual'     => ['label' => 'Anual',        'bg' => 'bg-gray-800 dark:bg-gray-100',        'text' => 'text-white dark:text-gray-900'],
      'manual'     => ['label' => 'Manual',       'bg' => 'bg-gray-100 dark:bg-white/10',        'text' => 'text-gray-500 dark:text-gray-400'],
    ];
    $defaultType = ['label' => 'Manual', 'bg' => 'bg-gray-100 dark:bg-white/10', 'text' => 'text-gray-500 dark:text-gray-400'];
  @endphp

  {{-- ── Banner generare în curs ────────────────────────────────────── --}}
  @if ($pendingId)
    <div wire:poll.4000ms="checkPending"
         class="flex items-center gap-4 rounded-xl border border-blue-200 bg-blue-50 dark:bg-blue-950/40 dark:border-blue-800 px-5 py-4 mb-2">
      <svg class="animate-spin h-5 w-5 text-blue-500 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
      </svg>
      <div>
        <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">Claude analizează datele...</p>
        <p class="text-xs text-blue-600 dark:text-blue-400 mt-0.5">Rulează în fundal. Pagina se actualizează automat când e gata.</p>
      </div>
    </div>
  @endif

  @php
    $allAnalyses      = $this->getAllAnalyses();
    $selectedAnalysis = $this->getSelectedAnalysis();
  @endphp

  @if ($allAnalyses->isEmpty() && !$pendingId)
    <div class="flex flex-col items-center justify-center py-24 text-center">
      <x-filament::icon icon="heroicon-o-chart-bar" class="w-14 h-14 text-gray-300 dark:text-gray-600 mb-4"/>
      <h3 class="text-lg font-semibold text-gray-600 dark:text-gray-300">Nicio analiză generată încă</h3>
      <p class="text-sm text-gray-400 mt-1 max-w-sm">
        Apasă <strong>„Generează analiză nouă"</strong> din dreapta sus.
      </p>
    </div>
  @else

    <div class="flex gap-5 items-start">

      {{-- ── Coloana stângă: lista analizelor ──────────────────────── --}}
      <div class="w-72 shrink-0 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">

        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60">
          <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
            Analize salvate ({{ $allAnalyses->count() }})
          </p>

          {{-- Legendă tipuri --}}
          <div class="flex flex-wrap gap-1 mt-2">
            @foreach($typeCfg as $tKey => $tVal)
              <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $tVal['bg'] }} {{ $tVal['text'] }}">
                {{ $tVal['label'] }}
              </span>
            @endforeach
          </div>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-700 max-h-[75vh] overflow-y-auto">
          @foreach ($allAnalyses as $analysis)
            @php
              $isSelected = $selectedId === $analysis->id;
              $isFailed   = $analysis->status === 'failed';
              $tc         = $typeCfg[$analysis->type ?? 'manual'] ?? $defaultType;
            @endphp
            <div
              wire:click="{{ $isFailed ? '' : "selectAnalysis({$analysis->id})" }}"
              @class([
                'px-4 py-3 cursor-pointer transition group',
                'bg-primary-50 dark:bg-primary-950/40 border-l-2 border-primary-500' => $isSelected,
                'hover:bg-gray-50 dark:hover:bg-gray-700/40' => !$isSelected && !$isFailed,
                'opacity-60 cursor-default' => $isFailed,
              ])
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0 flex-1">

                  {{-- Badge tip + data --}}
                  <div class="flex items-center gap-1.5 mb-1">
                    <span class="inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[10px] font-bold {{ $tc['bg'] }} {{ $tc['text'] }}">
                      {{ $tc['label'] }}
                    </span>
                    <span @class([
                      'text-xs font-medium',
                      'text-primary-700 dark:text-primary-300' => $isSelected,
                      'text-gray-700 dark:text-gray-300' => !$isSelected,
                    ])>
                      {{ $analysis->generated_at->format('d.m.Y') }}
                    </span>
                  </div>

                  {{-- Titlu trunchiat --}}
                  <p class="text-xs text-gray-500 dark:text-gray-400 truncate leading-tight" title="{{ $analysis->title }}">
                    {{ $analysis->title }}
                  </p>

                  {{-- Metadata --}}
                  <p class="text-[10px] text-gray-400 mt-0.5">
                    {{ $analysis->generated_at->format('H:i') }}
                    · {{ $analysis->generatedBy?->name ?? 'sistem' }}
                  </p>

                  @if ($isFailed)
                    <span class="inline-block mt-1 text-xs text-red-500 font-medium">Eșuată</span>
                  @elseif (isset($analysis->metrics_snapshot['cost_usd']))
                    <p class="text-[10px] text-gray-400 mt-0.5">
                      ${{ number_format($analysis->metrics_snapshot['cost_usd'], 4) }}
                    </p>
                  @endif

                </div>
                <button
                  wire:click.stop="deleteAnalysis({{ $analysis->id }})"
                  wire:confirm="Ștergi această analiză?"
                  class="text-gray-200 dark:text-gray-600 hover:text-red-500 transition shrink-0 mt-0.5"
                  title="Șterge"
                >
                  <x-filament::icon icon="heroicon-o-trash" class="w-3.5 h-3.5"/>
                </button>
              </div>
            </div>
          @endforeach
        </div>

      </div>

      {{-- ── Coloana dreaptă: conținut analiză selectată ────────────── --}}
      <div class="flex-1 min-w-0 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">

        @if ($selectedAnalysis)
          @php
            $stc = $typeCfg[$selectedAnalysis->type ?? 'manual'] ?? $defaultType;
          @endphp

          {{-- Header --}}
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 flex-wrap gap-3">
            <div>
              <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-bold {{ $stc['bg'] }} {{ $stc['text'] }}">
                  {{ $stc['label'] }}
                </span>
                <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ $selectedAnalysis->title }}</h2>
              </div>
              <p class="text-xs text-gray-400">
                {{ $selectedAnalysis->generated_at->format('d.m.Y H:i') }}
                · Generată de {{ $selectedAnalysis->generatedBy?->name ?? 'sistem' }}
                @if(isset($selectedAnalysis->metrics_snapshot['cost_usd']))
                  · <span title="{{ $selectedAnalysis->metrics_snapshot['tokens_input'] ?? 0 }} input + {{ $selectedAnalysis->metrics_snapshot['tokens_output'] ?? 0 }} output tokens">
                      ${{ number_format($selectedAnalysis->metrics_snapshot['cost_usd'], 4) }}
                    </span>
                @endif
              </p>
            </div>

            @php
              $snap = $selectedAnalysis->metrics_snapshot ?? [];
              $snapBadges = array_filter([
                isset($snap['total_p0']) ? ['icon' => 'heroicon-o-exclamation-triangle', 'color' => 'red',    'text' => $snap['total_p0'].' P0'] : null,
                isset($snap['total_p1']) ? ['icon' => 'heroicon-o-clock',                'color' => 'orange', 'text' => $snap['total_p1'].' P1'] : null,
                isset($snap['stock_value_end']) ? ['icon' => 'heroicon-o-banknotes',     'color' => 'blue',   'text' => number_format($snap['stock_value_end'],0,',','.').' RON'] : null,
                isset($snap['ordersLast7']) ? ['icon' => 'heroicon-o-shopping-cart',     'color' => 'green',  'text' => $snap['ordersLast7'].' comenzi/7z'] : null,
              ]);
              $colorMap = [
                'red'    => 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400',
                'orange' => 'bg-orange-50 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400',
                'blue'   => 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
                'green'  => 'bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400',
                'violet' => 'bg-violet-50 dark:bg-violet-900/30 text-violet-700 dark:text-violet-400',
              ];
            @endphp

            @if(count($snapBadges))
              <div class="flex gap-2 flex-wrap">
                @foreach($snapBadges as $badge)
                  <span class="inline-flex items-center gap-1 rounded-full text-xs font-medium px-2.5 py-1 {{ $colorMap[$badge['color']] }}">
                    {{ $badge['text'] }}
                  </span>
                @endforeach
              </div>
            @endif
          </div>

          {{-- Conținut markdown --}}
          <div class="px-6 py-5 prose prose-sm dark:prose-invert max-w-none
                      prose-headings:font-semibold
                      prose-h1:text-lg prose-h1:mt-4 prose-h1:mb-3
                      prose-h2:text-base prose-h2:mt-6 prose-h2:mb-2
                      prose-h3:text-sm prose-h3:mt-4 prose-h3:mb-1.5
                      prose-p:text-gray-700 dark:prose-p:text-gray-300
                      prose-li:text-gray-700 dark:prose-li:text-gray-300
                      prose-strong:text-gray-900 dark:prose-strong:text-gray-100
                      prose-table:text-xs
                      overflow-y-auto max-h-[75vh]">
            {!! \Illuminate\Support\Str::markdown($selectedAnalysis->content, ['html_input' => 'strip']) !!}
          </div>

        @else
          <div class="flex items-center justify-center h-48 text-gray-400 text-sm">
            Selectează o analiză din lista din stânga.
          </div>
        @endif

      </div>

    </div>

  @endif

</x-filament-panels::page>
