<?php

use App\Models\EanAssociationRequest;
use App\Models\WooProduct;
use App\Filament\App\Resources\WooProductResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Webhook routes sunt înregistrate în bootstrap/app.php → routes/webhooks.php
// fără middleware web (CSRF, session, auth).

Route::middleware(['web', 'auth'])->group(function () {

    // Redirect direct la produsul cu SKU-ul dat (scanare cod de bare)
    Route::get('/sku/{sku}', function (string $sku) {
        $product = WooProduct::where('sku', trim($sku))->first();

        if (! $product) {
            abort(404, 'Produs negăsit pentru SKU: ' . $sku);
        }

        return redirect(WooProductResource::getUrl('view', ['record' => $product->id], panel: 'app'));
    });

    // AJAX: verifică dacă un EAN/SKU există și returnează URL-ul produsului
    Route::get('/sku-check/{sku}', function (string $sku) {
        $product = WooProduct::where('sku', trim($sku))->first();

        if (! $product) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'url'   => WooProductResource::getUrl('view', ['record' => $product->id], panel: 'app'),
        ]);
    });

    // AJAX: caută produse după nume sau SKU (pentru asociere EAN)
    Route::get('/products/search', function (Request $request) {
        $q = trim($request->get('q', ''));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $products = WooProduct::where(function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%')
                      ->orWhere('sku', 'like', '%' . $q . '%');
            })
            ->where('stock_status', 'instock')
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'sku', 'stock_status', 'price']);

        return response()->json($products);
    });

    // AJAX: trimite cerere de asociere EAN la produs
    Route::post('/ean-association', function (Request $request) {
        $request->validate([
            'ean'            => 'required|string|max:255',
            'woo_product_id' => 'required|integer|exists:woo_products,id',
        ]);

        EanAssociationRequest::create([
            'ean'            => trim($request->ean),
            'woo_product_id' => $request->woo_product_id,
            'requested_by'   => auth()->id(),
            'status'         => EanAssociationRequest::STATUS_PENDING,
        ]);

        return response()->json(['success' => true]);
    });

});
