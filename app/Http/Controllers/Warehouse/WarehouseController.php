<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\WhPushSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class WarehouseController extends Controller
{
    // ─── Auth ────────────────────────────────────────────────────────────────

    public function loginForm()
    {
        if (Auth::check()) {
            return redirect()->route('warehouse.orders');
        }
        return view('warehouse.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, remember: true)) {
            $request->session()->regenerate();
            return redirect()->route('warehouse.pin');
        }

        return back()->withErrors(['email' => 'Email sau parolă incorectă.']);
    }

    // ─── PIN screen ──────────────────────────────────────────────────────────

    public function pinForm()
    {
        return view('warehouse.pin');
    }

    public function pinVerify(Request $request)
    {
        $data = $request->validate([
            'pin' => ['required', 'digits:4'],
        ]);

        $user = Auth::user();

        if (! Hash::check($data['pin'], $user->warehouse_pin ?? '')) {
            return response()->json(['ok' => false, 'error' => 'PIN incorect.']);
        }

        return response()->json(['ok' => true]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('warehouse.login');
    }

    public function lockScreen(Request $request)
    {
        $request->session()->forget('wh_pin_verified');
        return redirect()->route('warehouse.pin');
    }

    // ─── PIN change ──────────────────────────────────────────────────────────

    public function changePinForm()
    {
        return view('warehouse.change-pin');
    }

    public function changePinStore(Request $request)
    {
        $data = $request->validate([
            'current_pin' => ['required', 'digits:4'],
            'new_pin'     => ['required', 'digits:4'],
            'confirm_pin' => ['required', 'same:new_pin'],
        ]);

        $user = Auth::user();

        if (! Hash::check($data['current_pin'], $user->warehouse_pin ?? '')) {
            return response()->json(['ok' => false, 'error' => 'PIN-ul curent este incorect.']);
        }

        $user->update(['warehouse_pin' => Hash::make($data['new_pin'])]);

        return response()->json(['ok' => true, 'message' => 'PIN schimbat cu succes.']);
    }

    // ─── Orders ──────────────────────────────────────────────────────────────

    public function orders()
    {
        $orders = $this->getPendingOrders();
        return view('warehouse.orders', compact('orders'));
    }

    public function ordersJson()
    {
        return response()->json($this->getPendingOrders());
    }

    private function getPendingOrders(): array
    {
        return PurchaseOrder::with(['supplier', 'items'])
            ->where('status', PurchaseOrder::STATUS_SENT)
            ->latest('updated_at')
            ->get()
            ->map(fn ($o) => [
                'id'          => $o->id,
                'number'      => $o->number,
                'supplier'    => $o->supplier?->name ?? '—',
                'items_count' => $o->items->count(),
                'total'       => number_format($o->items->sum('line_total'), 2, ',', '.'),
                'created_at'  => $o->created_at?->format('d.m.Y'),
            ])
            ->all();
    }

    public function supplierProducts(Request $request, Supplier $supplier)
    {
        $q = trim($request->query('q', ''));

        $products = $supplier->products()
            ->when($q, fn ($query) =>
                $query->where(function ($sub) use ($q) {
                    $sub->where('woo_products.name', 'like', "%{$q}%")
                        ->orWhere('woo_products.sku', 'like', "%{$q}%")
                        ->orWhere('product_suppliers.supplier_sku', 'like', "%{$q}%")
                        ->orWhere('product_suppliers.supplier_product_name', 'like', "%{$q}%");
                })
            )
            ->select('woo_products.id', 'woo_products.name', 'woo_products.sku',
                     'product_suppliers.supplier_sku', 'product_suppliers.supplier_product_name')
            ->limit(20)
            ->get()
            ->map(fn ($p) => [
                'id'   => $p->id,
                'name' => $p->name,
                'sku'  => $p->sku,
                'supplier_sku'  => $p->supplier_sku,
                'supplier_name' => $p->supplier_product_name,
            ]);

        return response()->json($products);
    }

    // ─── Reception ───────────────────────────────────────────────────────────

    public function receive(PurchaseOrder $order)
    {
        abort_unless($order->status === PurchaseOrder::STATUS_SENT, 403);

        $order->loadMissing(['supplier', 'items']);

        $items = $order->items->map(fn ($item) => [
            'id'           => $item->id,
            'name'         => $item->product_name,
            'sku'          => $item->sku ?? null,
            'supplier_sku' => $item->supplier_sku ?? null,
            'ordered_qty'  => (float) $item->quantity,
            'qty'          => (float) $item->quantity,
        ])->values()->all();

        return view('warehouse.receive', compact('order', 'items'));
    }

    public function receiveStore(Request $request, PurchaseOrder $order)
    {
        abort_unless($order->status === PurchaseOrder::STATUS_SENT, 403);

        $data = $request->validate([
            'items'                    => ['required', 'array'],
            'items.*.id'               => ['required', 'integer'],
            'items.*.qty'              => ['required', 'numeric', 'min:0'],
            'items.*.reason'           => ['nullable', 'string', 'max:100'],
            'items.*.invoice_position' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'extra_items'                  => ['nullable', 'array'],
            'extra_items.*.name'           => ['required', 'string', 'max:255'],
            'extra_items.*.sku'            => ['required', 'string', 'max:100'],
            'extra_items.*.qty'            => ['required', 'numeric', 'min:0.01'],
            'extra_items.*.woo_product_id' => ['nullable', 'integer'],
            'received_notes'               => ['nullable', 'string', 'max:1000'],
        ]);

        $order->loadMissing('items');

        $affectedRequestIds = [];
        $hasShortfall       = false;
        $submittedById      = collect($data['items'])->keyBy('id');

        foreach ($order->items as $orderItem) {
            $submitted   = $submittedById[$orderItem->id] ?? [];
            $receivedQty = (float) ($submitted['qty'] ?? 0);
            $orderedQty  = (float) $orderItem->quantity;
            $shortfall   = max(0, $orderedQty - $receivedQty);

            $orderItem->update([
                'received_quantity' => $receivedQty,
                'received_note'     => $submitted['reason'] ?? null,
                'invoice_position'  => isset($submitted['invoice_position']) ? (int) $submitted['invoice_position'] : null,
            ]);

            if ($shortfall > 0) {
                $hasShortfall = true;
                $this->revertShortfall($orderItem, $shortfall, $affectedRequestIds);
            }
        }

        foreach (array_unique($affectedRequestIds) as $requestId) {
            PurchaseRequest::find($requestId)?->recalculateStatus();
        }

        // Produse neplanificate — adăugate la recepție
        foreach ($data['extra_items'] ?? [] as $extra) {
            $qty          = (float) $extra['qty'];
            $wooProductId = $extra['woo_product_id'] ? (int) $extra['woo_product_id'] : null;

            // Dacă avem woo_product_id, folosim SKU-ul ERP (EAN), nu codul furnizorului
            $sku = $extra['sku'] ?: null;
            if ($wooProductId) {
                $wooSku = \App\Models\WooProduct::find($wooProductId)?->sku;
                if ($wooSku) $sku = $wooSku;
            }

            $order->items()->create([
                'product_name'      => $extra['name'],
                'sku'               => $sku,
                'woo_product_id'    => $wooProductId,
                'quantity'          => $qty,
                'received_quantity' => $qty,
                'unit_price'        => 0,
                'line_total'        => 0,
                'notes'             => 'Adăugat la recepție (neplanificat)',
            ]);
        }

        $order->update([
            'status'                => PurchaseOrder::STATUS_RECEIVED,
            'received_at'           => now(),
            'received_by'           => Auth::id(),
            'received_notes'        => $data['received_notes'] ?? null,
            'winmentor_sync_status' => PurchaseOrder::WINMENTOR_PENDING,
            'winmentor_sync_error'  => null,
        ]);

        return response()->json([
            'ok'      => true,
            'message' => $hasShortfall
                ? 'Recepție înregistrată. Lipsurile returnate în coada de cumpărare.'
                : 'Recepție înregistrată cu succes.',
        ]);
    }

    // ─── Push Notifications ──────────────────────────────────────────────────

    public function vapidPublicKey()
    {
        return response()->json(['key' => config('services.vapid.public_key')]);
    }

    public function pushSubscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'p256dh'   => ['required', 'string', 'max:200'],
            'auth'     => ['required', 'string', 'max:100'],
        ]);

        WhPushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id'    => Auth::id(),
                'p256dh'     => $data['p256dh'],
                'auth'       => $data['auth'],
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function pushUnsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);
        WhPushSubscription::where('endpoint', $data['endpoint'])->delete();
        return response()->json(['ok' => true]);
    }

    // ─── History ─────────────────────────────────────────────────────────────

    public function history()
    {
        $orders = PurchaseOrder::with(['supplier', 'receivedBy'])
            ->where('status', PurchaseOrder::STATUS_RECEIVED)
            ->latest('received_at')
            ->paginate(30);

        return view('warehouse.history', compact('orders'));
    }

    public function historyDetail(PurchaseOrder $order)
    {
        abort_unless($order->status === PurchaseOrder::STATUS_RECEIVED, 404);
        $order->loadMissing(['supplier', 'items', 'receivedBy']);
        return view('warehouse.history-detail', compact('order'));
    }

    // ─── Batch Reception ─────────────────────────────────────────────────────

    public function receiveBatchForm(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', $request->query('ids', ''))));
        abort_if(empty($ids), 404);

        $orders = PurchaseOrder::with(['supplier', 'items'])
            ->whereIn('id', $ids)
            ->where('status', PurchaseOrder::STATUS_SENT)
            ->get();

        abort_if($orders->isEmpty(), 404);
        abort_if($orders->pluck('supplier_id')->unique()->count() > 1, 422);

        $mergedItems = $this->mergeOrderItems($orders);

        return view('warehouse.receive-batch', compact('orders', 'mergedItems'));
    }

    public function receiveBatchStore(Request $request)
    {
        $data = $request->validate([
            'po_ids'             => ['required', 'array'],
            'po_ids.*'           => ['required', 'integer'],
            'items'              => ['required', 'array'],
            'items.*.id'               => ['required', 'integer'],
            'items.*.qty'              => ['required', 'numeric', 'min:0'],
            'items.*.reason'           => ['nullable', 'string', 'max:100'],
            'items.*.invoice_position' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'extra_items'                  => ['nullable', 'array'],
            'extra_items.*.name'           => ['required', 'string', 'max:255'],
            'extra_items.*.sku'            => ['required', 'string', 'max:100'],
            'extra_items.*.qty'            => ['required', 'numeric', 'min:0.01'],
            'extra_items.*.po_id'          => ['required', 'integer'],
            'extra_items.*.woo_product_id' => ['nullable', 'integer'],
            'received_notes'     => ['nullable', 'string', 'max:1000'],
        ]);

        $orders = PurchaseOrder::with('items')
            ->whereIn('id', $data['po_ids'])
            ->where('status', PurchaseOrder::STATUS_SENT)
            ->get()
            ->keyBy('id');

        abort_if($orders->isEmpty(), 404);
        abort_if($orders->pluck('supplier_id')->unique()->count() > 1, 422, 'Comenzile trebuie să fie de la același furnizor.');

        $submittedById     = collect($data['items'])->keyBy('id');
        $affectedRequestIds = [];
        $hasShortfall      = false;

        foreach ($orders as $order) {
            foreach ($order->items as $orderItem) {
                $submitted   = $submittedById[$orderItem->id] ?? [];
                $receivedQty = (float) ($submitted['qty'] ?? 0);
                $orderedQty  = (float) $orderItem->quantity;
                $shortfall   = max(0, $orderedQty - $receivedQty);

                $orderItem->update([
                    'received_quantity' => $receivedQty,
                    'received_note'     => $submitted['reason'] ?? null,
                    'invoice_position'  => isset($submitted['invoice_position']) ? (int) $submitted['invoice_position'] : null,
                ]);

                if ($shortfall > 0) {
                    $hasShortfall = true;
                    $this->revertShortfall($orderItem, $shortfall, $affectedRequestIds);
                }
            }

            // Produse neplanificate pentru acest PO
            foreach ($data['extra_items'] ?? [] as $extra) {
                if ((int) $extra['po_id'] !== $order->id) continue;
                $qty          = (float) $extra['qty'];
                $wooProductId = $extra['woo_product_id'] ? (int) $extra['woo_product_id'] : null;
                $sku = $extra['sku'] ?: null;
                if ($wooProductId) {
                    $wooSku = \App\Models\WooProduct::find($wooProductId)?->sku;
                    if ($wooSku) $sku = $wooSku;
                }
                $order->items()->create([
                    'product_name'      => $extra['name'],
                    'sku'               => $sku,
                    'woo_product_id'    => $wooProductId,
                    'quantity'          => $qty,
                    'received_quantity' => $qty,
                    'unit_price'        => 0,
                    'line_total'        => 0,
                    'notes'             => 'Adăugat la recepție (neplanificat)',
                ]);
            }

            // updateQuietly — evităm trigger-ul model event care ar dispatch job individual
            $order->updateQuietly([
                'status'                => PurchaseOrder::STATUS_RECEIVED,
                'received_at'           => now(),
                'received_by'           => Auth::id(),
                'received_notes'        => $data['received_notes'] ?? null,
                'winmentor_sync_status' => PurchaseOrder::WINMENTOR_PENDING,
                'winmentor_sync_error'  => null,
            ]);
        }

        foreach (array_unique($affectedRequestIds) as $requestId) {
            PurchaseRequest::find($requestId)?->recalculateStatus();
        }

        // Un singur document WinMentor pentru toate PO-urile din batch
        \App\Jobs\PushBatchComenziFurnizoriToWinmentorJob::dispatch($orders->keys()->all())->afterCommit();

        return response()->json([
            'ok'      => true,
            'message' => $hasShortfall
                ? 'Recepție înregistrată. Lipsurile returnate în coada de cumpărare.'
                : 'Recepție înregistrată cu succes.',
        ]);
    }

    private function mergeOrderItems($orders): array
    {
        $merged = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $sku = $item->sku ?? $item->supplier_sku ?? null;
                $key = $sku ? 'sku_' . $sku : 'name_' . $item->product_name;

                if (! isset($merged[$key])) {
                    $merged[$key] = [
                        'key'           => $key,
                        'name'          => $item->product_name,
                        'sku'           => $sku,
                        'total_ordered' => 0,
                        'sub_items'     => [],
                    ];
                }

                $merged[$key]['total_ordered'] += (float) $item->quantity;
                $merged[$key]['sub_items'][] = [
                    'id'        => $item->id,
                    'po_number' => $order->number,
                    'po_id'     => $order->id,
                    'qty'       => (float) $item->quantity,
                ];
            }
        }

        return array_values($merged);
    }

    private function revertShortfall($orderItem, float $shortfall, array &$affectedRequestIds): void
    {
        foreach ($orderItem->sources_json ?? [] as $source) {
            $requestItem = PurchaseRequestItem::find($source['purchase_request_item_id'] ?? null);
            if (! $requestItem) continue;

            $revert = min($shortfall, (float) ($source['quantity'] ?? 0));
            if ($revert <= 0) continue;

            $requestItem->increment('ordered_quantity', -$revert);
            $requestItem->increment('quantity', $revert);
            $requestItem->update(['status' => PurchaseRequestItem::STATUS_PENDING]);

            $affectedRequestIds[] = $requestItem->purchase_request_id;
            $shortfall -= $revert;

            if ($shortfall <= 0) break;
        }
    }
}
