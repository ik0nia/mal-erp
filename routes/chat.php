<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

// Preflight CORS — fără throttle
Route::prefix('chat')->group(function () {
    Route::options('/message', [ChatController::class, 'preflight']);
    Route::options('/contact', [ChatController::class, 'preflight']);
    Route::options('/config',  [ChatController::class, 'preflight']);
});

// Endpoint principal mesaje — 20 req/min per IP
Route::post('chat/message', [ChatController::class, 'message'])
    ->middleware('throttle:chat-message');

// Salvare contact din formularul grafic — 10 req/min per IP
Route::post('chat/contact', [ChatController::class, 'contact'])
    ->middleware('throttle:chat-contact');

// Config widget (culori, texte) — 60 req/min per IP
Route::get('chat/config', [ChatController::class, 'config'])
    ->middleware('throttle:chat-config');
