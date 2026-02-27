<x-filament-panels::page>

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

    {{-- ── Empty state ─────────────────────────────────────────────── --}}
    <div class="flex flex-col items-center justify-center py-24 text-center">
      <x-heroicon-o-chart-bar class="w-14 h-14 text-gray-300 dark:text-gray-600 mb-4"/>
      <h3 class="text-lg font-semibold text-gray-600 dark:text-gray-300">Nicio analiză generată încă</h3>
      <p class="text-sm text-gray-400 mt-1 max-w-sm">
        Apasă <strong>„Generează analiză nouă"</strong> din dreapta sus.
      </p>
    </div>

  @else

    {{-- ── Layout două coloane ─────────────────────────────────────── --}}
    <div class="flex gap-5 items-start">

      {{-- Coloana stângă: lista analizelor ──────────────────────────── --}}
      <div class="w-72 shrink-0 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">

        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60">
          <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
            Analize salvate ({{ $allAnalyses->count() }})
          </p>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-700 max-h-[75vh] overflow-y-auto">
          @foreach ($allAnalyses as $analysis)
            @php
              $isSelected = $selectedId === $analysis->id;
              $isFailed   = $analysis->status === 'failed';
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
                <div class="min-w-0">
                  <p @class([
                    'text-sm font-medium truncate',
                    'text-primary-700 dark:text-primary-300' => $isSelected,
                    'text-gray-800 dark:text-gray-100' => !$isSelected,
                  ])>
                    {{ $analysis->generated_at->format('d.m.Y') }}
                  </p>
                  <p class="text-xs text-gray-400 mt-0.5">
                    {{ $analysis->generated_at->format('H:i') }}
                    · {{ $analysis->generatedBy?->name ?? 'sistem' }}
                  </p>
                  @if ($isFailed)
                    <span class="inline-block mt-1 text-xs text-red-500 font-medium">Eșuată</span>
                  @elseif ($analysis->metrics_snapshot)
                    <p class="text-xs text-gray-400 mt-1">
                      {{ $analysis->metrics_snapshot['ordersLast7'] ?? '—' }} comenzi / 7 zile
                    </p>
                  @endif
                </div>
                <button
                  wire:click.stop="deleteAnalysis({{ $analysis->id }})"
                  wire:confirm="Ștergi această analiză?"
                  class="text-gray-200 dark:text-gray-600 hover:text-red-500 transition shrink-0 mt-0.5"
                  title="Șterge"
                >
                  <x-heroicon-o-trash class="w-3.5 h-3.5"/>
                </button>
              </div>
            </div>
          @endforeach
        </div>

      </div>

      {{-- Coloana dreaptă: conținut analiză selectată ──────────────── --}}
      <div class="flex-1 min-w-0 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">

        @if ($selectedAnalysis)

          {{-- Header --}}
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 flex-wrap gap-3">
            <div>
              <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ $selectedAnalysis->title }}</h2>
              <p class="text-xs text-gray-400 mt-0.5">
                {{ $selectedAnalysis->generated_at->format('d.m.Y H:i') }}
                · Generată de {{ $selectedAnalysis->generatedBy?->name ?? 'sistem' }}
                @if(isset($selectedAnalysis->metrics_snapshot['cost_usd']))
                  · <span title="{{ $selectedAnalysis->metrics_snapshot['tokens_input'] ?? 0 }} input + {{ $selectedAnalysis->metrics_snapshot['tokens_output'] ?? 0 }} output tokens">
                      ${{ number_format($selectedAnalysis->metrics_snapshot['cost_usd'], 4) }}
                    </span>
                @endif
              </p>
            </div>
            @if ($selectedAnalysis->metrics_snapshot)
              <div class="flex gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1 rounded-full bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 text-xs font-medium px-3 py-1">
                  <x-heroicon-o-shopping-cart class="w-3.5 h-3.5"/>
                  {{ $selectedAnalysis->metrics_snapshot['ordersLast7'] ?? '—' }} comenzi / 7 zile
                </span>
                <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-xs font-medium px-3 py-1">
                  <x-heroicon-o-cube class="w-3.5 h-3.5"/>
                  {{ $selectedAnalysis->metrics_snapshot['inStockCount'] ?? '—' }} în stoc
                </span>
                <span class="inline-flex items-center gap-1 rounded-full bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 text-xs font-medium px-3 py-1">
                  <x-heroicon-o-banknotes class="w-3.5 h-3.5"/>
                  {{ number_format($selectedAnalysis->metrics_snapshot['ordersValue30'] ?? 0, 0, ',', '.') }} RON / 30 zile
                </span>
              </div>
            @endif
          </div>

          {{-- Conținut markdown --}}
          <div class="px-6 py-5 prose prose-sm dark:prose-invert max-w-none
                      prose-headings:font-semibold
                      prose-h2:text-base prose-h2:mt-6 prose-h2:mb-2
                      prose-h3:text-sm prose-h3:mt-4 prose-h3:mb-1.5
                      prose-p:text-gray-700 dark:prose-p:text-gray-300
                      prose-li:text-gray-700 dark:prose-li:text-gray-300
                      prose-strong:text-gray-900 dark:prose-strong:text-gray-100
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
