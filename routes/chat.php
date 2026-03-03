<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

// Rute publice chatbot — fără auth, fără CSRF, cu rate limit per IP
Route::prefix('chat')
    ->middleware(['throttle:30,1'])
    ->group(function () {
        // Preflight CORS pentru browsere (OPTIONS)
        Route::options('/message', [ChatController::class, 'preflight']);
        Route::options('/config',  [ChatController::class, 'preflight']);

        // Endpoint principal mesaje
        Route::post('/message', [ChatController::class, 'message']);

        // Salvare contact din formularul grafic
        Route::options('/contact', [ChatController::class, 'preflight']);
        Route::post('/contact',  [ChatController::class, 'contact']);

        // Config widget (culori, texte) — fără rate limit strict, e GET simplu
        Route::get('/config', [ChatController::class, 'config']);
    });
