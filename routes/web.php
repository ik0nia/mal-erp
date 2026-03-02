<?php

use App\Models\EanAssociationRequest;
use App\Models\EmailMessage;
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

    // Descarcă un atașament de pe IMAP (doar super_admin)
    Route::get('/email-attachment/{id}/{index}', function (int $id, int $index) {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $email = EmailMessage::findOrFail($id);
        $atts  = $email->attachments ?? [];

        abort_unless(isset($atts[$index]), 404);

        $meta = $atts[$index]; // {name, size, mime_type}

        // Conectare IMAP și fetch atașament
        $host       = \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_IMAP_HOST);
        $port       = \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_IMAP_PORT, '993');
        $encryption = \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_IMAP_ENCRYPTION, 'ssl');
        $username   = \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_IMAP_USERNAME);
        $password   = \App\Models\AppSetting::getEncrypted(\App\Models\AppSetting::KEY_IMAP_PASSWORD);

        $cm = new \Webklex\PHPIMAP\ClientManager();
        $client = $cm->make([
            'host'          => $host,
            'port'          => (int) $port,
            'encryption'    => $encryption,
            'validate_cert' => false,
            'username'      => $username,
            'password'      => $password,
            'protocol'      => 'imap',
        ]);

        $client->connect();

        // getFolder() nu funcționează pe sub-foldere — parcurgem arborele recursiv
        $folderPath = $email->imap_folder ?? 'INBOX';
        $folder = null;
        $stack  = $client->getFolders(false)->toArray();
        while ($stack) {
            $f = array_shift($stack);
            if ($f->path === $folderPath) { $folder = $f; break; }
            foreach ($f->children as $child) { $stack[] = $child; }
        }

        abort_unless($folder, 404, "Folder IMAP negăsit: {$folderPath}");

        $message = $folder->messages()->whereUid($email->imap_uid)->get()->first();

        abort_unless($message, 404);

        $attachments = $message->getAttachments();
        $att = $attachments->get($index);

        abort_unless($att, 404);

        $content  = $att->getContent();
        $mimeType = $meta['mime_type'] ?? 'application/octet-stream';
        $name     = $meta['name'] ?? 'attachment';

        $client->disconnect();

        // PDF și imagini: inline (se afișează în browser/modal), restul: download
        $isViewable = str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';
        $disposition = $isViewable ? 'inline' : 'attachment';

        return response($content)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', $disposition . '; filename="' . addslashes($name) . '"');
    });

    // Servește HTML-ul unui email într-un iframe izolat (doar super_admin)
    Route::get('/email-html/{id}', function (int $id) {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $email = EmailMessage::findOrFail($id);

        $html = $email->body_html ?? '';

        // Dacă nu are body HTML, construim un HTML simplu din text
        if ($html === '' && $email->body_text) {
            $html = '<html><body><pre style="font-family:sans-serif;white-space:pre-wrap;padding:16px">'
                . htmlspecialchars($email->body_text, ENT_QUOTES, 'UTF-8')
                . '</pre></body></html>';
        }

        if ($html === '') {
            $html = '<html><body><p style="color:#999;padding:16px">Conținut indisponibil.</p></body></html>';
        }

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "default-src 'self' 'unsafe-inline' data: https:; img-src * data:;");
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
