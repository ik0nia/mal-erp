<div class="grid grid-cols-2 gap-6 p-2">

    @php
        $sides = [
            ['label' => 'Produs existent', 'product' => $source, 'color' => 'blue'],
            ['label' => 'Propunere Toya',  'product' => $proposed, 'color' => 'green'],
        ];
    @endphp

    @foreach($sides as $side)
        @php $p = $side['product']; $color = $side['color']; @endphp
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden flex flex-col">

            {{-- Header --}}
            <div class="px-4 py-2 bg-{{ $color }}-50 dark:bg-{{ $color }}-900/20 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wide text-{{ $color }}-600 dark:text-{{ $color }}-400">{{ $side['label'] }}</span>
                @if($p)
                    <a href="{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $p->id]) }}"
                       target="_blank"
                       class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                        <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="w-3 h-3"/>
                        Deschide
                    </a>
                @endif
            </div>

            @if(!$p)
                <div class="flex-1 flex items-center justify-center py-12 text-gray-400 text-sm">Fără propunere</div>
            @else
                {{-- Imagine --}}
                <div class="flex items-center justify-center bg-gray-50 dark:bg-gray-800 h-48">
                    @php $img = $p->main_image_url ?: ($p->data['images'][0]['src'] ?? null); @endphp
                    @if($img)
                        <img src="{{ $img }}" alt="{{ $p->name }}" class="max-h-44 max-w-full object-contain p-2">
                    @else
                        <x-filament::icon icon="heroicon-o-photo" class="w-16 h-16 text-gray-300"/>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 p-4 space-y-3">

                    <div>
                        <p class="font-semibold text-sm text-gray-900 dark:text-gray-100 leading-snug">{{ $p->name }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">SKU: {{ $p->sku ?: '—' }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                        @if($p->brand)
                            <div class="text-gray-500">Marcă</div>
                            <div class="font-medium text-gray-800 dark:text-gray-200">{{ $p->brand }}</div>
                        @endif

                        @if($p->price)
                            <div class="text-gray-500">Preț</div>
                            <div class="font-medium text-gray-800 dark:text-gray-200">{{ number_format($p->price, 2) }} lei</div>
                        @endif

                        @if($p->weight)
                            <div class="text-gray-500">Greutate</div>
                            <div class="font-medium text-gray-800 dark:text-gray-200">{{ $p->weight }} kg</div>
                        @endif

                        @if($p->dim_length || $p->dim_width || $p->dim_height)
                            <div class="text-gray-500">Dimensiuni</div>
                            <div class="font-medium text-gray-800 dark:text-gray-200">
                                {{ $p->dim_length }}×{{ $p->dim_width }}×{{ $p->dim_height }} cm
                            </div>
                        @endif

                        @if($side['label'] === 'Produs existent' && $p->suppliers?->count())
                            <div class="text-gray-500">Furnizor</div>
                            <div class="font-medium text-gray-800 dark:text-gray-200">
                                {{ $p->suppliers->pluck('name')->implode(', ') }}
                            </div>
                        @endif

                        <div class="text-gray-500">Status</div>
                        <div>
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium
                                {{ $p->status === 'publish' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                                {{ $p->status === 'publish' ? 'Publicat' : ($p->status ?: '—') }}
                            </span>
                        </div>
                    </div>

                    @if($p->short_description || $p->description)
                        <div class="border-t border-gray-100 dark:border-gray-700 pt-3">
                            <p class="text-xs font-medium text-gray-500 mb-1">Descriere</p>
                            <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed line-clamp-5">
                                {{ strip_tags($p->short_description ?: $p->description) }}
                            </p>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endforeach

</div>

{{-- AI reasoning --}}
@if($record->reasoning)
    <div class="mt-4 mx-2 mb-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 px-4 py-3">
        <p class="text-xs font-semibold text-amber-700 dark:text-amber-400 mb-1 flex items-center gap-1">
            <x-filament::icon icon="heroicon-o-sparkles" class="w-3.5 h-3.5"/> Motivare AI
            @if($record->confidence)
                <span class="ml-auto font-normal text-amber-600">
                    Încredere: {{ round($record->confidence * 100) }}%
                </span>
            @endif
        </p>
        <p class="text-xs text-amber-800 dark:text-amber-300">{{ $record->reasoning }}</p>
    </div>
@endif
