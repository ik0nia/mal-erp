<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5 text-red-500" />
                <span>Produse fără stoc</span>
                @if($total > 0)
                    <x-filament::badge color="danger">{{ $total }}</x-filament::badge>
                @endif
            </div>
        </x-slot>

        @if(empty($rows))
            <p class="text-sm text-gray-500 py-4 text-center">Nu există produse fără stoc cu rulaj activ.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-xs text-gray-500 uppercase tracking-wide">
                            <th class="text-left py-2 pr-4 font-medium">Produs</th>
                            <th class="text-left py-2 pr-4 font-medium">SKU</th>
                            <th class="text-left py-2 pr-4 font-medium">Furnizor</th>
                            <th class="text-right py-2 pr-4 font-medium">Viteză/zi</th>
                            <th class="text-right py-2 font-medium">Vânz. 7 zile</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($rows as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                <td class="py-2 pr-4">
                                    <div class="flex items-center gap-2">
                                        @if($row->main_image_url)
                                            <img src="{{ $row->main_image_url }}" class="h-7 w-7 rounded object-cover ring-1 ring-gray-200/70 shrink-0" loading="lazy" />
                                        @else
                                            <span class="inline-flex h-7 w-7 items-center justify-center rounded bg-gray-100 text-gray-400 shrink-0">—</span>
                                        @endif
                                        <a href="{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $row->product_id]) }}"
                                           class="font-medium text-gray-900 dark:text-gray-100 hover:text-primary-600 line-clamp-2 max-w-xs">
                                            {{ html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') }}
                                        </a>
                                    </div>
                                </td>
                                <td class="py-2 pr-4 font-mono text-xs text-gray-500">{{ $row->sku }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-400 text-xs">{{ $row->supplier_name }}</td>
                                <td class="py-2 pr-4 text-right font-mono text-xs text-orange-600 font-semibold">
                                    {{ number_format($row->velocity_day, 2, ',', '.') }}/zi
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-red-600 font-semibold">
                                    {{ number_format($row->velocity_7d, 1, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($total > 50)
                <p class="text-xs text-gray-400 mt-3 text-right">Afișate 50 din {{ $total }}. Sortate după viteză descrescătoare.</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
