@php
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\View\ComponentAttributeBag;

    $color       = $this->getColor();
    $heading     = $this->getHeading();
    $description = $this->getDescription();
    $type        = $this->getType();
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 overflow-hidden">

        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-white/5">
            <span class="font-semibold text-gray-900 dark:text-white text-sm">{{ $heading }}</span>
            <div class="flex items-center gap-2">
                {{-- Dropdown: Valoare / Cantitate --}}
                <x-filament::input.wrapper inline-prefix wire:target="mode">
                    <x-filament::input.select inline-prefix wire:model.live="mode">
                        <option value="revenue">Valoare (lei)</option>
                        <option value="orders">Comenzi (nr)</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                {{-- Dropdown: An --}}
                <x-filament::input.wrapper inline-prefix wire:target="year">
                    <x-filament::input.select inline-prefix wire:model.live="year">
                        @foreach ($this->getAvailableYears() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </div>

        <div class="p-4">
        <div
            @if ($pollingInterval = $this->getPollingInterval())
                wire:poll.{{ $pollingInterval }}="updateChartData"
            @endif
        >
            <div
                x-load
                x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                wire:ignore
                data-chart-type="{{ $type }}"
                x-data="chart({
                    cachedData: @js($this->getCachedData()),
                    maxHeight: @js($maxHeight = $this->getMaxHeight()),
                    options: @js($this->getOptions()),
                    type: @js($type),
                })"
                {{
                    (new ComponentAttributeBag)
                        ->color(ChartWidgetComponent::class, $color)
                        ->class([
                            'fi-wi-chart-canvas-ctn',
                            'fi-wi-chart-canvas-ctn-no-aspect-ratio' => filled($maxHeight),
                        ])
                }}
            >
                <canvas
                    x-ref="canvas"
                    @if ($maxHeight)
                        style="max-height: {{ $maxHeight }}"
                    @endif
                ></canvas>

                <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
            </div>
        </div>
        </div>

    </div>
</x-filament-widgets::widget>
