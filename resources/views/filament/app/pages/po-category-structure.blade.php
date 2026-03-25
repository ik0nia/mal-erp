<div
    x-data="{
        syncToWire() {
            const qtys = {};
            document.querySelectorAll('.po-qty-input').forEach(function(el) {
                const v = parseFloat(el.value);
                if (!isNaN(v) && v > 0) qtys[el.dataset.pid] = v;
            });
            $wire.set('mountedActionsData.0.qtys_json', JSON.stringify(qtys));
        }
    }"
    style="padding: 4px;"
>

    @php
        $grandTotal = 0;
        $totalProducts = 0;
        foreach ($categories as $products) {
            $totalProducts += count($products);
            foreach ($products as $p) { $grandTotal += $p['recommended']; }
        }

        // Helper: format quantity - no decimals for integers, 2 decimals otherwise
        $fmtQty = function($val) {
            if ($val === null || $val === '') return '—';
            $val = (float) $val;
            return floor($val) == $val ? number_format($val, 0, '.', '') : number_format($val, 2, '.', '');
        };
    @endphp

    {{-- Summary bar --}}
    <div style="display: flex; align-items: center; gap: 24px; padding: 12px 16px; border-radius: 12px; background: #f9fafb; border: 1px solid #e5e7eb; margin-bottom: 16px;">
        <div style="font-size: 13px; color: #6b7280;">
            <span style="font-weight: 700; color: #111827; font-size: 15px;">{{ count($categories) }}</span> categorii
        </div>
        <div style="font-size: 13px; color: #6b7280;">
            <span style="font-weight: 700; color: #111827; font-size: 15px;">{{ $totalProducts }}</span> produse cu rulaj
        </div>
        <div style="font-size: 13px; color: #6b7280; margin-left: auto;">
            Total recomandat: <span style="font-weight: 700; color: #8B1A1A; font-size: 15px;">{{ $fmtQty($grandTotal) }}</span> buc
        </div>
    </div>

    @foreach($categories as $catName => $products)
        @php $catTotal = array_sum(array_column($products, 'recommended')); @endphp

        <details style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 4px;" open>
            <summary style="display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; cursor: pointer; background: #eef2ff; user-select: none;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-weight: 600; font-size: 13px; color: #3730a3;">{{ $catName }}</span>
                    <span style="font-size: 11px; color: #6366f1; font-weight: 400;">({{ count($products) }} produse)</span>
                </div>
                <span style="font-size: 11px; font-weight: 700; color: #4338ca; background: #c7d2fe; padding: 2px 10px; border-radius: 12px;">
                    {{ $fmtQty($catTotal) }} buc recomandat
                </span>
            </summary>

            <table style="width: 100%; font-size: 12px; border-collapse: collapse; border-top: 1px solid #e5e7eb;">
                <thead>
                    <tr style="background: #f9fafb;">
                        <th style="padding: 8px 12px; text-align: left; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; width: 35%;">Produs</th>
                        <th style="padding: 8px 12px; text-align: left; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280;">SKU Furnizor</th>
                        <th style="padding: 8px 12px; text-align: right; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280;">Stoc</th>
                        <th style="padding: 8px 12px; text-align: right; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280;">Vânz. 7z</th>
                        <th style="padding: 8px 12px; text-align: right; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280;">Vânz. 30z</th>
                        <th style="padding: 8px 12px; text-align: right; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #8B1A1A;">Recomandat</th>
                        <th style="padding: 8px 12px; text-align: right; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #15803d;">Cantitate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products as $p)
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 8px 12px;">
                                <div style="font-weight: 500; color: #1f2937; line-height: 1.4;">{{ $p['name'] }}</div>
                                <div style="color: #9ca3af; font-size: 10px;">{{ $p['sku'] }}</div>
                            </td>
                            <td style="padding: 8px 12px; color: #6b7280;">{{ $p['supplier_sku'] ?: '—' }}</td>
                            <td style="padding: 8px 12px; text-align: right;">
                                <span style="{{ $p['stock'] <= 0 ? 'color: #dc2626; font-weight: 600;' : 'color: #374151;' }}">
                                    {{ $fmtQty($p['stock']) }}
                                </span>
                            </td>
                            <td style="padding: 8px 12px; text-align: right; color: #4b5563;">
                                {{ $fmtQty($p['sales_7d']) }}
                            </td>
                            <td style="padding: 8px 12px; text-align: right; color: #4b5563;">
                                {{ $fmtQty($p['sales_30d']) }}
                            </td>
                            <td style="padding: 8px 12px; text-align: right;">
                                @if($p['recommended'] > 0)
                                    <span style="font-weight: 700; color: #8B1A1A; background: #fef2f2; padding: 2px 8px; border-radius: 4px;">
                                        {{ $fmtQty($p['recommended']) }}
                                    </span>
                                @else
                                    <span style="color: #9ca3af;">—</span>
                                @endif
                            </td>
                            <td style="padding: 8px 12px; text-align: right;">
                                <input
                                    type="number"
                                    class="po-qty-input"
                                    style="width: 80px; text-align: right; border: 1px solid #d1d5db; border-radius: 4px; padding: 3px 6px; font-size: 12px; background: #fff; color: #111827; outline: none;"
                                    data-pid="{{ $p['woo_product_id'] }}"
                                    value="{{ $currentQtys[$p['woo_product_id']] ?? ($p['recommended'] > 0 ? $p['recommended'] : '') }}"
                                    min="0"
                                    step="1"
                                    placeholder="0"
                                    x-on:input="syncToWire()"
                                >
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endforeach

    @if(empty($categories))
        <div style="text-align: center; padding: 48px 0; color: #9ca3af; font-size: 13px;">
            Niciun produs cu rulaj gasit pentru acest furnizor.
        </div>
    @endif

</div>
