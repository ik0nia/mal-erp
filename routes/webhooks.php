<?php

use App\Http\Controllers\WooWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/woo/{connection}', [WooWebhookController::class, 'handle'])
    ->name('webhooks.woo.product');

// Documentația MentorAPI — doar super_admin autentificat (harta completă a bridge-ului
// contabil: 162 endpoint-uri; nu se expune public — audit securitate 2026-09-08)
Route::middleware(['web', 'auth'])->group(function () {

    Route::get('/mentorapi/audit', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        return response()->file(base_path('mentorapi/audit/docs-complete.html'));
    });

    Route::get('/mentorapi/audit-v2', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        return response()->file(base_path('mentorapi/audit-v2.html'));
    });

    Route::get('/mentorapi/docs-v2', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        return response()->file(base_path('mentorapi/docs.html'));
    });

    // Comparație documentația oficială DocImpServer (PDF Rev 1.5 / 22.05.2026) vs MentorAPI
    Route::get('/mentorapi/comparatie-doc', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        return response()->file(base_path('mentorapi/comparatie-doc-oficial.html'));
    });

    Route::get('/mentorapi/openapi-docs.json', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        $path = base_path('mentorapi/openapi-docs.json');
        if (!file_exists($path)) abort(404);
        return response()->file($path, ['Content-Type' => 'application/json']);
    });

    // Download build MentorAPI (TEMPORAR — pentru deploy manual pe Windows). Șterge ruta după deploy.
    Route::get('/mentorapi/download-exe', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        $path = base_path('mentorapi/mentorapi.exe');
        if (!file_exists($path)) abort(404);
        return response()->download($path, 'mentorapi.exe');
    });

    // Raport incident 2026-09-15 (log complet ce s-a întâmplat)
    Route::get('/mentorapi/incident', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        $path = base_path('documente/mentorapi-incident-2026-09-15.md');
        if (!file_exists($path)) abort(404);
        return response()->file($path, ['Content-Type' => 'text/markdown; charset=utf-8']);
    });

    // Documentația API completă v1.5.0 (HTML — params, formate, răspunsuri)
    Route::get('/mentorapi/api-docs', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        $path = base_path('documente/mentorapi-api-v1.5.0.html');
        if (!file_exists($path)) abort(404);
        return response()->file($path, ['Content-Type' => 'text/html; charset=utf-8']);
    });

    // Documentația completă v1.5.0 (markdown)
    Route::get('/mentorapi/documentatie', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        $path = base_path('documente/mentorapi-v1.5.0-documentatie.md');
        if (!file_exists($path)) abort(404);
        return response()->file($path, ['Content-Type' => 'text/markdown; charset=utf-8']);
    });

    // MentorAPI audit sections
    Route::get('/mentorapi/audit/{file}', function (string $file) {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        $path = base_path('mentorapi/audit/' . basename($file));
        if (!file_exists($path)) abort(404);
        return response()->file($path, ['Content-Type' => 'text/html; charset=utf-8']);
    });

    // MentorAPI docs — servit local (fără proxy la Windows)
    Route::get('/mentorapi/docs', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
        return response()->file(base_path('mentorapi/docs.html'));
    });

    Route::get('/mentorapi/openapi.json', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
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
});

// Ruta de download MentorAPI (token static, „temporar" 2026-05-14) a fost eliminată
// la auditul de securitate din 2026-09-08 — expunea appsettings.json neautentificat.
// La nevoie: refă descărcarea printr-o rută cu auth + super_admin.
