<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReception;
use App\Models\PurchaseOrderReceptionItem;
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
        $orders = PurchaseOrder::with(['supplier', 'items', 'receptions'])
            ->whereIn('status', [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIALLY_RECEIVED])
            ->latest('updated_at')
            ->get();

        // Preîncărcăm drafturi existente
        $drafts = \DB::table('purchase_order_reception_drafts')
            ->whereIn('purchase_order_id', $orders->pluck('id'))
            ->get()
            ->keyBy('purchase_order_id');

        return $orders->map(function ($o) use ($drafts) {
            $draft = $drafts[$o->id] ?? null;
            $draftUser = $draft ? \App\Models\User::find($draft->user_id) : null;

            return [
                'id'               => $o->id,
                'number'           => $o->number,
                'supplier'         => $o->supplier?->name ?? '—',
                'items_count'      => $o->items->count(),
                'total'            => number_format($o->items->sum('line_total'), 2, ',', '.'),
                'created_at'       => $o->created_at?->format('d.m.Y'),
                'status'           => $o->status,
                'receptions_count' => $o->receptions->count(),
                'has_draft'        => $draft !== null,
                'draft_user'       => $draftUser?->name,
                'draft_at'         => $draft ? \Carbon\Carbon::parse($draft->updated_at)->format('d.m H:i') : null,
            ];
        })->all();
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
        abort_unless(in_array($order->status, [
            PurchaseOrder::STATUS_SENT,
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        ]), 403);

        $order->loadMissing(['supplier', 'items', 'receptions.items']);

        // Calculăm cantitățile deja recepționate per item (din recepțiile anterioare)
        $previouslyReceived = [];
        foreach ($order->receptions as $reception) {
            foreach ($reception->items as $ri) {
                $previouslyReceived[$ri->order_item_id] = ($previouslyReceived[$ri->order_item_id] ?? 0) + (float) $ri->received_quantity;
            }
        }

        $isPartiallyReceived = $order->status === PurchaseOrder::STATUS_PARTIALLY_RECEIVED;

        $items = $order->items->map(fn ($item) => [
            'id'                    => $item->id,
            'name'                  => $item->product_name,
            'sku'                   => $item->sku ?? null,
            'supplier_sku'          => $item->supplier_sku ?? null,
            'ordered_qty'           => (float) $item->quantity,
            'previously_received'   => $previouslyReceived[$item->id] ?? 0,
            'remaining_qty'         => max(0, (float) $item->quantity - ($previouslyReceived[$item->id] ?? 0)),
            'qty'                   => $isPartiallyReceived ? 0 : max(0, (float) $item->quantity - ($previouslyReceived[$item->id] ?? 0)),
        ])->values()->all();

        // Istoric recepții pentru afișare
        $receptionHistory = $order->receptions->sortBy('reception_number')->map(fn ($r) => [
            'number'     => $r->reception_number,
            'date'       => $r->received_at->format('d.m.Y H:i'),
            'items_count' => $r->items->count(),
            'total_qty'  => $r->items->sum(fn ($ri) => (float) $ri->received_quantity),
            'wm_status'  => $r->winmentor_sync_status,
            'is_final'   => $r->is_final,
        ])->values()->all();

        // Draft server-side (orice user)
        $serverDraft = \DB::table('purchase_order_reception_drafts')
            ->where('purchase_order_id', $order->id)
            ->orderByDesc('updated_at')
            ->first();

        $serverDraftData = null;
        $serverDraftMeta = null;
        if ($serverDraft) {
            $serverDraftData = $serverDraft->draft_data;
            $draftUser = \App\Models\User::find($serverDraft->user_id);
            $serverDraftMeta = [
                'user_name' => $draftUser?->name ?? 'Necunoscut',
                'user_id'   => $serverDraft->user_id,
                'saved_at'  => $serverDraft->updated_at,
            ];
        }

        return view('warehouse.receive', compact('order', 'items', 'isPartiallyReceived', 'receptionHistory', 'serverDraftData', 'serverDraftMeta'));
    }

    public function draftSave(Request $request, PurchaseOrder $order)
    {
        abort_unless(in_array($order->status, [
            PurchaseOrder::STATUS_SENT,
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        ]), 403);

        $data = $request->validate([
            'draft_data' => ['required', 'array'],
        ]);

        \DB::table('purchase_order_reception_drafts')->updateOrInsert(
            ['purchase_order_id' => $order->id, 'user_id' => Auth::id()],
            [
                'draft_data'  => json_encode($data['draft_data']),
                'updated_at'  => now(),
                'created_at'  => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function draftDelete(PurchaseOrder $order)
    {
        \DB::table('purchase_order_reception_drafts')
            ->where('purchase_order_id', $order->id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function receiveStore(Request $request, PurchaseOrder $order)
    {
        abort_unless(in_array($order->status, [
            PurchaseOrder::STATUS_SENT,
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        ]), 403);

        $data = $request->validate([
            'reception_type'               => ['required', 'in:partial,final'],
            'items'                        => ['required', 'array'],
            'items.*.id'                   => ['required', 'integer'],
            'items.*.qty'                  => ['required', 'numeric', 'min:0'],
            'items.*.reason'               => ['nullable', 'string', 'max:100'],
            'items.*.invoice_position'     => ['nullable', 'integer', 'min:1', 'max:9999'],
            'extra_items'                  => ['nullable', 'array'],
            'extra_items.*.name'           => ['required', 'string', 'max:255'],
            'extra_items.*.sku'            => ['required', 'string', 'max:100'],
            'extra_items.*.qty'            => ['required', 'numeric', 'min:0.01'],
            'extra_items.*.woo_product_id' => ['nullable', 'integer'],
            'received_notes'               => ['nullable', 'string', 'max:1000'],
        ]);

        $isFinal = $data['reception_type'] === 'final';

        // Verifică dacă există cel puțin o cantitate > 0 (items normale sau extra)
        $hasAnyQty = collect($data['items'])->contains(fn ($i) => (float) ($i['qty'] ?? 0) > 0)
            || ! empty($data['extra_items']);

        if (! $hasAnyQty) {
            return response()->json([
                'ok'      => false,
                'message' => 'Nu ai introdus nicio cantitate. Completează cel puțin un produs.',
            ], 422);
        }

        $order->loadMissing(['items', 'receptions']);

        // Următorul număr de recepție
        $nextReceptionNr = ($order->receptions->max('reception_number') ?? 0) + 1;

        // Creăm recepția (fără PENDING — dispatch manual după ce items sunt salvate)
        $reception = PurchaseOrderReception::create([
            'purchase_order_id'     => $order->id,
            'reception_number'      => $nextReceptionNr,
            'is_final'              => $isFinal,
            'received_at'           => now(),
            'received_by'           => Auth::id(),
            'received_notes'        => $data['received_notes'] ?? null,
            'winmentor_sync_status' => 'pending',
        ]);

        $submittedById = collect($data['items'])->keyBy('id');
        $affectedRequestIds = [];
        $hasShortfall       = false;

        foreach ($order->items as $orderItem) {
            $submitted   = $submittedById[$orderItem->id] ?? [];
            $thisQty     = (float) ($submitted['qty'] ?? 0);

            // Creăm item de recepție (chiar dacă qty=0, pentru evidență completă)
            if ($thisQty > 0) {
                PurchaseOrderReceptionItem::create([
                    'reception_id'      => $reception->id,
                    'order_item_id'     => $orderItem->id,
                    'received_quantity'  => $thisQty,
                    'received_note'     => $submitted['reason'] ?? null,
                    'invoice_position'  => isset($submitted['invoice_position']) ? (int) $submitted['invoice_position'] : null,
                ]);
            }

            // Actualizăm cantitatea cumulativă pe PO item
            $previousTotal = (float) ($orderItem->received_quantity ?? 0);
            $newTotal      = $previousTotal + $thisQty;

            $orderItem->update([
                'received_quantity' => $newTotal,
                'received_note'     => $submitted['reason'] ?? $orderItem->received_note,
                'invoice_position'  => isset($submitted['invoice_position']) ? (int) $submitted['invoice_position'] : $orderItem->invoice_position,
            ]);

            // Revert shortfall doar la recepția finală
            if ($isFinal) {
                $orderedQty = (float) $orderItem->quantity;
                $shortfall  = max(0, $orderedQty - $newTotal);
                if ($shortfall > 0) {
                    $hasShortfall = true;
                    $this->revertShortfall($orderItem, $shortfall, $affectedRequestIds);
                }
            }
        }

        if ($isFinal) {
            foreach (array_unique($affectedRequestIds) as $requestId) {
                PurchaseRequest::find($requestId)?->recalculateStatus();
            }
        }

        // Produse neplanificate
        foreach ($data['extra_items'] ?? [] as $extra) {
            $qty          = (float) $extra['qty'];
            $wooProductId = $extra['woo_product_id'] ? (int) $extra['woo_product_id'] : null;
            $sku = $extra['sku'] ?: null;
            if ($wooProductId) {
                $wooSku = \App\Models\WooProduct::find($wooProductId)?->sku;
                if ($wooSku) $sku = $wooSku;
            }

            $newItem = $order->items()->create([
                'product_name'      => $extra['name'],
                'sku'               => $sku,
                'woo_product_id'    => $wooProductId,
                'quantity'          => $qty,
                'received_quantity' => $qty,
                'unit_price'        => 0,
                'line_total'        => 0,
                'notes'             => 'Adăugat la recepție (neplanificat)',
            ]);

            PurchaseOrderReceptionItem::create([
                'reception_id'      => $reception->id,
                'order_item_id'     => $newItem->id,
                'received_quantity'  => $qty,
            ]);
        }

        // Actualizăm PO status
        $newStatus = $isFinal ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIALLY_RECEIVED;

        $order->update([
            'status'                => $newStatus,
            'received_at'           => $isFinal ? now() : ($order->received_at ?? now()),
            'received_by'           => $isFinal ? Auth::id() : ($order->received_by ?? Auth::id()),
            'received_notes'        => $data['received_notes'] ?? $order->received_notes,
        ]);

        // Șterge draftul server-side (recepția e finalizată)
        \DB::table('purchase_order_reception_drafts')
            ->where('purchase_order_id', $order->id)
            ->delete();

        // Dispatch WinMentor sync job acum (după ce toate items sunt salvate)
        \App\Jobs\PushReceptionToWinmentorJob::dispatch($reception->id)->afterCommit();

        $message = $isFinal
            ? ($hasShortfall
                ? 'Recepție finală înregistrată. Lipsurile returnate în coada de cumpărare.'
                : 'Recepție finală înregistrată cu succes.')
            : "Recepție parțială #{$nextReceptionNr} înregistrată. Comanda rămâne deschisă.";

        return response()->json([
            'ok'      => true,
            'message' => $message,
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
        $orders = PurchaseOrder::with(['supplier', 'receivedBy', 'receptions'])
            ->whereIn('status', [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED])
            ->latest('received_at')
            ->paginate(30);

        return view('warehouse.history', compact('orders'));
    }

    public function historyDetail(PurchaseOrder $order)
    {
        abort_unless(in_array($order->status, [
            PurchaseOrder::STATUS_RECEIVED,
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        ]), 404);
        $order->loadMissing(['supplier', 'items', 'receivedBy', 'receptions.items', 'receptions.receivedByUser']);
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
