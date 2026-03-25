@php
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\View\ComponentAttributeBag;

    $color       = $this->getColor();
    $heading     = $this->getHeading();
    $description = $this->getDescription();
    $type        = $this->getType();
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section :description="$description" :heading="$heading">
        <x-slot name="afterHeader">
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <x-filament::input.wrapper inline-prefix wire:target="mode" class="fi-wi-chart-filter">
                    <x-filament::input.select inline-prefix wire:model.live="mode">
                        <option value="revenue">Valoare (lei)</option>
                        <option value="orders">Comenzi (nr)</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <x-filament::input.wrapper inline-prefix wire:target="year" class="fi-wi-chart-filter">
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
                    id="sales-chart-canvas"
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
    </x-filament::section>
</x-filament-widgets::widget>

<script>
(function() {
    function addDataLabels() {
        var canvas = document.getElementById('sales-chart-canvas');
        if (!canvas || typeof Chart === 'undefined') return false;
        var chartInstance = Chart.getChart(canvas);
        if (!chartInstance) return false;
        if (chartInstance.__salesLabelsAdded) return true;
        chartInstance.__salesLabelsAdded = true;

        var origDraw = chartInstance.draw;
        chartInstance.draw = function() {
            origDraw.call(this);
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

    var attempts = 0;
    var interval = setInterval(function() {
        try { if (addDataLabels()) clearInterval(interval); } catch(e) {}
        if (++attempts > 100) clearInterval(interval);
    }, 300);
})();
</script>
