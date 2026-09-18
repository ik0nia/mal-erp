{{-- Marcaj „deja în PO deschis" pe produsul din necesar (clickabil + dată) --}}
@if(!empty($product->open_po_id))
    @php
        $poUrl  = \App\Filament\App\Resources\PurchaseOrderResource::getUrl('view', ['record' => (int) $product->open_po_id]);
        $poDate = $product->open_po_date ? \Illuminate\Support\Carbon::parse($product->open_po_date)->format('d.m.Y') : null;
    @endphp
    <a href="{{ $poUrl }}" target="_blank"
       title="Există deja o comandă deschisă pentru acest produs — click pentru PO"
       style="display:inline-flex;align-items:center;gap:5px;margin-top:3px;background:#dbeafe;color:#1d4ed8;padding:1px 8px;border-radius:9999px;font-size:0.7rem;font-weight:700;text-decoration:none;width:fit-content;">
        📋 deja în {{ $product->open_po_number }}@if($product->open_po_qty) · {{ number_format((float) $product->open_po_qty, 0, '.', '') }} buc @endif@if($poDate) · {{ $poDate }}@endif
    </a>
@endif
