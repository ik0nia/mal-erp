<?php

use App\Http\Controllers\WooWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/woo/{connection}', [WooWebhookController::class, 'handle'])
    ->name('webhooks.woo.product');

// MentorAPI audit — pagina de audit funcții și câmpuri
Route::get('/mentorapi/audit', function () {
    return response()->file(base_path('mentorapi/audit/docs-complete.html'));
});

Route::get('/mentorapi/audit-v2', function () {
    return response()->file(base_path('mentorapi/audit-v2.html'));
});

Route::get('/mentorapi/docs-v2', function () {
    return response()->file(base_path('mentorapi/docs.html'));
});

Route::get('/mentorapi/openapi-docs.json', function () {
    $path = base_path('mentorapi/openapi-docs.json');
    if (!file_exists($path)) abort(404);
    return response()->file($path, ['Content-Type' => 'application/json']);
});

// MentorAPI audit sections
Route::get('/mentorapi/audit/{file}', function (string $file) {
    $path = base_path('mentorapi/audit/' . basename($file));
    if (!file_exists($path)) abort(404);
    return response()->file($path, ['Content-Type' => 'text/html; charset=utf-8']);
});

// MentorAPI docs — servit local (fără proxy la Windows)
Route::get('/mentorapi/docs', function () {
    return response()->file(base_path('mentorapi/docs.html'));
});

Route::get('/mentorapi/openapi.json', function () {
    // Încearcă spec-ul îmbogățit local, fallback pe cel de pe server
    $local = base_path('mentorapi/openapi-docs.json');
    if (file_exists($local)) {
        return response()->file($local, ['Content-Type' => 'application/json']);
    }
    $conn = \App\Models\IntegrationConnection::find(5);
    $json = $conn ? @file_get_contents(rtrim($conn->bridgeUrl(), '/') . '/api/openapi.json') : null;
    if (!$json) return response()->json(['error' => 'MentorAPI not reachable'], 502);
    return response($json)->header('Content-Type', 'application/json');
});

// MentorAPI download — temporar, protejat cu token unic, fără auth/session
Route::get('/download/mentorapi/{token}', function (string $token) {
    if ($token !== 'mapi-2026-05-14-x9k2') {
        abort(404);
    }
    $exe = base_path('mentorapi/mentorapi.exe');
    $json = base_path('mentorapi/appsettings.json');
    if (!file_exists($exe)) abort(404, 'mentorapi.exe not found');

    if (request('file') === 'config') {
        return response()->download($json, 'appsettings.json');
    }

    return response()->download($exe, 'mentorapi.exe');
});
