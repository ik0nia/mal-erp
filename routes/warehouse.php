<?php

use App\Http\Controllers\Warehouse\WarehouseController;
use App\Http\Controllers\Inventory\InventoryController;
use App\Http\Controllers\Mobile\HubController;
use Illuminate\Support\Facades\Route;

// ─── PWA unificat — hub cu icoane ────────────────────────────────────────────
Route::prefix('app')->name('mobile.')->middleware('auth')->group(function () {
    Route::get('/', [HubController::class, 'home'])->name('home');

    // Dispecerizare vânzări azi (magazin / depozit / livrare) — rol din matricea de permisiuni
    Route::middleware('perm:mobile_dispecer')->group(function () {
        Route::get('dispecer',           [\App\Http\Controllers\Mobile\DispecerController::class, 'index'])->name('dispecer');
        Route::post('dispecer/set',      [\App\Http\Controllers\Mobile\DispecerController::class, 'set'])->name('dispecer.set');
        Route::post('dispecer/confirma', [\App\Http\Controllers\Mobile\DispecerController::class, 'confirma'])->name('dispecer.confirma');
    });

    // De predat — pentru manipulanți / gestionar depozit — rol din matricea de permisiuni
    Route::middleware('perm:mobile_depredat')->group(function () {
        Route::get('de-predat',        [\App\Http\Controllers\Mobile\DispecerController::class, 'dePredat'])->name('depredat');
        Route::post('de-predat/gata',  [\App\Http\Controllers\Mobile\DispecerController::class, 'predat'])->name('depredat.gata');
        Route::post('de-predat/linie', [\App\Http\Controllers\Mobile\DispecerController::class, 'predatLinie'])->name('depredat.linie');
    });
});

// ─── PWA Inventar / Lookup produse ──────────────────────────────────────────
Route::prefix('inv')->name('inventory.')->middleware('auth')->group(function () {
    Route::get('/',               [InventoryController::class, 'index'])->name('index');
    Route::post('scan',           [InventoryController::class, 'scan'])->name('scan');
    Route::post('ean-request',    [InventoryController::class, 'eanRequest'])->name('ean-request');
    Route::post('necesar',        [InventoryController::class, 'addNecesar'])->name('necesar');
    Route::get('search',          [InventoryController::class, 'searchProducts'])->name('search');
});

Route::prefix('wh')->name('warehouse.')->group(function () {

    // Auth
    Route::get('login', [WarehouseController::class, 'loginForm'])->name('login');
    Route::post('login', [WarehouseController::class, 'login'])->name('login.post')->middleware('throttle:5,1');
    Route::post('logout', [WarehouseController::class, 'logout'])->name('logout')->middleware('auth');

    // Autentificat — ecran PIN (fără verificare PIN)
    Route::middleware('auth')->group(function () {
        Route::get('pin',        [WarehouseController::class, 'pinForm'])->name('pin');
        Route::post('pin',       [WarehouseController::class, 'pinVerify'])->name('pin.verify');
        Route::post('lock',      [WarehouseController::class, 'lockScreen'])->name('lock');
        Route::get('pin/change', [WarehouseController::class, 'changePinForm'])->name('pin.change');
        Route::post('pin/change',[WarehouseController::class, 'changePinStore'])->name('pin.change.post');
    });

    // Autentificat (PIN verificat client-side prin sessionStorage)
    Route::middleware('auth')->group(function () {
        Route::get('/',           [WarehouseController::class, 'orders'])->name('orders');
        Route::get('orders',      [WarehouseController::class, 'ordersJson'])->name('orders.json');
        Route::get('batch',          [WarehouseController::class, 'receiveBatchForm'])->name('batch');
        Route::post('batch',         [WarehouseController::class, 'receiveBatchStore'])->name('batch.store');
        Route::get('history',           [WarehouseController::class, 'history'])->name('history');
        Route::get('history/{order}',   [WarehouseController::class, 'historyDetail'])->name('history.detail');
        Route::post('push/subscribe',   [WarehouseController::class, 'pushSubscribe'])->name('push.subscribe');
        Route::post('push/unsubscribe', [WarehouseController::class, 'pushUnsubscribe'])->name('push.unsubscribe');
        Route::get('push/vapid-key',    [WarehouseController::class, 'vapidPublicKey'])->name('push.vapid-key');
        Route::get('supplier/{supplier}/products', [WarehouseController::class, 'supplierProducts'])->name('supplier.products');
        Route::get('{order}',            [WarehouseController::class, 'receive'])->name('receive');
        Route::post('{order}',           [WarehouseController::class, 'receiveStore'])->name('receive.store');
        Route::post('{order}/draft',     [WarehouseController::class, 'draftSave'])->name('receive.draft');
        Route::delete('{order}/draft',   [WarehouseController::class, 'draftDelete'])->name('receive.draft.delete');
    });
});
