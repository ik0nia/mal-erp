<x-filament-panels::page>

  @php
    $typeCfg = [
      'weekly'     => ['label' => 'Săptămânal',  'bg' => '#DBEAFE', 'text' => '#1D4ED8', 'darkBg' => '#1E3A5F', 'darkText' => '#93C5FD'],
      'monthly'    => ['label' => 'Lunar',        'bg' => '#EDE9FE', 'text' => '#6D28D9', 'darkBg' => '#3B1F6E', 'darkText' => '#C4B5FD'],
      'quarterly'  => ['label' => 'Trimestrial',  'bg' => '#FFEDD5', 'text' => '#C2410C', 'darkBg' => '#5C2D0E', 'darkText' => '#FDBA74'],
      'semiannual' => ['label' => 'Semestrial',   'bg' => '#FFE4E6', 'text' => '#BE123C', 'darkBg' => '#5C1526', 'darkText' => '#FDA4AF'],
      'annual'     => ['label' => 'Anual',        'bg' => '#1F2937', 'text' => '#FFFFFF', 'darkBg' => '#F3F4F6', 'darkText' => '#111827'],
      'manual'     => ['label' => 'Manual',       'bg' => '#F3F4F6', 'text' => '#6B7280', 'darkBg' => '#374151', 'darkText' => '#9CA3AF'],
    ];
    $defaultType = ['label' => 'Manual', 'bg' => '#F3F4F6', 'text' => '#6B7280', 'darkBg' => '#374151', 'darkText' => '#9CA3AF'];
  @endphp

  {{-- ── Banner generare în curs ────────────────────────────────────── --}}
  @if ($pendingId)
    <div wire:poll.4000ms="checkPending"
         style="display: flex; align-items: center; gap: 16px; border-radius: 12px; border: 1px solid #BFDBFE; background: #EFF6FF; padding: 16px 20px; margin-bottom: 8px;">
      <svg style="animation: spin 1s linear infinite; height: 20px; width: 20px; color: #3B82F6; flex-shrink: 0;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
      </svg>
      <div>
        <p style="font-size: 14px; font-weight: 600; color: #1E40AF; margin: 0;">Claude analizează datele...</p>
        <p style="font-size: 12px; color: #2563EB; margin: 4px 0 0 0;">Rulează în fundal. Pagina se actualizează automat când e gata.</p>
      </div>
    </div>
    <style>
      @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
  @endif

  @php
    $allAnalyses      = $this->getAllAnalyses();
    $selectedAnalysis = $this->getSelectedAnalysis();
  @endphp

  @if ($allAnalyses->isEmpty() && !$pendingId)
    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 96px 0; text-align: center;">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 56px; height: 56px; color: #D1D5DB; margin-bottom: 16px;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
      </svg>
      <h3 style="font-size: 18px; font-weight: 600; color: #4B5563; margin: 0;">Nicio analiză generată încă</h3>
      <p style="font-size: 14px; color: #9CA3AF; margin: 4px 0 0 0; max-width: 380px;">
        Apasă <strong>„Generează analiză nouă"</strong> din dreapta sus.
      </p>
    </div>
  @else

    <div style="display: flex; gap: 20px; align-items: flex-start;">

      {{-- ── Coloana stângă: lista analizelor ──────────────────────── --}}
      <div style="width: 288px; flex-shrink: 0; border-radius: 12px; border: 1px solid #E5E7EB; background: #FFFFFF; box-shadow: 0 1px 3px rgba(0,0,0,0.06); overflow: hidden;">

        <div style="padding: 12px 16px; border-bottom: 1px solid #F3F4F6; background: #F9FAFB;">
          <p style="font-size: 11px; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0;">
            Analize salvate ({{ $allAnalyses->count() }})
          </p>

          {{-- Legendă tipuri --}}
          <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px;">
            @foreach($typeCfg as $tKey => $tVal)
              <span style="display: inline-flex; align-items: center; border-radius: 4px; padding: 2px 6px; font-size: 10px; font-weight: 700; background: {{ $tVal['bg'] }}; color: {{ $tVal['text'] }};">
                {{ $tVal['label'] }}
              </span>
            @endforeach
          </div>
        </div>

        <div style="max-height: 75vh; overflow-y: auto;">
          @foreach ($allAnalyses as $analysis)
            @php
              $isSelected = $selectedId === $analysis->id;
              $isFailed   = $analysis->status === 'failed';
              $tc         = $typeCfg[$analysis->type ?? 'manual'] ?? $defaultType;

              $itemBg = $isSelected ? '#FEF2F2' : '#FFFFFF';
              $borderLeft = $isSelected ? '3px solid #8B1A1A' : '3px solid transparent';
              $cursor = $isFailed ? 'default' : 'pointer';
              $opacity = $isFailed ? '0.6' : '1';
            @endphp
            <div
              wire:click="{{ $isFailed ? '' : "selectAnalysis({$analysis->id})" }}"
              style="padding: 12px 16px; cursor: {{ $cursor }}; opacity: {{ $opacity }}; background: {{ $itemBg }}; border-left: {{ $borderLeft }}; border-bottom: 1px solid #F3F4F6; transition: background 0.15s;"
              onmouseover="if(!{{ $isSelected ? 'true' : 'false' }} && !{{ $isFailed ? 'true' : 'false' }}) this.style.background='#F9FAFB'"
              onmouseout="this.style.background='{{ $itemBg }}'"
            >
              <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                <div style="min-width: 0; flex: 1;">

                  {{-- Badge tip + data --}}
                  <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                    <span style="display: inline-flex; flex-shrink: 0; align-items: center; border-radius: 4px; padding: 2px 6px; font-size: 10px; font-weight: 700; background: {{ $tc['bg'] }}; color: {{ $tc['text'] }};">
                      {{ $tc['label'] }}
                    </span>
                    <span style="font-size: 12px; font-weight: 500; color: {{ $isSelected ? '#8B1A1A' : '#374151' }};">
                      {{ $analysis->generated_at->format('d.m.Y') }}
                    </span>
                  </div>

                  {{-- Titlu trunchiat --}}
                  <p style="font-size: 12px; color: #6B7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.3; margin: 0;" title="{{ $analysis->title }}">
                    {{ $analysis->title }}
                  </p>

                  {{-- Metadata --}}
                  <p style="font-size: 10px; color: #9CA3AF; margin: 2px 0 0 0;">
                    {{ $analysis->generated_at->format('H:i') }}
                    &middot; {{ $analysis->generatedBy?->name ?? 'sistem' }}
                  </p>

                  @if ($isFailed)
                    <span style="display: inline-block; margin-top: 4px; font-size: 12px; color: #EF4444; font-weight: 500;">Eșuată</span>
                  @elseif (isset($analysis->metrics_snapshot['cost_usd']))
                    <p style="font-size: 10px; color: #9CA3AF; margin: 2px 0 0 0;">
                      ${{ number_format($analysis->metrics_snapshot['cost_usd'], 4) }}
                    </p>
                  @endif

                </div>
                <button
                  wire:click.stop="deleteAnalysis({{ $analysis->id }})"
                  wire:confirm="Ștergi această analiză?"
                  style="background: none; border: none; padding: 2px; cursor: pointer; flex-shrink: 0; margin-top: 2px; color: #D1D5DB; transition: color 0.15s;"
                  onmouseover="this.style.color='#EF4444'"
                  onmouseout="this.style.color='#D1D5DB'"
                  title="Șterge"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 16px; height: 16px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                  </svg>
                </button>
              </div>
            </div>
          @endforeach
        </div>

      </div>

      {{-- ── Coloana dreaptă: conținut analiză selectată ────────────── --}}
      <div style="flex: 1; min-width: 0; border-radius: 12px; border: 1px solid #E5E7EB; background: #FFFFFF; box-shadow: 0 1px 3px rgba(0,0,0,0.06); overflow: hidden;">

        @if ($selectedAnalysis)
          @php
            $stc = $typeCfg[$selectedAnalysis->type ?? 'manual'] ?? $defaultType;
          @endphp

          {{-- Header --}}
          <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; border-bottom: 1px solid #F3F4F6; background: #F9FAFB; flex-wrap: wrap; gap: 12px;">
            <div>
              <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                <span style="display: inline-flex; align-items: center; border-radius: 6px; padding: 3px 8px; font-size: 12px; font-weight: 700; background: {{ $stc['bg'] }}; color: {{ $stc['text'] }};">
                  {{ $stc['label'] }}
                </span>
                <h2 style="font-size: 16px; font-weight: 600; color: #1F2937; margin: 0;">{{ $selectedAnalysis->title }}</h2>
              </div>
              <p style="font-size: 12px; color: #9CA3AF; margin: 0;">
                {{ $selectedAnalysis->generated_at->format('d.m.Y H:i') }}
                &middot; Generată de {{ $selectedAnalysis->generatedBy?->name ?? 'sistem' }}
                @if(isset($selectedAnalysis->metrics_snapshot['cost_usd']))
                  &middot; <span title="{{ $selectedAnalysis->metrics_snapshot['tokens_input'] ?? 0 }} input + {{ $selectedAnalysis->metrics_snapshot['tokens_output'] ?? 0 }} output tokens">
                      ${{ number_format($selectedAnalysis->metrics_snapshot['cost_usd'], 4) }}
                    </span>
                @endif
              </p>
            </div>

            @php
              $snap = $selectedAnalysis->metrics_snapshot ?? [];
              $snapBadges = array_filter([
                isset($snap['total_p0']) ? ['color' => '#FEF2F2', 'textColor' => '#B91C1C', 'text' => $snap['total_p0'].' P0'] : null,
                isset($snap['total_p1']) ? ['color' => '#FFF7ED', 'textColor' => '#C2410C', 'text' => $snap['total_p1'].' P1'] : null,
                isset($snap['stock_value_end']) ? ['color' => '#EFF6FF', 'textColor' => '#1D4ED8', 'text' => number_format($snap['stock_value_end'],0,',','.').' RON'] : null,
                isset($snap['ordersLast7']) ? ['color' => '#F0FDF4', 'textColor' => '#15803D', 'text' => $snap['ordersLast7'].' comenzi/7z'] : null,
              ]);
            @endphp

            @if(count($snapBadges))
              <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                @foreach($snapBadges as $badge)
                  <span style="display: inline-flex; align-items: center; gap: 4px; border-radius: 9999px; font-size: 12px; font-weight: 500; padding: 4px 10px; background: {{ $badge['color'] }}; color: {{ $badge['textColor'] }};">
                    {{ $badge['text'] }}
                  </span>
                @endforeach
              </div>
            @endif
          </div>

          {{-- Conținut markdown --}}
          <div class="prose prose-sm max-w-none" style="padding: 20px 24px; overflow-y: auto; max-height: 75vh; font-size: 14px; line-height: 1.6; color: #374151;">
            {!! \Illuminate\Support\Str::markdown($selectedAnalysis->content, ['html_input' => 'strip']) !!}
          </div>

        @else
          <div style="display: flex; align-items: center; justify-content: center; height: 192px; color: #9CA3AF; font-size: 14px;">
            Selectează o analiză din lista din stânga.
          </div>
        @endif

      </div>

    </div>

  @endif

</x-filament-panels::page>
