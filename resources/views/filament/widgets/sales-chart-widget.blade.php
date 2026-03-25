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
document.addEventListener('DOMContentLoaded', function() {
    // Hook into Chart.js to add data labels on bar charts for sales widget
    const origDrawDatasetPointsOfType = Chart.prototype.draw;
    if (window.__salesChartPatched) return;
    window.__salesChartPatched = true;

    const plugin = {
        id: 'salesBarLabels',
        afterDatasetsDraw(chart) {
            // Only apply to bar charts in sales widget container
            if (chart.config.type !== 'bar') return;
            const canvas = chart.canvas;
            if (!canvas.closest('.fi-wi-chart')) return;
            // Check if this is the sales chart (has single dataset with Vânzări/Comenzi label)
            const ds = chart.data.datasets;
            if (!ds || ds.length !== 1) return;
            const label = ds[0].label || '';
            if (!label.includes('Vânzări') && !label.includes('Comenzi')) return;

            const ctx = chart.ctx;
            ctx.save();
            ctx.font = 'bold 11px system-ui, -apple-system, sans-serif';
            ctx.fillStyle = '#374151';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';

            const meta = chart.getDatasetMeta(0);
            meta.data.forEach(function(bar, idx) {
                const val = ds[0].data[idx];
                if (val > 0) {
                    const text = val >= 1000
                        ? Math.round(val).toLocaleString('ro-RO')
                        : String(Math.round(val));
                    ctx.fillText(text, bar.x, bar.y - 4);
                }
            });
            ctx.restore();
        }
    };

    // Register globally — Chart.js will pick it up for all charts
    if (typeof Chart !== 'undefined') {
        Chart.register(plugin);
    } else {
        // Chart.js might not be loaded yet, wait for it
        let attempts = 0;
        const interval = setInterval(function() {
            if (typeof Chart !== 'undefined') {
                Chart.register(plugin);
                clearInterval(interval);
            }
            if (++attempts > 50) clearInterval(interval);
        }, 200);
    }
});
</script>
