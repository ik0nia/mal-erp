<style>
.ls-table { width:100%; font-size:0.875rem; border-collapse:collapse; table-layout:fixed; }
.ls-table th { padding:0.5rem 0.75rem; text-align:left; font-size:0.75rem; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #e5e7eb; }
.ls-table td { padding:0.625rem 0.75rem; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
.ls-table tr:hover td { background:#f9fafb; }
.ls-table .col-product { width:30%; }
.ls-table .col-sku { width:17%; }
.ls-table .col-supplier { width:17%; }
.ls-table .col-stock { width:12%; text-align:right; }
.ls-table .col-speed { width:12%; text-align:right; }
.ls-table .col-days { width:12%; text-align:right; }
.ls-product-cell { display:flex; align-items:center; gap:0.5rem; }
.ls-product-img { height:2rem; width:2rem; border-radius:0.25rem; object-fit:cover; flex-shrink:0; border:1px solid #e5e7eb; }
.ls-product-placeholder { display:inline-flex; height:2rem; width:2rem; align-items:center; justify-content:center; border-radius:0.25rem; background:#f3f4f6; color:#9ca3af; flex-shrink:0; font-size:0.75rem; }
.ls-product-link { font-weight:500; color:#111827; text-decoration:none; display:block; overflow:hidden; text-overflow:ellipsis; }
.ls-product-link:hover { color:#dc2626; text-decoration:underline; }
.ls-sku { font-family:monospace; font-size:0.875rem; color:#111827; cursor:pointer; }
.ls-supplier-link { color:#4b5563; text-decoration:none; font-size:0.8125rem; }
.ls-supplier-link:hover { color:#dc2626; text-decoration:underline; }
.ls-days-badge { display:inline-flex; align-items:center; padding:0.125rem 0.5rem; border-radius:0.25rem; font-size:0.75rem; font-weight:700; }
.ls-days-critical { background:#fee2e2; color:#dc2626; }
.ls-days-warning { background:#ffedd5; color:#c2410c; }
.ls-days-caution { background:#fef3c7; color:#a16207; }
</style>

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <x-filament::icon icon="heroicon-o-arrow-trending-down" class="h-5 w-5 text-warning-500" />
                <span>Produse care se epuizează</span>
                @if($total > 0)
                    <x-filament::badge color="warning">{{ $total }}</x-filament::badge>
                @endif
            </div>
        </x-slot>

        @if(empty($rows))
            <p style="text-align:center; color:#6b7280; padding:2rem 0; font-size:0.875rem;">Nu există produse cu stoc critic.</p>
        @else
            <div style="overflow-x:auto;">
                <table class="ls-table">
                    <thead>
                        <tr>
                            <th class="col-product">Produs</th>
                            <th class="col-sku">SKU</th>
                            <th class="col-supplier">Furnizor</th>
                            <th class="col-stock" style="text-align:right;">Stoc actual</th>
                            <th class="col-speed" style="text-align:right;">Viteză/zi</th>
                            <th class="col-days" style="text-align:right;">Zile rămase</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            @php
                                $days = (float) $row->days_to_stockout;
                                $daysCls = $days <= 3 ? 'ls-days-critical' : ($days <= 7 ? 'ls-days-warning' : 'ls-days-caution');
                            @endphp
                            <tr>
                                <td>
                                    <div class="ls-product-cell">
                                        @if($row->main_image_url)
                                            <img src="{{ $row->main_image_url }}" class="ls-product-img" loading="lazy" />
                                        @else
                                            <span class="ls-product-placeholder">—</span>
                                        @endif
                                        <a href="{{ \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $row->product_id]) }}"
                                           class="ls-product-link">
                                            {{ html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') }}
                                        </a>
                                    </div>
                                </td>
                                <td class="ls-sku" onclick="navigator.clipboard.writeText('{{ $row->sku }}');new FilamentNotification().title('Copiat!').success().duration(2000).send()">{{ $row->sku }}</td>
                                <td>
                                    <a href="{{ \App\Filament\App\Resources\SupplierResource::getUrl('view', ['record' => $row->supplier_id]) }}" class="ls-supplier-link">
                                        {{ $row->supplier_name }}
                                    </a>
                                </td>
                                <td style="text-align:right; font-family:monospace; font-size:0.8125rem; font-weight:600;">
                                    {{ number_format($row->stock, 0, '.', '') }}
                                </td>
                                <td style="text-align:right; font-family:monospace; font-size:0.8125rem; color:#d97706;">
                                    {{ number_format($row->velocity_day, 2, '.', '') }}/zi
                                </td>
                                <td style="text-align:right;">
                                    <span class="ls-days-badge {{ $daysCls }}">
                                        {{ number_format($days, 1, '.', '') }} zile
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($total > 50)
                <p style="font-size:0.75rem; color:#9ca3af; margin-top:0.75rem; text-align:right;">Afișate 50 din {{ $total }}. Sortate după urgență.</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
