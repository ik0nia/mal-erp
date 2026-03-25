@php
    use Filament\Support\Facades\FilamentView;

    $color       = $this->getColor();
    $heading     = $this->getHeading();
    $description = $this->getDescription();
    $type        = $this->getType();
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section :description="$description" :heading="$heading">
        <x-slot name="headerEnd">
            <div style="display:flex; align-items:center; gap:0.5rem; margin:-0.5rem 0;">
                <x-filament::input.wrapper inline-prefix wire:target="mode" class="w-max">
                    <x-filament::input.select inline-prefix wire:model.live="mode">
                        <option value="revenue">Valoare (lei)</option>
                        <option value="orders">Comenzi (nr)</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <x-filament::input.wrapper inline-prefix wire:target="year" class="w-max">
                    <x-filament::input.select inline-prefix wire:model.live="year">
                        @foreach ($this->getAvailableYears() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </x-slot>

        <div
            @if ($pollingInterval = $this->getPollingInterval())
                wire:poll.{{ $pollingInterval }}="updateChartData"
            @endif
        >
            <div
                id="sales-chart-container"
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
                    type: @js($type),
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
                    id="sales-chart-canvas"
                    @if ($maxHeight = $this->getMaxHeight())
                        style="max-height: {{ $maxHeight }}"
                    @endif
                ></canvas>

                <span
                    x-ref="backgroundColorElement"
                    @class([
                        match ($color) {
                            'gray' => 'text-gray-100 dark:text-gray-800',
                            default => 'text-custom-50 dark:text-custom-400/10',
                        },
                    ])
                    @style([
                        \Filament\Support\get_color_css_variables(
                            $color,
                            shades: [50, 400],
                            alias: 'widgets::chart-widget.background',
                        ) => $color !== 'gray',
                    ])
                ></span>

                <span
                    x-ref="borderColorElement"
                    @class([
                        match ($color) {
                            'gray' => 'text-gray-400',
                            default => 'text-custom-500 dark:text-custom-400',
                        },
                    ])
                    @style([
                        \Filament\Support\get_color_css_variables(
                            $color,
                            shades: [400, 500],
                            alias: 'widgets::chart-widget.border',
                        ) => $color !== 'gray',
                    ])
                ></span>

                <span
                    x-ref="gridColorElement"
                    class="text-gray-200 dark:text-gray-800"
                ></span>

                <span
                    x-ref="textColorElement"
                    class="text-gray-500 dark:text-gray-400"
                ></span>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

<script>
(function() {
    function addDataLabels() {
        var canvas = document.getElementById('sales-chart-canvas');
        if (!canvas) return false;

        // Chart.js stores instance on canvas element
        var chartInstance = Chart.getChart(canvas);
        if (!chartInstance) return false;

        // Check if plugin already added
        if (chartInstance.__salesLabelsAdded) return true;
        chartInstance.__salesLabelsAdded = true;

        var origDraw = chartInstance.draw.bind(chartInstance);
        var origDrawFn = chartInstance.draw;

        // Override draw to add labels after each render
        chartInstance.draw = function() {
            origDrawFn.call(this);

            var ctx = this.ctx;
            var ds = this.data.datasets;
            if (!ds || !ds.length) return;

            ctx.save();
            ctx.font = 'bold 11px system-ui, -apple-system, sans-serif';
            ctx.fillStyle = '#374151';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';

            for (var i = 0; i < ds.length; i++) {
                var meta = this.getDatasetMeta(i);
                for (var idx = 0; idx < meta.data.length; idx++) {
                    var val = ds[i].data[idx];
                    if (val > 0) {
                        var bar = meta.data[idx];
                        var text = val >= 1000
                            ? Math.round(val).toLocaleString('ro-RO')
                            : String(Math.round(val));
                        ctx.fillText(text, bar.x, bar.y - 4);
                    }
                }
            }
            ctx.restore();
        };

        chartInstance.update();
        return true;
    }

    // Try repeatedly until Chart.js instance is ready
    var attempts = 0;
    var interval = setInterval(function() {
        try {
            if (typeof Chart !== 'undefined' && addDataLabels()) {
                clearInterval(interval);
            }
        } catch(e) {}
        if (++attempts > 100) clearInterval(interval);
    }, 300);
})();
</script>
