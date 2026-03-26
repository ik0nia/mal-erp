<?php

use App\Models\Customer;
use App\Models\EanAssociationRequest;
use App\Models\EmailMessage;
use App\Models\Offer;
use App\Models\WooProduct;
use App\Filament\App\Resources\WooProductResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Webhook routes sunt înregistrate în bootstrap/app.php → routes/webhooks.php
// fără middleware web (CSRF, session, auth).

// Pagină publică progres import Toya (fără autentificare)
Route::get('/toya-import', \App\Http\Controllers\ToyaImportStatusController::class);

// Redirect permanent de la vechea cale woo-products → produse
Route::permanentRedirect('/woo-products', '/produse');
Route::get('/woo-products/{any}', fn (Request $request, string $any) =>
    redirect('/produse/' . $any . ($request->getQueryString() ? '?' . $request->getQueryString() : ''), 301)
)->where('any', '.*');

Route::middleware(['web', 'auth'])->group(function () {

    // Raport PDF — discrepanțe preț de vânzare vs WinMentor
    Route::get('/rapoarte/discrepante-pret-vanzare', function () {
        $file = storage_path('app/LISTA PRODUSE PRET ACHIZITIE.xlsx');

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        // Build map EAN → {H (sale), G (purchase), last date}
        $excelMap = [];
        for ($row = 3; $row <= $highestRow; $row++) {
            $nrCrt = trim((string) $sheet->getCell('A' . $row)->getValue());
            if ($nrCrt === 'crt.' || str_starts_with($nrCrt, 'Total')) continue;
            $ean  = trim((string) $sheet->getCell('C' . $row)->getValue());
            $pretG = (float) str_replace(',', '.', (string) $sheet->getCell('G' . $row)->getValue());
            $pretH = (float) str_replace(',', '.', (string) $sheet->getCell('H' . $row)->getValue());
            $dateRaw = $sheet->getCell('J' . $row)->getFormattedValue();
            if ($ean === '' || $pretG <= 0) continue;
            try {
                $date = \Carbon\Carbon::createFromFormat('d.m.Y', $dateRaw);
            } catch (\Exception) {
                $date = null;
            }
            if (!isset($excelMap[$ean]) || ($date && (!$excelMap[$ean]['date'] || $date->gt($excelMap[$ean]['date'])))) {
                $excelMap[$ean] = ['h' => $pretH, 'g' => $pretG, 'date' => $date];
            }
        }

        $products = \App\Models\WooProduct::whereIn('sku', array_keys($excelMap))
            ->whereNotNull('sku')
            ->get(['id', 'sku', 'name', 'price', 'regular_price'])
            ->keyBy('sku');

        $totalCompared = 0;
        $totalOk = 0;
        $discrepancies = collect();

        foreach ($excelMap as $ean => $ex) {
            $product = $products[$ean] ?? null;
            if (!$product) continue;
            $dbPrice = (float) ($product->price ?: $product->regular_price);
            if ($dbPrice <= 0 && $ex['h'] <= 0) continue;
            $totalCompared++;
            $diff = $dbPrice - $ex['h'];
            $diffPct = $ex['h'] > 0 ? ($diff / $ex['h']) * 100 : 0;
            $breakeven = $ex['g'] * 1.21;
            if (abs($diff) < 0.05 || abs($diffPct) < 2) {
                $totalOk++;
                continue;
            }
            $discrepancies->push([
                'ean'       => $ean,
                'name'      => $product->name,
                'excel_h'   => $ex['h'],
                'excel_g'   => $ex['g'],
                'db_price'  => $dbPrice,
                'diff'      => $diff,
                'diff_pct'  => $diffPct,
                'breakeven' => $breakeven,
                'is_loss'   => $dbPrice < $breakeven,
            ]);
        }

        $discrepancies = $discrepancies->sortByDesc(fn ($r) => abs($r['diff']))->values();
        $lossCount = $discrepancies->where('is_loss', true)->count();
        $totalOk += $totalCompared - $discrepancies->count() - $totalOk;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.price-discrepancies', compact(
            'discrepancies', 'totalCompared', 'totalOk', 'lossCount'
        ))->setPaper('a4', 'landscape');

        return $pdf->stream('discrepante-pret-' . now()->format('Ymd') . '.pdf');
    })->name('reports.price-discrepancies');

    // Raport PDF — furnizori fără persoane de contact
    Route::get('/rapoarte/furnizori-fara-contact', function () {
        $suppliers = \App\Models\Supplier::withCount([
                'contacts',
                'products' => fn ($q) => $q->where('is_discontinued', false),
            ])
            ->having('contacts_count', 0)
            ->having('products_count', '>', 0)
            ->where('is_active', true)
            ->with(['buyer', 'buyers'])
            ->orderByDesc('products_count')
            ->orderBy('name')
            ->get();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.suppliers-without-contacts', compact('suppliers'))
            ->setPaper('a4', 'portrait');

        return $pdf->stream('furnizori-fara-contact-' . now()->format('Ymd') . '.pdf');
    })->name('reports.suppliers-without-contacts');

    // Raport PDF — produse discontinued fără furnizor activ
    Route::get('/rapoarte/produse-discontinued-fara-furnizor', function () {
        $products = \Illuminate\Support\Facades\DB::table('woo_products as wp')
            ->leftJoin(
                \Illuminate\Support\Facades\DB::raw('(SELECT woo_product_id, SUM(quantity) as total FROM product_stocks GROUP BY woo_product_id) stk'),
                'stk.woo_product_id', '=', 'wp.id'
            )
            ->where('wp.product_type', 'shop')
            ->where('wp.is_discontinued', true)
            ->whereNotExists(function ($q) {
                $q->select(\Illuminate\Support\Facades\DB::raw(1))
                  ->from('product_suppliers as ps')
                  ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
                  ->whereColumn('ps.woo_product_id', 'wp.id')
                  ->where('s.is_active', true);
            })
            ->select(
                'wp.sku', 'wp.name', 'wp.status', 'wp.brand', 'wp.price',
                \Illuminate\Support\Facades\DB::raw('COALESCE(stk.total, 0) as stoc'),
                \Illuminate\Support\Facades\DB::raw('ROUND(COALESCE(stk.total, 0) * wp.price, 2) as valoare')
            )
            ->orderByRaw('COALESCE(stk.total, 0) * wp.price DESC')
            ->orderBy('wp.name')
            ->get();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.discontinued-without-supplier', compact('products'))
            ->setPaper('a4', 'landscape');

        return $pdf->stream('produse-discontinued-fara-furnizor-' . now()->format('Ymd') . '.pdf');
    })->name('reports.discontinued-without-supplier');

    // ── Propunere aprovizionare Toya (PDF) ───────────────────────────────────
    Route::get('/rapoarte/propunere-toya', function () {
        $basePath = storage_path('app/toya-proposal');

        $s1 = json_decode(file_get_contents("{$basePath}/shelf1_final.json"), true);
        $s2 = json_decode(file_get_contents("{$basePath}/shelf2_final.json"), true);
        $s3 = json_decode(file_get_contents("{$basePath}/shelf3_final.json"), true);

        $shelves = collect([
            [
                'title'    => 'Raftul 1 — Unelte Auto & Impact',
                'subtitle' => 'Scule pentru mecanici auto: chei de impact, chei dinamometrice, seturi tubulare auto',
                'story'    => 'Segmentul auto-moto este printre cele mai căutate categorii la un magazin de bricolaj & scule profesionale. Mecanicii auto independenți, atelierele mici și entuziaștii DIY care își repară singuri mașina caută constant unelte de calitate la prețuri accesibile. Toya excelează exact în acest segment — cheile de impact pe baterie (18V brushless) și seturile de tubulare cu adaptor sunt bestseller-uri dovedite. Recomandăm expunerea în zona centrală a raftului, la nivel ochilor, cu prețuri afișate vizibil. ROI estimat: 60-90 zile.',
                'products' => $s1['products'],
                'total_ron' => $s1['total_ron'],
            ],
            [
                'title'    => 'Raftul 2 — Truse Complete & Seturi',
                'subtitle' => 'Truse profesionale 129-224 piese: ideal cadou, echipare atelier, portofoliu complet',
                'story'    => 'Trusele complete sunt categoria cu cea mai mare valoare adăugată per produs expus. Un set de 224 piese arată impresionant pe raft, justifică prețul și generează vânzări impulsive (achiziție pentru cadou, dotare atelier). Seria YATO Professional acoperă toată gama: de la set entry-level 34 piese pentru uz casnic, până la 224 piese pentru profesionist. Recomandăm poziționarea pe raftul superior, bine iluminat, cu capacul deschis pentru demonstrație. Rotație estimată: 3-4 truse/lună per referință.',
                'products' => $s2['products'],
                'total_ron' => $s2['total_ron'],
            ],
            [
                'title'    => 'Raftul 3 — Scule de Mână & Electrice',
                'subtitle' => 'Chei combinate, tubulare individuale, rulete, clești, OBD, pistol impact electric',
                'story'    => 'Sculele de mână individuale sunt produse de reaprovizionare constantă — clientul vine să cumpere o cheie fixă de 17mm, un clește sau o ruletă fără să planifice vizita. Cheile combinate satinate YATO (seria YT-03xx) sunt referințe de bază: se vând rapid, nu ocupă mult spațiu și au marjă bună. Recomandăm expunerea în fâșii organizate pe dimensiune (8mm→22mm), cu prețuri per bucată afișate clar. OBD tester-ul și pistolul de impact electric completează secțiunea cu produse "wow" care atrag atenția și cresc valoarea coșului mediu.',
                'products' => $s3['products'],
                'total_ron' => $s3['total_ron'],
            ],
        ]);

        $grandTotal  = round($shelves->sum('total_ron'), 2);
        $generatedAt = now()->format('d.m.Y H:i');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.toya-proposal', [
            'shelves'      => $shelves,
            'grand_total'  => $grandTotal,
            'generated_at' => $generatedAt,
        ])
        ->setPaper('a4', 'landscape');

        return $pdf->stream('propunere-toya-' . now()->format('Ymd') . '.pdf');
    })->name('reports.toya-proposal');

    // ── Editor grafic template (standalone, fără Livewire) ────────────────────
    Route::get('/template-editor/{template}', [App\Http\Controllers\GraphicTemplateController::class, 'show'])
        ->name('template-editor.show');
    Route::post('/template-editor/{template}/save', [App\Http\Controllers\GraphicTemplateController::class, 'save'])
        ->name('template-editor.save');
    Route::post('/template-editor/{template}/preview', [App\Http\Controllers\GraphicTemplateController::class, 'preview'])
        ->name('template-editor.preview');

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

    // AJAX: caută produse pentru tabelul de necesare (returnează {id, label})
    Route::get('/achizirii/products-search', function (Request $request) {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) return response()->json([]);

        return WooProduct::where(function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%')
                      ->orWhere('sku', 'like', '%' . $q . '%');
            })
            ->orderByRaw("CASE WHEN sku = ? THEN 0 WHEN sku LIKE ? THEN 1 ELSE 2 END", [strtoupper($q), strtoupper($q).'%'])
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'sku', 'price', 'regular_price'])
            ->map(fn ($p) => [
                'id'    => $p->id,
                'label' => ($p->sku ? "[{$p->sku}] " : '') . ($p->decoded_name ?? $p->name),
                'price' => $p->price ?? $p->regular_price,
            ]);
    })->middleware('throttle:search');

    // AJAX: caută clienți pentru popup rezervare necesar
    Route::get('/achizirii/customers-search', function (Request $request) {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) return response()->json([]);

        return Customer::where('name', 'like', '%' . $q . '%')
            ->orWhere('phone', 'like', '%' . $q . '%')
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone'])
            ->map(fn ($c) => [
                'id'    => $c->id,
                'label' => $c->name . ($c->phone ? " ({$c->phone})" : ''),
            ]);
    })->middleware('throttle:search');

    // AJAX: caută oferte pentru popup rezervare necesar
    Route::get('/achizirii/offers-search', function (Request $request) {
        $q      = trim($request->get('q', ''));
        $userId = auth()->id();

        // Fără query: returnează ultimele 5 oferte ale userului curent
        if (strlen($q) < 2) {
            return Offer::where('user_id', $userId)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'number', 'client_name'])
                ->map(fn ($o) => [
                    'id'    => $o->id,
                    'label' => "{$o->number} — {$o->client_name}",
                ]);
        }

        return Offer::where(function ($q2) use ($q) {
                $q2->where('number', 'like', '%' . $q . '%')
                   ->orWhere('client_name', 'like', '%' . $q . '%');
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'number', 'client_name'])
            ->map(fn ($o) => [
                'id'    => $o->id,
                'label' => "{$o->number} — {$o->client_name}",
            ]);
    })->middleware('throttle:search');

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
    })->middleware('throttle:search');

    // WinMentor Bridge — plan integrare ERP (doar super_admin)
    Route::get('/docs/winmentor-integrare', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $md   = file_get_contents(base_path('private/winmentor/INTEGRARE-ERP.md'));
        $html = \Illuminate\Support\Str::markdown($md, [
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return response()->view('docs.winmentor-integrare', ['content' => $html]);
    })->name('docs.winmentor-integrare');

    // WinMentor Bridge — documentație (doar super_admin)
    Route::get('/docs/winmentor', function () {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $md   = file_get_contents(base_path('private/winmentor/README.md'));
        $html = \Illuminate\Support\Str::markdown($md, [
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return response()->view('docs.winmentor', ['content' => $html]);
    })->name('docs.winmentor');

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

        try {
            $client->connect();
        } catch (\Throwable $e) {
            \Log::error('IMAP connection failed', ['error' => $e->getMessage()]);
            abort(503, 'Nu s-a putut conecta la serverul de email.');
        }

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

        abort_unless($message, 404, 'Mesajul nu mai există pe serverul de email.');

        try {
            $attachments = $message->getAttachments();
            $att = $attachments->get($index);
            abort_unless($att, 404, 'Atașamentul nu a fost găsit.');
            $content = $att->getContent();
        } catch (\Throwable $e) {
            \Log::error('IMAP attachment download failed', ['id' => $id, 'index' => $index, 'error' => $e->getMessage()]);
            abort(500, 'Nu s-a putut descărca atașamentul.');
        }

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

        $rawHtml = $email->body_html ?? '';

        // Dacă nu are body HTML, construim un HTML simplu din text (body_text e deja escaped)
        if ($rawHtml === '' && $email->body_text) {
            $safeText = htmlspecialchars($email->body_text, ENT_QUOTES, 'UTF-8');
            $html = '<html><body><pre style="font-family:sans-serif;white-space:pre-wrap;padding:16px">'
                . $safeText
                . '</pre></body></html>';

            return response($html)
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('X-Frame-Options', 'SAMEORIGIN')
                ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline';");
        }

        if ($rawHtml === '') {
            $html = '<html><body><p style="color:#999;padding:16px">Conținut indisponibil.</p></body></html>';

            return response($html)
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('X-Frame-Options', 'SAMEORIGIN')
                ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline';");
        }

        // Sanitizare HTML pentru a preveni XSS înainte de redare în iframe
        $cleanHtml = EmailMessage::sanitizeEmailHtml($rawHtml);

        return response($cleanHtml)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "default-src 'self' 'unsafe-inline' data: https:; img-src * data:; script-src 'none';");
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
