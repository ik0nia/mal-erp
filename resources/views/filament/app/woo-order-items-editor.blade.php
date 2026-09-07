@php
    /** @var \App\Models\WooOrder $record */
    $record   = $getRecord();
    $editable = $this->isOrderEditable();

    // stoc ERP per item (o singură interogare per produs)
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
@endphp

<style>
.oie-table{width:100%;border-collapse:collapse;font-size:.875rem;}
.oie-table th{padding:.5rem .75rem;text-align:left;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;border-bottom:1px solid #e5e7eb;}
.oie-table td{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;color:#374151;vertical-align:middle;}
.oie-input{width:6.5rem;border:1px solid #d1d5db;border-radius:.4rem;padding:.3rem .5rem;font-size:.85rem;text-align:right;background:#fff;}
.oie-input:focus{border-color:#6366f1;outline:none;box-shadow:0 0 0 2px rgba(99,102,241,.15);}
.oie-qty{width:4.5rem;}
.oie-x{border:none;background:transparent;color:#d1d5db;font-size:1.1rem;cursor:pointer;padding:.2rem .45rem;border-radius:.375rem;line-height:1;}
.oie-x:hover{color:#dc2626;background:#fee2e2;}
.oie-badge{display:inline-block;padding:.1rem .5rem;border-radius:.375rem;font-size:.72rem;font-weight:700;}
.oie-ok{background:#dcfce7;color:#15803d;} .oie-low{background:#fee2e2;color:#b91c1c;} .oie-gray{background:#f3f4f6;color:#6b7280;}
.oie-save{font-size:.85rem;font-weight:600;color:#fff;background:#4f46e5;border:none;border-radius:.5rem;padding:.45rem 1rem;cursor:pointer;}
.oie-save:hover{background:#4338ca;} .oie-save:disabled{opacity:.5;cursor:wait;}
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
        @if($editable)<th style="width:2.5rem;"></th>@endif
      </tr>
    </thead>
    <tbody>
      @foreach($record->items as $item)
        @php
            [$stock, $stockClass] = $stockFor($item);
            $id    = (int) $item->woo_item_id;
            $vat   = $this->itemVat[$id] ?? 21;
            $gross = $this->itemPrice[$id] ?? round((float) $item->price * (1 + $vat / 100), 2);
            $qty   = $this->itemQty[$id] ?? (int) $item->quantity;
        @endphp
        <tr wire:key="oie-{{ $id }}">
          <td>
            <div style="font-weight:600;color:#111827;">{{ $item->name }}</div>
            <div style="font-size:.75rem;color:#9ca3af;font-family:monospace;">{{ $item->sku ?: 'fără SKU' }}</div>
          </td>
          <td style="text-align:center;">
            <span class="oie-badge oie-{{ $stockClass }}">{{ $stock === null ? '–' : (floor($stock) == $stock ? number_format($stock, 0) : number_format($stock, 2)) }}</span>
          </td>
          @if($editable)
            <td style="text-align:right;"><input type="number" step="0.01" min="0" class="oie-input" wire:model="itemPrice.{{ $id }}"></td>
            <td style="text-align:right;"><input type="number" step="1" min="1" class="oie-input oie-qty" wire:model="itemQty.{{ $id }}"></td>
            <td style="text-align:right;font-weight:600;">{{ number_format((float) $gross * (float) $qty, 2) }}</td>
            <td style="text-align:center;">
              @if($record->items->count() > 1)
                <button type="button" class="oie-x" title="Șterge {{ $item->name }} din comandă"
                        wire:click="deleteOrderProduct({{ $id }})"
                        wire:confirm="Ștergi „{{ $item->name }}" din comandă? Poți reveni din Istoricul modificărilor."
                        wire:loading.attr="disabled">✕</button>
              @endif
            </td>
          @else
            <td style="text-align:right;">{{ number_format((float) $item->price * (1 + $vat / 100), 2) }}</td>
            <td style="text-align:right;">{{ floor($item->quantity) == $item->quantity ? number_format($item->quantity, 0) : number_format($item->quantity, 2) }}</td>
            <td style="text-align:right;font-weight:600;">{{ number_format((float) $item->total * (1 + $vat / 100), 2) }}</td>
          @endif
        </tr>
      @endforeach
    </tbody>
  </table>

  @if($editable)
    <div style="display:flex;align-items:center;gap:1rem;margin-top:.75rem;">
      <button type="button" class="oie-save" wire:click="saveInlineItems" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="saveInlineItems">Salvează modificările în WooCommerce</span>
        <span wire:loading wire:target="saveInlineItems">Se salvează...</span>
      </button>
      <span style="font-size:.75rem;color:#9ca3af;">Prețul afectează doar această comandă. Totalurile și TVA-ul se recalculează pe site, apoi comanda se resincronizează.</span>
    </div>
  @endif
</div>
