@php
    /** @var \App\Models\WooOrder $record */
    $record   = $getRecord();
    $editable = $this->isOrderEditable();

    $stockFor = function ($item) use ($record) {
        if (! $item->woo_product_id) return [null, 'gray'];
        $localId = \App\Models\WooProduct::where('woo_id', $item->woo_product_id)->value('id');
        if (! $localId) return [null, 'gray'];
        $qty = \App\Models\ProductStock::where('woo_product_id', $localId)
            ->when((int) $record->location_id > 0, fn ($q) => $q->where('location_id', $record->location_id))
            ->value('quantity');
        if ($qty === null) return [null, 'gray'];
        return [(float) $qty, (float) $qty >= (float) $item->quantity ? 'ok' : 'low'];
    };

    $vatRates = collect($this->buildEditableItems())->keyBy('woo_item_id');
    $shipLine = collect($record->data['shipping_lines'] ?? [])->first();
    $shipGross = round((float) $record->shipping_total + (float) data_get($record->data, 'shipping_tax', (float) $record->shipping_total * 0.21), 2);
@endphp

<style>
.oie-table{width:100%;border-collapse:collapse;font-size:.875rem;}
.oie-table th{padding:.5rem .75rem;text-align:left;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;border-bottom:1px solid #e5e7eb;}
.oie-table td{padding:.55rem .75rem;border-bottom:1px solid #f3f4f6;color:#374151;vertical-align:middle;}
.oie-badge{display:inline-block;padding:.1rem .5rem;border-radius:.375rem;font-size:.72rem;font-weight:700;}
.oie-ok{background:#dcfce7;color:#15803d;} .oie-low{background:#fee2e2;color:#b91c1c;} .oie-gray{background:#f3f4f6;color:#6b7280;}
.oie-icon{border:none;background:transparent;cursor:pointer;padding:.25rem .4rem;border-radius:.375rem;font-size:.95rem;line-height:1;color:#9ca3af;}
.oie-icon:hover{background:#eef2ff;color:#4f46e5;}
.oie-icon.oie-del:hover{background:#fee2e2;color:#dc2626;}
.oie-add{font-size:.83rem;font-weight:600;color:#4f46e5;border:1px dashed #c7d2fe;background:#fff;border-radius:.5rem;padding:.4rem .9rem;cursor:pointer;}
.oie-add:hover{background:#eef2ff;}
.oie-totals{margin-left:auto;font-size:.875rem;min-width:16rem;}
.oie-totals td{padding:.2rem .75rem;border:none;}
</style>

<div>
  <table class="oie-table">
    <thead>
      <tr>
        <th>Produs</th>
        <th style="text-align:center;">Stoc ERP</th>
        <th style="text-align:right;">Preț cu TVA</th>
        <th style="text-align:right;">Cant.</th>
        <th style="text-align:right;">Total</th>
        @if($editable)<th style="width:4.5rem;"></th>@endif
      </tr>
    </thead>
    <tbody>
      @foreach($record->items as $item)
        @php
            [$stock, $stockClass] = $stockFor($item);
            $row   = $vatRates->get($item->woo_item_id);
            $gross = $row['price_gross'] ?? round((float) $item->price * 1.21, 2);
        @endphp
        <tr wire:key="oie-{{ $item->woo_item_id }}">
          <td>
            <div style="font-weight:600;color:#111827;">{{ $item->name }}</div>
            <div style="font-size:.75rem;color:#9ca3af;font-family:monospace;">{{ $item->sku ?: 'fără SKU' }}</div>
          </td>
          <td style="text-align:center;">
            <span class="oie-badge oie-{{ $stockClass }}">{{ $stock === null ? '–' : (floor($stock) == $stock ? number_format($stock, 0) : number_format($stock, 2)) }}</span>
          </td>
          <td style="text-align:right;">{{ number_format($gross, 2) }}</td>
          <td style="text-align:right;">{{ floor($item->quantity) == $item->quantity ? number_format($item->quantity, 0) : number_format($item->quantity, 2) }}</td>
          <td style="text-align:right;font-weight:600;">{{ number_format($gross * (float) $item->quantity, 2) }}</td>
          @if($editable)
            <td style="text-align:right;white-space:nowrap;">
              <button type="button" class="oie-icon" title="Editează cantitatea/prețul"
                      wire:click="mountAction('editLine', { itemId: {{ (int) $item->woo_item_id }} })">✎</button>
              @if($record->items->count() > 1)
                <button type="button" class="oie-icon oie-del" title="Șterge din comandă"
                        wire:click="deleteOrderProduct({{ (int) $item->woo_item_id }})"
                        wire:confirm="Ștergi „{{ $item->name }}" din comandă? Poți reveni din Istoricul modificărilor."
                        wire:loading.attr="disabled">✕</button>
              @endif
            </td>
          @endif
        </tr>
      @endforeach
      @if($shipLine)
        <tr>
          <td colspan="2" style="color:#6b7280;">🚚 {{ $shipLine['method_title'] ?? 'Transport' }}</td>
          <td></td><td></td>
          <td style="text-align:right;">{{ number_format((float) $record->shipping_total * 1.21, 2) }}</td>
          @if($editable)
            <td style="text-align:right;">
              <button type="button" class="oie-icon" title="Editează transportul (în Livrare & client)"
                      wire:click="mountAction('editAddress')">✎</button>
            </td>
          @endif
        </tr>
      @endif
    </tbody>
  </table>

  <div style="display:flex;align-items:flex-start;gap:1rem;margin-top:.75rem;">
    @if($editable)
      <button type="button" class="oie-add" wire:click="mountAction('addProduct')">+ Adaugă produs</button>
    @endif
    <table class="oie-totals">
      <tr><td style="color:#6b7280;">Subtotal (fără TVA)</td><td style="text-align:right;">{{ number_format((float) $record->subtotal, 2) }}</td></tr>
      <tr><td style="color:#6b7280;">Transport (fără TVA)</td><td style="text-align:right;">{{ number_format((float) $record->shipping_total, 2) }}</td></tr>
      @if((float) $record->discount_total > 0)
        <tr><td style="color:#6b7280;">Discount</td><td style="text-align:right;">−{{ number_format((float) $record->discount_total, 2) }}</td></tr>
      @endif
      <tr><td style="color:#6b7280;">TVA</td><td style="text-align:right;">{{ number_format((float) $record->tax_total, 2) }}</td></tr>
      <tr><td style="font-weight:700;color:#111827;border-top:1px solid #e5e7eb;">Total</td><td style="text-align:right;font-weight:700;color:#111827;border-top:1px solid #e5e7eb;">{{ number_format((float) $record->total, 2) }} {{ $record->currency }}</td></tr>
    </table>
  </div>
</div>
