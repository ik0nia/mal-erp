<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;padding:8px">

    @php
        $sides = [
            ['label' => 'Produs existent', 'product' => $source, 'color' => 'blue'],
            ['label' => 'Propunere Toya',  'product' => $proposed, 'color' => 'green'],
        ];
        $colorMap = [
            'blue'  => ['bg' => '#eff6ff', 'text' => '#2563eb', 'border' => '#bfdbfe'],
            'green' => ['bg' => '#f0fdf4', 'text' => '#16a34a', 'border' => '#bbf7d0'],
        ];
    @endphp

    @foreach($sides as $side)
        @php $p = $side['product']; $color = $side['color']; $c = $colorMap[$color]; @endphp
        <div style="border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;display:flex;flex-direction:column">

            {{-- Header --}}
            <div style="padding:8px 16px;background:{{ $c['bg'] }};border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between">
                <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:{{ $c['text'] }}">{{ $side['label'] }}</span>
                @if($p)
                    <a href="{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $p->id]) }}"
                       target="_blank"
                       style="font-size:12px;color:#9ca3af;text-decoration:none;display:flex;align-items:center;gap:4px"
                       onmouseover="this.style.color='#4b5563'" onmouseout="this.style.color='#9ca3af'">
                        <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="w-3 h-3"/>
                        Deschide
                    </a>
                @endif
            </div>

            @if(!$p)
                <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:48px 0;color:#9ca3af;font-size:14px">Fără propunere</div>
            @else
                {{-- Imagine --}}
                <div style="display:flex;align-items:center;justify-content:center;background:#f9fafb;height:192px">
                    @php $img = $p->main_image_url ?: ($p->data['images'][0]['src'] ?? null); @endphp
                    @if($img)
                        <img src="{{ $img }}" alt="{{ $p->name }}" style="max-height:176px;max-width:100%;object-fit:contain;padding:8px">
                    @else
                        <x-filament::icon icon="heroicon-o-photo" class="w-16 h-16" style="color:#d1d5db"/>
                    @endif
                </div>

                {{-- Info --}}
                <div style="flex:1;padding:16px">

                    <div>
                        <p style="font-weight:600;font-size:14px;color:#111827;line-height:1.4">{{ $p->name }}</p>
                        <p style="font-size:12px;color:#6b7280;margin-top:2px">SKU: {{ $p->sku ?: '—' }}</p>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;column-gap:16px;row-gap:6px;font-size:12px;margin-top:12px">
                        @if($p->brand)
                            <div style="color:#6b7280">Marcă</div>
                            <div style="font-weight:500;color:#1f2937">{{ $p->brand }}</div>
                        @endif

                        @if($p->price)
                            <div style="color:#6b7280">Preț</div>
                            <div style="font-weight:500;color:#1f2937">{{ number_format($p->price, 2) }} lei</div>
                        @endif

                        @if($p->weight)
                            <div style="color:#6b7280">Greutate</div>
                            <div style="font-weight:500;color:#1f2937">{{ $p->weight }} kg</div>
                        @endif

                        @if($p->dim_length || $p->dim_width || $p->dim_height)
                            <div style="color:#6b7280">Dimensiuni</div>
                            <div style="font-weight:500;color:#1f2937">
                                {{ $p->dim_length }}×{{ $p->dim_width }}×{{ $p->dim_height }} cm
                            </div>
                        @endif

                        @if($side['label'] === 'Produs existent' && $p->suppliers?->count())
                            <div style="color:#6b7280">Furnizor</div>
                            <div style="font-weight:500;color:#1f2937">
                                {{ $p->suppliers->pluck('name')->implode(', ') }}
                            </div>
                        @endif

                        <div style="color:#6b7280">Status</div>
                        <div>
                            @if($p->status === 'publish')
                                <span style="display:inline-flex;align-items:center;padding:2px 6px;border-radius:4px;font-size:12px;font-weight:500;background:#dcfce7;color:#15803d">Publicat</span>
                            @else
                                <span style="display:inline-flex;align-items:center;padding:2px 6px;border-radius:4px;font-size:12px;font-weight:500;background:#fef9c3;color:#a16207">{{ $p->status ?: '—' }}</span>
                            @endif
                        </div>
                    </div>

                    @if($p->short_description || $p->description)
                        <div style="border-top:1px solid #f3f4f6;padding-top:12px;margin-top:12px">
                            <p style="font-size:12px;font-weight:500;color:#6b7280;margin-bottom:4px">Descriere</p>
                            <p style="font-size:12px;color:#374151;line-height:1.6;display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;overflow:hidden">
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
    <div style="margin:16px 8px 8px;border-radius:8px;background:#fffbeb;border:1px solid #fde68a;padding:12px 16px">
        <p style="font-size:12px;font-weight:600;color:#b45309;margin-bottom:4px;display:flex;align-items:center;gap:4px">
            <x-filament::icon icon="heroicon-o-sparkles" class="w-3.5 h-3.5"/> Motivare AI
            @if($record->confidence)
                <span style="margin-left:auto;font-weight:400;color:#d97706">
                    Încredere: {{ round($record->confidence * 100) }}%
                </span>
            @endif
        </p>
        <p style="font-size:12px;color:#92400e">{{ $record->reasoning }}</p>
    </div>
@endif
