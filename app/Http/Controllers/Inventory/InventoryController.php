<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\EanAssociationRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\WooProduct;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class InventoryController extends Controller
{
    public function index()
    {
        return view('inventory.scan');
    }

    /** Adaugă produsul scanat la necesarul (draft) utilizatorului. */
    public function addNecesar(Request $request)
    {
        $data = $request->validate([
            'woo_product_id' => ['required', 'integer', 'exists:woo_products,id'],
            'quantity'       => ['nullable', 'numeric', 'min:0.001'],
            'notes'          => ['nullable', 'string', 'max:255'],
        ]);

        $user = Auth::user();
        if (! $user instanceof User) {
            return response()->json(['ok' => false], 403);
        }

        $qty   = (float) ($data['quantity'] ?? 1);
        $draft = PurchaseRequest::getOrCreateDraft($user);

        $existing = $draft->items()->where('woo_product_id', $data['woo_product_id'])->first();
        if ($existing) {
            $existing->update(['quantity' => (float) $existing->quantity + $qty]);
        } else {
            $draft->items()->create([
                'woo_product_id' => $data['woo_product_id'],
                'quantity'       => $qty,
                'notes'          => $data['notes'] ?? null,
            ]);
        }

        return response()->json([
            'ok'          => true,
            'total_items' => $draft->items()->count(),
        ]);
    }

    // ─── Lookup barcode / SKU ────────────────────────────────────────────────

    public function scan(Request $request)
    {
        $code = trim($request->input('code', ''));

        if (!$code) {
            return response()->json(['found' => false, 'error' => 'Cod lipsă']);
        }

        $product = $this->findProduct($code);

        if (!$product) {
            // Verifică dacă există deja o cerere pending pentru acest EAN
            $existing = EanAssociationRequest::where('ean', $code)
                ->whereIn('status', [EanAssociationRequest::STATUS_PENDING, EanAssociationRequest::STATUS_APPROVED, EanAssociationRequest::STATUS_AUTO_DETECTED])
                ->latest()
                ->first();

            return response()->json([
                'found'           => false,
                'code'            => $code,
                'existing_request'=> $existing ? [
                    'status' => $existing->status,
                    'product'=> $existing->product?->name,
                ] : null,
            ]);
        }

        // Stocuri locale per locație (toate, inclusiv 0)
        $stocks = ProductStock::with('location')
            ->where('woo_product_id', $product->id)
            ->get()
            ->map(fn($s) => [
                'location' => $s->location?->name ?? 'Locație ' . $s->location_id,
                'quantity' => (float) $s->quantity,
                'unit'     => $product->unit ?? 'buc',
            ]);

        // Comenzi de achiziție active (draft, sent, approved, pending, received fără recepție WinMentor)
        $poItems = PurchaseOrderItem::with('purchaseOrder.supplier')
            ->where(fn($q) => $q
                ->where('woo_product_id', $product->id)
                ->when($product->sku, fn($q2) => $q2->orWhere('sku', $product->sku))
                ->orWhere('product_name', $product->name)
            )
            ->whereHas('purchaseOrder', fn($q) => $q
                ->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_REJECTED])
                ->whereNot(fn($q2) => $q2
                    ->where('status', PurchaseOrder::STATUS_RECEIVED)
                    ->whereNotNull('winmentor_receptie_nr')
                )
            )
            ->get()
            ->sortByDesc(fn($item) => $item->purchaseOrder->created_at)
            ->take(10)
            ->map(fn($item) => [
                'po_number'  => $item->purchaseOrder->number,
                'supplier'   => $item->purchaseOrder->supplier?->name ?? '—',
                'status'     => $item->purchaseOrder->status,
                'status_label' => PurchaseOrder::statusLabels()[$item->purchaseOrder->status] ?? $item->purchaseOrder->status,
                'quantity'   => (float) $item->quantity,
                'unit'       => $product->unit ?? 'buc',
                'sent_at'    => $item->purchaseOrder->sent_at?->format('d.m.Y'),
                'received_at'=> $item->purchaseOrder->received_at?->format('d.m.Y'),
            ]);

        return response()->json([
            'found'   => true,
            'product' => [
                'id'          => $product->id,
                'name'        => $product->name,
                'sku'         => $product->sku,
                'unit'        => $product->unit ?? 'buc',
                'price'       => $product->price,
                'image'       => $product->main_image_url ?: $product->images()->first()?->url,
                'ean_carton'  => $product->ean_carton,
                'brand'       => $product->brand,
                'is_discontinued' => $product->is_discontinued,
            ],
            'stocks'  => $stocks,
            'orders'  => $poItems,
        ]);
    }

    // ─── Cerere asociere EAN necunoscut ─────────────────────────────────────

    public function eanRequest(Request $request)
    {
        $validated = $request->validate([
            'ean'            => ['required', 'string', 'max:50'],
            'woo_product_id' => ['nullable', 'integer', 'exists:woo_products,id'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        // Nu duplicăm cereri pending pentru același EAN
        $existing = EanAssociationRequest::where('ean', $validated['ean'])
            ->where('status', EanAssociationRequest::STATUS_PENDING)
            ->first();

        if ($existing) {
            return response()->json(['ok' => true, 'already_exists' => true]);
        }

        // Auto-detectare: produsul are winmentor_name și SKU diferit de EAN-ul scanat
        $autoDetected = false;
        $oldSku = null;
        if (! empty($validated['woo_product_id'])) {
            $product = WooProduct::find($validated['woo_product_id']);
            if ($product && $product->winmentor_name && $product->sku && $product->sku !== $validated['ean']) {
                $autoDetected = true;
                $oldSku = $product->sku;
            }
        }

        $eanRequest = EanAssociationRequest::create([
            'ean'            => $validated['ean'],
            'woo_product_id' => $validated['woo_product_id'] ?? null,
            'requested_by'   => Auth::id(),
            'status'         => $autoDetected ? EanAssociationRequest::STATUS_AUTO_DETECTED : EanAssociationRequest::STATUS_PENDING,
            'processed_at'   => $autoDetected ? now() : null,
            'notes'          => $autoDetected
                ? "EAN vechi: {$oldSku}"
                : ($validated['notes'] ?? null),
        ]);

        // Notificare email doar pentru cererile manuale
        if (! $autoDetected) {
            $eanRequest->loadMissing(['product', 'requestedBy']);
            Mail::to(['codrut@ikonia.ro', 'office@malinco.ro'])
                ->queue(new \App\Mail\EanAssociationRequestMail($eanRequest));
        }

        return response()->json(['ok' => true, 'already_exists' => false, 'auto_detected' => $autoDetected]);
    }

    // ─── Căutare produse pentru asociere ────────────────────────────────────

    public function searchProducts(Request $request)
    {
        $q = trim($request->input('q', ''));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $products = WooProduct::where(fn($query) => $query
            ->where('name', 'like', "%{$q}%")
            ->orWhere('sku', 'like', "%{$q}%")
        )
            ->where('is_placeholder', false)
            ->withSum('stocks', 'quantity')
            ->limit(20)
            ->get(['id', 'name', 'sku', 'unit', 'main_image_url']);

        return response()->json($products->map(fn($p) => [
            'id'    => $p->id,
            'name'  => $p->name,
            'sku'   => $p->sku,
            'unit'  => $p->unit,
            'image' => $p->main_image_url,
            'stock' => (float) ($p->stocks_sum_quantity ?? 0),
        ]));
    }

    // ─── Helper: găsește produs după EAN / SKU ───────────────────────────────

    private function findProduct(string $code): ?WooProduct
    {
        // 1. SKU exact
        $product = WooProduct::where('sku', $code)->first();
        if ($product) return $product;

        // 2. EAN carton
        $product = WooProduct::where('ean_carton', $code)->first();
        if ($product) return $product;

        // 3. supplier_package_ean (pe tabelul pivot)
        $product = WooProduct::whereHas('suppliers', fn($q) =>
            $q->where('supplier_package_ean', $code)
        )->first();
        if ($product) return $product;

        // 4. EAN Association Request aprobat sau auto-detectat
        $assoc = EanAssociationRequest::where('ean', $code)
            ->whereIn('status', [EanAssociationRequest::STATUS_APPROVED, EanAssociationRequest::STATUS_AUTO_DETECTED])
            ->with('product')
            ->latest('processed_at')
            ->first();

        return $assoc?->product;
    }
}
