@php
    use Filament\Support\Facades\FilamentView;

    $color       = $this->getColor();
    $heading     = $this->getHeading();
    $description = $this->getDescription();
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section :description="$description" :heading="$heading">

        <x-slot name="headerEnd">
            <div class="flex items-center gap-2 sm:-my-2">

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
        </x-slot>

        <div>
            <div
                @if (FilamentView::hasSpaMode())
                    x-load="visible"
                @else
                    x-load
                @endif
                x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                wire:ignore
                x-data="chart({
                    cachedData: @js($this->getCachedData()),
                    options: @js($this->getOptions()),
                    type: @js($this->getType()),
                })"
                @class([
                    match ($color) {
                        'gray' => null,
                        default => 'fi-color-custom',
                    },
                    is_string($color) ? "fi-color-{$color}" : null,
                ])
            >
                <canvas
                    x-ref="canvas"
                    @if ($maxHeight = $this->getMaxHeight())
                        style="max-height: {{ $maxHeight }}"
                    @endif
                ></canvas>

                <span x-ref="backgroundColorElement"
                    @class([
                        match ($color) {
                            'gray' => 'text-gray-100 dark:text-gray-800',
                            default => 'text-custom-50 dark:text-custom-400/10',
                        },
                    ])
                    @style([
                        \Filament\Support\get_color_css_variables($color, shades: [50, 400], alias: 'widgets::chart-widget.background') => $color !== 'gray',
                    ])
                ></span>

                <span x-ref="borderColorElement"
                    @class([
                        match ($color) {
                            'gray' => 'text-gray-400',
                            default => 'text-custom-500 dark:text-custom-400',
                        },
                    ])
                    @style([
                        \Filament\Support\get_color_css_variables($color, shades: [400, 500], alias: 'widgets::chart-widget.border') => $color !== 'gray',
                    ])
                ></span>

                <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-800"></span>
                <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
            </div>
        </div>


    </x-filament::section>
</x-filament-widgets::widget>
