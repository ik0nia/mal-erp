@php $data = $this->getData(); @endphp

@if($data['total'] > 0)
<style>
.wm-stats { display:flex; flex-wrap:wrap; gap:0.75rem; }
.wm-stat  { flex:1 1 calc(50% - 0.375rem); min-width:0; }
@@media(min-width:768px){ .wm-stat { flex:1 1 0; } }
</style>
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="wm-stats">
            @foreach($data['stats'] as $stat)
            <a href="{{ $data['pageUrl'] }}"
               class="wm-stat"
               style="border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; padding:1rem 1rem 0.75rem; box-shadow:0 1px 2px rgba(0,0,0,0.05); text-decoration:none; display:block; transition:box-shadow 0.15s;">
                <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.25rem;">
                    <x-filament::icon
                        :icon="$stat['icon']"
                        @class([
                            'h-4 w-4 shrink-0',
                            'text-warning-500' => $stat['color'] === 'warning',
                            'text-success-500' => $stat['color'] === 'success',
                            'text-danger-500'  => $stat['color'] === 'danger',
                        ])
                    />
                    <span style="font-size:0.75rem; font-weight:500; color:#6b7280; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                        {{ $stat['label'] }}
                    </span>
                </div>
                <div style="font-size:1.5rem; font-weight:700; {{ $stat['color'] === 'warning' ? 'color:#d97706;' : ($stat['color'] === 'success' ? 'color:#16a34a;' : 'color:#dc2626;') }}">
                    {{ $stat['value'] }}
                </div>
            </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
@endif
