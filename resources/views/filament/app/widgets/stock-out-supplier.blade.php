<style>
.so-table { width:100%; font-size:0.875rem; border-collapse:collapse; table-layout:fixed; }
.so-table th { padding:0.5rem 0.75rem; text-align:left; font-size:0.75rem; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #e5e7eb; }
.so-table td { padding:0.625rem 0.75rem; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
.so-table tr:hover td { background:#f9fafb; }
.so-table .col-product { width:35%; }
.so-table .col-sku { width:20%; }
.so-table .col-supplier { width:20%; }
.so-table .col-speed { width:12%; text-align:right; }
.so-table .col-sales { width:13%; text-align:right; }
.so-product-cell { display:flex; align-items:center; gap:0.5rem; }
.so-product-img { height:2rem; width:2rem; border-radius:0.25rem; object-fit:cover; flex-shrink:0; border:1px solid #e5e7eb; }
.so-product-placeholder { display:inline-flex; height:2rem; width:2rem; align-items:center; justify-content:center; border-radius:0.25rem; background:#f3f4f6; color:#9ca3af; flex-shrink:0; font-size:0.75rem; }
.so-product-link { font-weight:500; color:#111827; text-decoration:none; display:block; overflow:hidden; text-overflow:ellipsis; }
.so-product-link:hover { color:#dc2626; text-decoration:underline; }
.so-sku { font-family:monospace; font-size:0.875rem; color:#111827; cursor:pointer; }
.so-supplier { font-size:0.8125rem; color:#4b5563; }
.so-supplier-link { color:#4b5563; text-decoration:none; font-size:0.8125rem; }
.so-supplier-link:hover { color:#dc2626; text-decoration:underline; }
.so-speed { font-family:monospace; font-size:0.8125rem; color:#d97706; font-weight:600; text-align:right; }
.so-sales { font-family:monospace; font-size:0.8125rem; color:#dc2626; font-weight:600; text-align:right; }
</style>

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-danger-500" />
                <span>Produse fără stoc</span>
                @if($total > 0)
                    <x-filament::badge color="danger">{{ $total }}</x-filament::badge>
                @endif
            </div>
        </x-slot>

        @if(empty($rows))
            <p style="text-align:center; color:#6b7280; padding:2rem 0; font-size:0.875rem;">Nu există produse fără stoc cu rulaj activ.</p>
        @else
            <div style="overflow-x:auto;">
                <table class="so-table">
                    <thead>
                        <tr>
                            <th class="col-product">Produs</th>
                            <th class="col-sku">SKU</th>
                            <th class="col-supplier">Furnizor</th>
                            <th class="col-speed" style="text-align:right;">Viteză/zi</th>
                            <th class="col-sales" style="text-align:right;">Vânz. 7 zile</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td>
                                    <div class="so-product-cell">
                                        @if($row->main_image_url)
                                            <img src="{{ $row->main_image_url }}" class="so-product-img" loading="lazy" />
                                        @else
                                            <span class="so-product-placeholder">—</span>
                                        @endif
                                        <a href="{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $row->product_id]) }}"
                                           class="so-product-link">
                                            {{ html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') }}
                                        </a>
                                    </div>
                                </td>
                                <td class="so-sku" onclick="navigator.clipboard.writeText('{{ $row->sku }}');new FilamentNotification().title('Copiat!').success().duration(2000).send()">{{ $row->sku }}</td>
                                <td>
                                    <a href="{{ \App\Filament\App\Resources\SupplierResource::getUrl('view', ['record' => $row->supplier_id]) }}" class="so-supplier-link">
                                        {{ $row->supplier_name }}
                                    </a>
                                </td>
                                <td class="so-speed">{{ number_format($row->velocity_day, 2, ',', '.') }}/zi</td>
                                <td class="so-sales">{{ number_format($row->velocity_7d, 1, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($total > 50)
                <p style="font-size:0.75rem; color:#9ca3af; margin-top:0.75rem; text-align:right;">Afișate 50 din {{ $total }}. Sortate după viteză descrescătoare.</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
