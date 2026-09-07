<?php

namespace App\Filament\App\Resources\PurchaseOrderResource\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public int   $supplierId        = 0;
    public array $csvImportRows     = [];   // [{code, name, sku, qty, found, ps_id}]
    public string $csvImportPath    = '';

    public function mount(): void
    {
        $this->supplierId = (int) request()->query('supplier_id', 0);
        parent::mount(); // apelează fillForm() — trebuie să avem supplierId setat deja
    }

    /**
     * Interceptăm crearea PO-ului pentru a avertiza utilizatorul
     * dacă există poziții fără cantitate completată.
     */
    public function create(bool $another = false): void
    {
        $rawState   = $this->form->getRawState();
        $totalItems = collect($rawState['items'] ?? []);
        $withQty    = $totalItems->filter(fn ($item) => isset($item['quantity']) && (float) $item['quantity'] > 0);

        if ($withQty->isEmpty()) {
            \Filament\Notifications\Notification::make()
                ->warning()
                ->title('Niciun produs cu cantitate completată')
                ->body('Completează cantitatea pentru cel puțin un produs înainte de a crea comanda.')
                ->send();
            return;
        }

        parent::create($another);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('categoryStructure')
                ->label('Structură pe categorii')
                ->icon('heroicon-o-squares-2x2')
                ->color('gray')
                ->modalHeading(function (): string {
                    $name = $this->supplierId ? \App\Models\Supplier::find($this->supplierId)?->name : null;
                    return 'Structură pe categorii' . ($name ? ' — ' . $name : '');
                })
                ->modalWidth('6xl')
                ->modalSubmitActionLabel('Aplică cantitățile în PO')
                ->modalCancelActionLabel('Închide')
                ->form(function (): array {
                    $categories = $this->buildCategoryStructure($this->supplierId);

                    $currentQtys = [];
                    foreach ($this->data['items'] ?? [] as $item) {
                        $pid = $item['woo_product_id'] ?? null;
                        if ($pid) {
                            $currentQtys[$pid] = $item['quantity'] ?? null;
                        }
                    }

                    return [
                        \Filament\Forms\Components\Hidden::make('qtys_json'),
                        \Filament\Forms\Components\Placeholder::make('category_view')
                            ->label('')
                            ->content(fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
                                view('filament.app.pages.po-category-structure', [
                                    'categories'  => $categories,
                                    'currentQtys' => $currentQtys,
                                ])->render()
                            )),
                    ];
                })
                ->action(function (array $data): void {
                    $newQtys = json_decode($data['qtys_json'] ?? '{}', true) ?? [];
                    if (empty($newQtys)) {
                        return;
                    }

                    $items = $this->data['items'] ?? [];

                    // Actualizează cantitățile pentru itemele existente
                    $existingPids = [];
                    foreach ($items as &$item) {
                        $pid = $item['woo_product_id'] ?? null;
                        if ($pid) {
                            $existingPids[(string) $pid] = true;
                            if (isset($newQtys[(string) $pid])) {
                                $item['quantity'] = (float) $newQtys[(string) $pid];
                            }
                        }
                    }
                    unset($item);

                    // Adaugă rânduri noi pentru produsele care nu sunt încă în PO
                    $newPids = array_keys(array_diff_key($newQtys, $existingPids));
                    if (! empty($newPids)) {
                        $products = \App\Models\WooProduct::whereIn('id', $newPids)
                            ->get()->keyBy('id');

                        $psMap = \App\Models\ProductSupplier::where('supplier_id', $this->supplierId)
                            ->whereIn('woo_product_id', $newPids)
                            ->get()->keyBy('woo_product_id');

                        // Stock + velocity data pentru toate produsele noi dintr-o singură query
                        $infoRows = DB::table('woo_products as wp')
                            ->leftJoin('bi_product_velocity_current as bpv', 'bpv.reference_product_id', '=', 'wp.sku')
                            ->leftJoin(
                                DB::raw('(SELECT woo_product_id, COALESCE(SUM(quantity),0) as total_qty FROM product_stocks GROUP BY woo_product_id) stk'),
                                'stk.woo_product_id', '=', 'wp.id'
                            )
                            ->whereIn('wp.id', $newPids)
                            ->select([
                                'wp.id',
                                'wp.min_stock_qty',
                                'wp.max_stock_qty',
                                DB::raw('COALESCE(stk.total_qty, 0) as stock'),
                                DB::raw('COALESCE(bpv.avg_out_qty_7d, 0)  as avg7'),
                                DB::raw('COALESCE(bpv.avg_out_qty_30d, 0) as avg30'),
                                DB::raw('COALESCE(bpv.avg_out_qty_90d, 0) as avg90'),
                            ])
                            ->get()->keyBy('id');

                        foreach ($newPids as $pid) {
                            $product = $products->get($pid);
                            if (! $product) continue;

                            $ps   = $psMap->get($pid);
                            $info = $infoRows->get($pid);

                            $stock    = $info ? (float) $info->stock : 0;
                            $avg7     = $info ? (float) $info->avg7  : 0;
                            $avg30    = $info ? (float) $info->avg30 : 0;
                            $avg90    = $info ? (float) $info->avg90 : 0;
                            $minStk   = $info && $info->min_stock_qty !== null ? (float) $info->min_stock_qty : null;
                            $maxStk   = $info && $info->max_stock_qty !== null ? (float) $info->max_stock_qty : null;

                            $base    = max($avg7, $avg30, $avg90);
                            $trend   = ($avg30 > 0 && $avg7 > 0 && $avg7 < $avg30 * 0.85) ? max(0.5, $avg7 / $avg30) : 1.0;
                            $velDay  = $base * $trend;
                            $sales7d = round($avg7 * 7, 1);
                            $sales30d = round($avg30 * 30, 1);
                            $safetyStock = $velDay * 3;
                            $hint    = max(0, (int) ceil($velDay * 7 + $safetyStock - $stock));
                            $daysToStockout = $avg7 > 0 ? round($stock / $avg7, 1) : null;

                            if ($maxStk !== null && $maxStk > 0) {
                                $addStore   = max(0, (int) ceil($maxStk - $stock));
                                $calcMethod = 'max_stock';
                            } elseif ($hint > 0) {
                                $addStore   = $hint;
                                $calcMethod = 'velocity';
                            } else {
                                $addStore   = 0;
                                $calcMethod = null;
                            }

                            $items[] = [
                                'woo_product_id'         => (string) $pid,
                                'product_name'           => $product->decoded_name ?? $product->name,
                                'sku'                    => $product->sku ?? '',
                                'supplier_sku'           => $ps?->supplier_sku ?? '',
                                'unit_price'             => $ps ? (float) ($ps->last_purchase_price ?: $ps->purchase_price ?: 0) : 0,
                                'quantity'               => (float) $newQtys[(string) $pid],
                                'info_purchase_uom'      => $ps?->purchase_uom ?? null,
                                'info_conversion_factor' => $ps?->conversion_factor ? (float) $ps->conversion_factor : null,
                                'info_stock'             => $stock,
                                'info_sales_7d'          => $sales7d,
                                'info_sales_30d'         => $sales30d,
                                'info_days_stockout'     => $daysToStockout,
                                'quantity_hint'          => $addStore > 0 ? $addStore : null,
                                'recommendation_json'    => json_encode([
                                    'from_requests'     => 0,
                                    'reserved_qty'      => 0,
                                    'general_qty'       => 0,
                                    'current_stock'     => $stock,
                                    'velocity_day'      => $velDay,
                                    'sales_7d'          => $sales7d,
                                    'sales_30d'         => $sales30d,
                                    'min_stock_qty'     => $minStk,
                                    'max_stock_qty'     => $maxStk,
                                    'additional_store'  => $addStore,
                                    'total_recommended' => $addStore,
                                    'calc_method'       => $calcMethod,
                                ], JSON_UNESCAPED_UNICODE),
                            ];
                        }
                    }

                    $this->data['items'] = array_values($items);
                    $this->form->fill($this->data);

                    $added   = count($newPids);
                    $updated = count($newQtys) - $added;
                    $msg     = [];
                    if ($updated > 0) $msg[] = "{$updated} cantități actualizate";
                    if ($added > 0)   $msg[] = "{$added} produse noi adăugate";

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title(implode(', ', $msg))
                        ->send();
                })
                ->visible(fn (): bool => $this->supplierId > 0),

            // Import CSV — flux 2 pași în o singură acțiune (re-mount cu stare)
            Action::make('import_csv')
                ->label(fn (): string => empty($this->csvImportRows) ? 'Import CSV' : 'Import CSV — Confirmare')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->supplierId > 0)
                ->modalHeading(fn (): string => empty($this->csvImportRows) ? 'Import CSV — Pasul 1: Încarcă fișier' : 'Import CSV — Pasul 2: Confirmare')
                ->modalSubmitActionLabel(fn (): string => empty($this->csvImportRows) ? 'Previzualizează →' : 'Creează PO cu aceste produse')
                ->modalWidth('4xl')
                ->mountUsing(function (): void {
                    // resetăm preview-ul doar la deschidere fresh (nu la re-mount din action)
                })
                ->form(function (): array {
                    if (! empty($this->csvImportRows)) {
                        // Pasul 2: preview tabel
                        $rows   = $this->csvImportRows;
                        $found  = array_values(array_filter($rows, fn ($r) => $r['found']));
                        $missed = array_values(array_filter($rows, fn ($r) => ! $r['found']));

                        $html  = '<div style="font-size:13px;line-height:1.5">';
                        $html .= '<div style="margin-bottom:12px;display:flex;gap:20px;font-weight:600">';
                        $html .= '<span style="color:#16a34a">✓ ' . count($found) . ' produse găsite</span>';
                        if ($missed) {
                            $html .= '<span style="color:#dc2626">✗ ' . count($missed) . ' coduri negăsite</span>';
                        }
                        $html .= '</div>';
                        $html .= '<div style="max-height:400px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px">';
                        $html .= '<table style="width:100%;border-collapse:collapse">';
                        $html .= '<thead style="position:sticky;top:0;z-index:1"><tr style="background:#f3f4f6;text-align:left">';
                        $html .= '<th style="padding:7px 10px;border-bottom:1px solid #e5e7eb">Cod furnizor</th>';
                        $html .= '<th style="padding:7px 10px;border-bottom:1px solid #e5e7eb">Produs</th>';
                        $html .= '<th style="padding:7px 10px;border-bottom:1px solid #e5e7eb">SKU</th>';
                        $html .= '<th style="padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:right">Cantitate</th>';
                        $html .= '</tr></thead><tbody>';

                        foreach ($rows as $row) {
                            $bg    = $row['found'] ? 'background:#fff' : 'background:#fff5f5';
                            $color = $row['found'] ? '#111827' : '#dc2626';
                            $html .= "<tr style=\"{$bg};border-bottom:1px solid #f3f4f6\">";
                            $html .= "<td style=\"padding:5px 10px;font-family:monospace;font-size:12px;color:{$color}\">{$row['code']}</td>";
                            $html .= "<td style=\"padding:5px 10px;color:{$color}\">{$row['name']}</td>";
                            $html .= "<td style=\"padding:5px 10px;font-family:monospace;font-size:12px;color:#6b7280\">{$row['sku']}</td>";
                            $html .= "<td style=\"padding:5px 10px;text-align:right;font-weight:600\">{$row['qty']}</td>";
                            $html .= '</tr>';
                        }

                        $html .= '</tbody></table></div></div>';

                        return [
                            Placeholder::make('csv_preview')
                                ->label('')
                                ->content(new HtmlString($html)),
                        ];
                    }

                    // Pasul 1: upload fișier
                    return [
                        FileUpload::make('csv_file')
                            ->label('Fișier CSV (cod_furnizor,cantitate)')
                            ->disk('local')
                            ->directory('csv-imports-tmp')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/octet-stream'])
                            ->maxSize(1024)
                            ->required()
                            ->helperText('Format: o linie per produs — cod_furnizor,cantitate (cu sau fără header)'),
                    ];
                })
                ->action(function (array $data): void {
                    // Pasul 2 — creăm PO direct
                    if (! empty($this->csvImportRows)) {
                        $rows = array_values(array_filter($this->csvImportRows, fn ($r) => $r['found']));

                        if (empty($rows)) {
                            Notification::make()->danger()->title('Niciun produs valid de importat.')->send();
                            return;
                        }

                        $order = DB::transaction(function () use ($rows): PurchaseOrder {
                            $order = PurchaseOrder::create([
                                'supplier_id' => $this->supplierId,
                                'buyer_id'    => auth()->id(),
                                'status'      => PurchaseOrder::STATUS_DRAFT,
                            ]);

                            // Bulk load ProductSupplier cu product pentru toți ps_ids
                            $psMap = ProductSupplier::with('product')
                                ->whereIn('id', array_column($rows, 'ps_id'))
                                ->get()
                                ->keyBy('id');

                            foreach ($rows as $row) {
                                $ps = $psMap->get($row['ps_id']);
                                if (! $ps?->product) continue;

                                PurchaseOrderItem::create([
                                    'purchase_order_id' => $order->id,
                                    'woo_product_id'    => $ps->product->id,
                                    'product_name'      => $ps->product->decoded_name ?? $ps->product->name,
                                    'sku'               => $ps->product->sku,
                                    'supplier_sku'      => $ps->supplier_sku,
                                    'quantity'          => $row['qty'],
                                    'unit_price'        => (float) ($ps->last_purchase_price ?: $ps->purchase_price ?: 0),
                                ]);
                            }

                            $order->recalculateTotals();
                            return $order;
                        });

                        $this->csvImportRows = [];
                        $this->redirect(PurchaseOrderResource::getUrl('view', ['record' => $order]));
                        return;
                    }

                    // Pasul 1 — parsăm CSV și setăm preview
                    $path    = is_array($data['csv_file']) ? reset($data['csv_file']) : $data['csv_file'];
                    $content = Storage::disk('local')->get($path);

                    if (! $content) {
                        Notification::make()->danger()->title('Fișierul nu a putut fi citit.')->send();
                        return;
                    }

                    $firstLine = strtok($content, "\r\n");
                    $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

                    $csvRows = [];
                    foreach (preg_split('/\r\n|\r|\n/', trim($content)) as $line) {
                        $line = trim($line);
                        if ($line === '') continue;
                        $cols = str_getcsv($line, $delimiter);
                        if (count($cols) < 2) continue;
                        $code = trim($cols[0]);
                        $qty  = (float) str_replace(',', '.', trim($cols[1]));
                        if ($code !== '' && $qty > 0) {
                            $csvRows[$code] = $qty;
                        }
                    }

                    if (empty($csvRows)) {
                        Notification::make()->danger()->title('CSV-ul nu conține date valide.')->send();
                        return;
                    }

                    $psRecords = ProductSupplier::with('product')
                        ->where('supplier_id', $this->supplierId)
                        ->whereIn('supplier_sku', array_keys($csvRows))
                        ->get()
                        ->keyBy('supplier_sku');

                    $rows = [];
                    foreach ($csvRows as $code => $qty) {
                        $ps      = $psRecords->get($code);
                        $product = $ps?->product;
                        $rows[]  = [
                            'code'  => $code,
                            'name'  => $product ? ($product->decoded_name ?? $product->name) : '—',
                            'sku'   => $product?->sku ?? '—',
                            'qty'   => $qty,
                            'found' => (bool) $product,
                            'ps_id' => $ps?->id,
                        ];
                    }

                    $this->csvImportRows = $rows;

                    // Ștergem schema cacheată ca form() să fie re-evaluat cu preview
                    unset($this->cachedSchemas['mountedActionSchema0']);

                    // Halt — păstrăm modalul deschis
                    throw new \Filament\Support\Exceptions\Halt();
                }),

        ];
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        if ($this->supplierId) {
            $name = \App\Models\Supplier::query()->where('id', $this->supplierId)->value('name');
            if ($name) {
                return 'Creează PO — ' . $name;
            }
        }

        return 'Creează PO';
    }

    /**
     * Override fillForm so that when coming from BuyerDashboard (?supplier_id=X)
     * we pre-populate the form with all pending items for that supplier.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        if ($this->supplierId) {
            $items = $this->buildItemsForSupplier($this->supplierId);

            $this->form->fill([
                'supplier_id' => $this->supplierId,
                'items'       => $items,
            ]);
        } else {
            $this->form->fill();
        }

        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $data['buyer_id'] = $user->id;
        }

        // Eliminăm rândurile fără cantitate completată — utilizatorul le-a lăsat goale intenționat
        if (isset($data['items']) && is_array($data['items'])) {
            $data['items'] = array_values(array_filter(
                $data['items'],
                fn ($item) => isset($item['quantity']) && (float) $item['quantity'] > 0
            ));
        }

        return $data;
    }

    /**
     * After the PO is created, link request items → order items using FIFO partial allocation.
     *
     * Dacă cantitatea PO este mai mică decât suma din necesare, se alocă parțial:
     * - ordered_quantity se incrementează cu cantitatea alocată
     * - Dacă item-ul e acum complet acoperit → STATUS_ORDERED
     * - Dacă rămâne neacoperit parțial → rămâne STATUS_PENDING cu ordered_quantity > 0
     * - sources_json se actualizează cu `allocated_qty` pentru fiecare sursă
     */
    protected function afterCreate(): void
    {
        $affectedRequestIds = [];

        DB::transaction(function () use (&$affectedRequestIds): void {
            foreach ($this->record->items as $orderItem) {
                // Dacă nu are sources_json (PO creat manual/din catalog),
                // auto-match cu request items pending pe același produs + furnizor
                if (blank($orderItem->sources_json) || empty($orderItem->sources_json)) {
                    $this->autoMatchRequestItems($orderItem, $affectedRequestIds);
                    continue;
                }

                $sources = $orderItem->sources_json;
                if (! is_array($sources) || empty($sources)) {
                    $this->autoMatchRequestItems($orderItem, $affectedRequestIds);
                    continue;
                }

                // FIFO sort: urgent first, then earliest needed_by
                usort($sources, function (array $a, array $b): int {
                    $urgentDiff = (int) ($b['is_urgent'] ?? false) <=> (int) ($a['is_urgent'] ?? false);
                    if ($urgentDiff !== 0) {
                        return $urgentDiff;
                    }
                    return strcmp($a['needed_by'] ?? '', $b['needed_by'] ?? '');
                });

                $remaining          = (float) $orderItem->quantity;
                $primaryRequestItem = null;
                $updatedSources     = [];

                foreach ($sources as $source) {
                    $requestItemId = $source['request_item_id'] ?? null;
                    if (! $requestItemId) {
                        $updatedSources[] = array_merge($source, ['allocated_qty' => 0.0]);
                        continue;
                    }

                    // Pessimistic lock — previne race condition la creare simultană de PO-uri
                    $requestItem = PurchaseRequestItem::lockForUpdate()->find($requestItemId);

                    if (! $requestItem) {
                        $updatedSources[] = array_merge($source, ['allocated_qty' => 0.0]);
                        continue;
                    }

                    $sourceQty = (float) ($source['quantity'] ?? 0);

                    if ($remaining <= 0 || $sourceQty <= 0) {
                        $updatedSources[] = array_merge($source, ['allocated_qty' => 0.0]);
                        continue;
                    }

                    // Alocare parțială: cât se poate acoperi din remaining
                    // Recalculăm din DB (post-lock) cantitatea reală disponibilă
                    $alreadyOrdered  = (float) $requestItem->ordered_quantity;
                    $originalQty     = (float) $requestItem->quantity;
                    $availableInItem = max(0, $originalQty - $alreadyOrdered);
                    $canAllocate     = min($remaining, $sourceQty, $availableInItem);

                    if ($canAllocate <= 0) {
                        $updatedSources[] = array_merge($source, ['allocated_qty' => 0.0]);
                        continue;
                    }

                    $remaining -= $canAllocate;

                    // Incrementăm ordered_quantity
                    PurchaseRequestItem::where('id', $requestItem->id)
                        ->increment('ordered_quantity', $canAllocate);

                    $requestItem->refresh();

                    // Dacă itemul e complet acoperit → STATUS_ORDERED
                    if ($requestItem->isFullyOrdered()) {
                        $requestItem->update([
                            'status'                 => PurchaseRequestItem::STATUS_ORDERED,
                            'purchase_order_item_id' => $orderItem->id,
                        ]);
                    } else {
                        // Parțial comandat — rămâne pending, referința la PO
                        $requestItem->update([
                            'purchase_order_item_id' => $orderItem->id,
                        ]);
                    }

                    $primaryRequestItem   = $primaryRequestItem ?? $requestItem->id;
                    $affectedRequestIds[] = $requestItem->purchase_request_id;

                    $updatedSources[] = array_merge($source, ['allocated_qty' => $canAllocate]);
                }

                // Salvăm sources_json cu allocated_qty pentru fiecare sursă
                $orderItem->updateQuietly([
                    'sources_json'             => json_encode($updatedSources, JSON_UNESCAPED_UNICODE),
                    'purchase_request_item_id' => $primaryRequestItem,
                ]);
            }
        });

        foreach (array_unique($affectedRequestIds) as $requestId) {
            PurchaseRequest::find($requestId)?->recalculateStatus();
        }
    }

    /**
     * Auto-match: când un PO item nu are sources_json, caută request items
     * pending cu același produs (woo_product_id sau SKU) + furnizor și le leagă.
     */
    private function autoMatchRequestItems(PurchaseOrderItem $orderItem, array &$affectedRequestIds): void
    {
        $supplierId = $this->record->supplier_id;

        // Căutăm request items pending cu același produs + furnizor
        $query = PurchaseRequestItem::query()
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', [
                PurchaseRequest::STATUS_SUBMITTED,
                PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ]))
            ->whereRaw('quantity > COALESCE(ordered_quantity, 0)')
            ->orderByDesc('is_urgent')
            ->orderBy('needed_by');

        // Match pe woo_product_id (prioritar) sau SKU
        if ($orderItem->woo_product_id) {
            $query->where('woo_product_id', $orderItem->woo_product_id);
        } elseif ($orderItem->sku) {
            $query->where('sku', $orderItem->sku);
        } else {
            return; // nu avem ce matcha
        }

        $matchingItems = $query->lockForUpdate()->get();

        if ($matchingItems->isEmpty()) {
            return;
        }

        $remaining      = (float) $orderItem->quantity;
        $primaryRequest = null;
        $sources        = [];

        foreach ($matchingItems as $requestItem) {
            if ($remaining <= 0) {
                break;
            }

            $availableInItem = max(0, (float) $requestItem->quantity - (float) $requestItem->ordered_quantity);
            $canAllocate     = min($remaining, $availableInItem);

            if ($canAllocate <= 0) {
                continue;
            }

            $remaining -= $canAllocate;

            PurchaseRequestItem::where('id', $requestItem->id)
                ->increment('ordered_quantity', $canAllocate);

            $requestItem->refresh();

            if ($requestItem->isFullyOrdered()) {
                $requestItem->update([
                    'status'                 => PurchaseRequestItem::STATUS_ORDERED,
                    'purchase_order_item_id' => $orderItem->id,
                ]);
            } else {
                $requestItem->update([
                    'purchase_order_item_id' => $orderItem->id,
                ]);
            }

            $primaryRequest       = $primaryRequest ?? $requestItem->id;
            $affectedRequestIds[] = $requestItem->purchase_request_id;

            $sources[] = [
                'request_item_id' => $requestItem->id,
                'request_number'  => $requestItem->purchaseRequest?->number,
                'request_id'      => $requestItem->purchase_request_id,
                'quantity'        => $availableInItem,
                'allocated_qty'   => $canAllocate,
            ];
        }

        if (! empty($sources)) {
            $orderItem->updateQuietly([
                'sources_json'             => json_encode($sources, JSON_UNESCAPED_UNICODE),
                'purchase_request_item_id' => $primaryRequest,
            ]);
        }
    }

    /**
     * Construiește structura produselor furnizorului grupate pe categorii,
     * cu cantități recomandate pe baza velocității.
     */
    private function buildCategoryStructure(int $supplierId): array
    {
        if (! $supplierId) {
            return [];
        }

        // Produse furnizor cu date velocity și stoc — toate, inclusiv fără rulaj
        $rows = \Illuminate\Support\Facades\DB::table('product_suppliers as ps')
            ->join('woo_products as wp', 'wp.id', '=', 'ps.woo_product_id')
            ->leftJoin('bi_product_velocity_current as bpv', 'bpv.reference_product_id', '=', 'wp.sku')
            ->leftJoin(
                \Illuminate\Support\Facades\DB::raw('(SELECT woo_product_id, COALESCE(SUM(quantity),0) as total_qty FROM product_stocks GROUP BY woo_product_id) stk'),
                'stk.woo_product_id', '=', 'wp.id'
            )
            ->where('ps.supplier_id', $supplierId)
            ->where('wp.is_discontinued', false)
            ->select([
                'wp.id', 'wp.name', 'wp.sku', 'wp.min_stock_qty', 'wp.max_stock_qty', 'wp.procurement_type',
                'ps.supplier_sku', 'ps.order_multiple',
                \Illuminate\Support\Facades\DB::raw('COALESCE(stk.total_qty, 0) as stock'),
                \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_7d, 0)  as avg7'),
                \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_30d, 0) as avg30'),
                \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_90d, 0) as avg90'),
            ])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Calculăm cantitatea recomandată per produs
        $productIds = $rows->pluck('id')->all();

        // Cantități deja pe comenzi deschise (PO-uri trimise/aprobate, nerecepționate)
        $onOrderQtys = $this->getOnOrderQtys($productIds);

        // Acoperire adaptată furnizorului: lead time real + ciclu de comandă
        $coverDays = $this->resolveCoverDays($supplierId);

        // Cantități din necesare (purchase request items PENDING pentru furnizorul ăsta)
        $pendingQtys = \Illuminate\Support\Facades\DB::table('purchase_request_items as pri')
            ->join('woo_products as wp', 'wp.id', '=', 'pri.woo_product_id')
            ->where('pri.supplier_id', $supplierId)
            ->whereIn('pri.status', [
                \App\Models\PurchaseRequestItem::STATUS_PENDING,
                \App\Models\PurchaseRequestItem::STATUS_ORDERED,
            ])
            ->whereIn('pri.woo_product_id', $productIds)
            ->select('pri.woo_product_id', \Illuminate\Support\Facades\DB::raw('SUM(GREATEST(pri.quantity - pri.ordered_quantity, 0)) as pending_qty'))
            ->groupBy('pri.woo_product_id')
            ->pluck('pending_qty', 'woo_product_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        $products = [];

        foreach ($rows as $row) {
            $avg7  = (float) $row->avg7;
            $avg30 = (float) $row->avg30;
            $avg90 = (float) $row->avg90;
            $stock = (float) $row->stock;
            $onOrder = (float) ($onOrderQtys[$row->id] ?? 0);

            $base = max($avg7, $avg30, $avg90);

            if ($row->procurement_type === \App\Models\WooProduct::PROCUREMENT_ON_DEMAND) {
                $salesRecommended = 0; // produs la comandă — doar cantitățile din necesare
            } elseif ($base > 0) {
                $trend  = ($avg30 > 0 && $avg7 > 0 && $avg7 < ($avg30 * 0.85))
                    ? max(0.5, $avg7 / $avg30) : 1.0;
                $daily  = $base * $trend;

                $maxStock = $row->max_stock_qty !== null ? (float) $row->max_stock_qty : null;
                if ($maxStock !== null && $maxStock > 0) {
                    $salesRecommended = max(0, $maxStock - $stock - $onOrder);
                } else {
                    $safety           = $daily * 3;
                    $salesRecommended = max(0, $daily * $coverDays + $safety - $stock - $onOrder);
                }
            } else {
                $salesRecommended = 0; // fără rulaj — nu recomandăm cantitate din vânzări
            }

            $pendingQty  = $pendingQtys[$row->id] ?? 0;
            $recommended = (int) ceil($salesRecommended) + (int) ceil($pendingQty);

            // Round to order multiple if set
            if ($recommended > 0 && ! empty($row->order_multiple)) {
                $om = (float) $row->order_multiple;
                if ($om > 0) {
                    $recommended = (int) (ceil($recommended / $om) * $om);
                }
            }

            $products[$row->id] = [
                'woo_product_id' => $row->id,
                'name'           => $row->name,
                'sku'            => $row->sku,
                'supplier_sku'   => $row->supplier_sku,
                'stock'          => $stock,
                'sales_7d'       => round($avg7 * 7, 1),
                'sales_30d'      => round($avg30 * 30, 1),
                'pending_qty'    => $pendingQty,
                'recommended'    => $recommended,
            ];
        }

        if (empty($products)) {
            return [];
        }

        // Categorii per produs (subcategorii — parent_id not null)
        $catRows = \Illuminate\Support\Facades\DB::table('woo_product_category as wpc')
            ->join('woo_categories as wc', 'wc.id', '=', 'wpc.woo_category_id')
            ->whereIn('wpc.woo_product_id', array_keys($products))
            ->whereNotNull('wc.parent_id')
            ->where('wc.parent_id', '!=', '')
            ->select(['wpc.woo_product_id', 'wc.name as cat_name'])
            ->get()
            ->groupBy('woo_product_id');

        // Grupăm produsele pe categorii
        $categories = [];

        foreach ($products as $productId => $data) {
            $cats = isset($catRows[$productId])
                ? $catRows[$productId]->pluck('cat_name')->unique()->all()
                : ['Fără categorie'];

            foreach ($cats as $cat) {
                $categories[$cat][] = $data;
            }
        }

        // Sortare: categorii normale alfabetic, "Fără categorie" la final
        $noCategory = $categories['Fără categorie'] ?? null;
        unset($categories['Fără categorie']);
        ksort($categories);
        if ($noCategory !== null) {
            $categories['Fără categorie'] = $noCategory;
        }

        return $categories;
    }

    /**
     * Fetch ALL pending items for $supplierId + velocity-based suggestions.
     * Quantity is left null (user must fill); quantity_hint shows the 7-day suggestion.
     */
    private function buildItemsForSupplier(int $supplierId): array
    {
        // --- 1. Items din necesare trimise ---
        $requestItems = PurchaseRequestItem::query()
            ->with(['purchaseRequest.user', 'purchaseRequest.location'])
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', [
                PurchaseRequest::STATUS_SUBMITTED,
                PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ]))
            ->whereRaw('quantity > COALESCE(ordered_quantity, 0)')
            ->orderByDesc('is_urgent')
            ->orderBy('needed_by')
            ->get();

        $groups = $requestItems->groupBy(
            fn (PurchaseRequestItem $item): string => $item->woo_product_id
                ? 'woo:'.(string) $item->woo_product_id
                : 'name:'.$item->product_name
        );

        // Colectăm woo_product_ids deja în lista din necesare
        $requestProductIds = $requestItems->pluck('woo_product_id')->filter()->unique()->values()->all();

        // --- 2. Velocity items pentru toți produsele furnizorului cu rulaj ---
        $velocityItems = $this->getVelocityItems($supplierId, []);

        // --- 3. Construim itemele din necesare ---
        $items = $groups->map(function ($groupItems) use ($supplierId, $velocityItems): array {
            /** @var \Illuminate\Support\Collection<int, PurchaseRequestItem> $groupItems */
            $first = $groupItems->first();

            $supplierSku = null;

            if ($first->woo_product_id) {
                $ps = ProductSupplier::where('woo_product_id', $first->woo_product_id)
                    ->where('supplier_id', $supplierId)
                    ->first();

                if ($ps) {
                    $supplierSku = $ps->supplier_sku;
                }
            }

            $sources = $groupItems->map(fn (PurchaseRequestItem $item): array => [
                'request_item_id'  => $item->id,
                'request_number'   => $item->purchaseRequest?->number,
                'request_id'       => $item->purchaseRequest?->id,
                'consultant'       => $item->purchaseRequest?->user?->name,
                'location'         => $item->purchaseRequest?->location?->name,
                'quantity'         => max(0, (float) $item->quantity - (float) $item->ordered_quantity),
                'is_urgent'        => (bool) $item->is_urgent,
                'needed_by'        => $item->needed_by?->format('Y-m-d'),
                'client_reference' => $item->client_reference,
                'requested_at'     => $item->purchaseRequest?->created_at?->format('d.m.Y H:i'),
                'allocated_qty'    => 0.0,
            ])->values()->all();

            $vi = $velocityItems[$first->woo_product_id] ?? null;

            $totalRequested = array_sum(array_column($sources, 'quantity'));

            // Cantitate rezervată pentru clienți specifici vs. stoc general
            $reservedQty = (float) array_sum(array_column(
                array_filter($sources, fn ($s) => ! empty($s['client_reference'])),
                'quantity'
            ));
            $generalQty = $totalRequested - $reservedQty;

            $currentStock = $vi ? $vi['stock'] : 0.0;
            $velocityDay  = $vi ? $vi['velocity_day'] : 0.0;
            $minStock     = $vi ? $vi['min_stock_qty'] : null;
            $maxStock     = $vi ? $vi['max_stock_qty'] : null;

            // Calculăm necesarul suplimentar pentru stocul magazinului
            $additionalStore = 0;
            $calcMethod      = null;

            if ($maxStock !== null && $maxStock > 0) {
                // Dacă avem target de stoc maxim: cât mai trebuie să cumpărăm
                // post-livrare: currentStock + onOrder + generalQty + additionalStore >= maxStock
                $additionalStore = max(0, (int) ceil($maxStock - $currentStock - ($vi['on_order'] ?? 0) - $generalQty));
                $calcMethod      = 'max_stock';
            } elseif ($vi && $vi['hint'] > 0) {
                // Velocity hint (deja redus de stoc curent): din el scădem ce acoperă necesarele generale
                $additionalStore = max(0, (int) ceil($vi['hint'] - $generalQty));
                $calcMethod      = 'velocity';
            }

            $totalRecommended = (int) ceil($totalRequested + $additionalStore);

            // Round to order multiple if set on product-supplier pivot
            $ps = $ps ?? null; // $ps from supplier_sku lookup above
            if ($ps && $totalRecommended > 0) {
                $totalRecommended = (int) $ps->roundToOrderMultiple((float) $totalRecommended);
            }

            $recData = [
                'from_requests'     => $totalRequested,
                'reserved_qty'      => $reservedQty,
                'general_qty'       => $generalQty,
                'current_stock'     => $currentStock,
                'velocity_day'      => $velocityDay,
                'sales_7d'          => $vi ? $vi['sales_7d'] : 0,
                'sales_30d'         => $vi ? $vi['sales_30d'] : 0,
                'min_stock_qty'     => $minStock,
                'max_stock_qty'     => $maxStock,
                'additional_store'  => $additionalStore,
                'total_recommended' => $totalRecommended,
                'calc_method'       => $calcMethod,
            ];

            return [
                'woo_product_id'      => $first->woo_product_id,
                'product_name'        => $first->product_name,
                'sku'                 => $first->sku,
                'supplier_sku'        => $supplierSku,
                'quantity'            => null,
                'unit_price'          => null,
                'notes'               => null,
                'sources_json'        => json_encode($sources, JSON_UNESCAPED_UNICODE),
                'recommendation_json' => json_encode($recData, JSON_UNESCAPED_UNICODE),
                'quantity_hint'       => $totalRecommended > 0 ? $totalRecommended : ($vi['hint'] ?? null),
                'info_stock'             => $vi['stock'] ?? null,
                'info_sales_7d'          => $vi['sales_7d'] ?? null,
                'info_sales_30d'         => $vi['sales_30d'] ?? null,
                'info_days_stockout'     => $vi['days_to_stockout'] ?? null,
                'info_purchase_uom'      => ($ps ?? null)?->purchase_uom,
                'info_conversion_factor' => ($ps ?? null)?->conversion_factor ? (float) ($ps ?? null)->conversion_factor : null,
            ];
        })->values()->all();

        // --- 4. Adăugăm produse cu rulaje care nu sunt deja în necesare ---
        $velocityOnlyItems = $this->buildVelocityOnlyItems($supplierId, $requestProductIds, $velocityItems);

        return array_merge($items, $velocityOnlyItems);
    }

    /**
     * Calculează sugestiile de cantitate folosind bi_product_velocity_current (aceeași sursă ca NecesarMarfa).
     * Returnează array [local_product_id => data] pentru produsele furnizorului cu rulaj real.
     *
     * @param  int  $supplierId
     * @param  int[]  $excludeProductIds  produse deja în necesare (nu le mai repetăm)
     * @param  int  $coverDays  zile de stoc de acoperit
     * @return array<int, array{hint: int, sku: string, name: string, supplier_sku: ?string, velocity_day: float, min_stock_qty: ?float, max_stock_qty: ?float}>
     */
    private function getVelocityItems(int $supplierId, array $excludeProductIds, ?int $coverDays = null): array
    {
        // Acoperire adaptată furnizorului: lead time real + ciclu de comandă
        $coverDays ??= $this->resolveCoverDays($supplierId);

        $rows = \Illuminate\Support\Facades\DB::table('product_suppliers as ps')
            ->join('woo_products as wp', 'wp.id', '=', 'ps.woo_product_id')
            ->leftJoin('bi_product_velocity_current as bpv', 'bpv.reference_product_id', '=', 'wp.sku')
            ->leftJoin(
                \Illuminate\Support\Facades\DB::raw('(SELECT woo_product_id, COALESCE(SUM(quantity),0) as total_qty FROM product_stocks GROUP BY woo_product_id) stk'),
                'stk.woo_product_id', '=', 'wp.id'
            )
            ->where('ps.supplier_id', $supplierId)
            ->where('wp.is_discontinued', false)
            ->where('wp.procurement_type', '!=', \App\Models\WooProduct::PROCUREMENT_ON_DEMAND)
            ->when(! empty($excludeProductIds), fn ($q) => $q->whereNotIn('ps.woo_product_id', $excludeProductIds))
            ->whereRaw('GREATEST(COALESCE(bpv.avg_out_qty_7d,0), COALESCE(bpv.avg_out_qty_30d,0), COALESCE(bpv.avg_out_qty_90d,0)) > 0')
            ->select([
                'ps.woo_product_id',
                'ps.supplier_sku',
                'wp.name',
                'wp.sku',
                'wp.min_stock_qty',
                'wp.max_stock_qty',
                \Illuminate\Support\Facades\DB::raw('COALESCE(stk.total_qty, 0) as stock'),
                \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_7d, 0)  as avg7'),
                \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_30d, 0) as avg30'),
                \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_90d, 0) as avg90'),
            ])
            ->get();

        $items = [];

        // Cantități deja pe comenzi deschise (PO-uri trimise/aprobate, nerecepționate)
        $onOrderQtys = $this->getOnOrderQtys($rows->pluck('woo_product_id')->all());

        foreach ($rows as $row) {
            $avg7  = (float) $row->avg7;
            $avg30 = (float) $row->avg30;
            $avg90 = (float) $row->avg90;
            $stock = (float) $row->stock;
            $onOrder = (float) ($onOrderQtys[$row->woo_product_id] ?? 0);

            $base  = max($avg7, $avg30, $avg90);
            $trend = 1.0;
            if ($avg30 > 0 && $avg7 > 0 && $avg7 < ($avg30 * 0.85)) {
                $trend = max(0.5, $avg7 / $avg30);
            }

            $adjustedDaily = $base * $trend;
            $safetyStock   = $adjustedDaily * 3;
            $recommended   = (int) ceil($adjustedDaily * $coverDays + $safetyStock - $stock - $onOrder);

            $daysUntilStockout = $avg7 > 0 ? round($stock / $avg7, 1) : null;

            $items[$row->woo_product_id] = [
                'hint'              => max(0, $recommended),
                'on_order'          => $onOrder,
                'name'              => $row->name,
                'sku'               => $row->sku,
                'supplier_sku'      => $row->supplier_sku,
                'stock'             => $stock,
                'sales_7d'          => round($avg7 * 7, 1),
                'sales_30d'         => round($avg30 * 30, 1),
                'days_to_stockout'  => $daysUntilStockout,
                'velocity_day'      => $adjustedDaily,
                'min_stock_qty'     => $row->min_stock_qty !== null ? (float) $row->min_stock_qty : null,
                'max_stock_qty'     => $row->max_stock_qty !== null ? (float) $row->max_stock_qty : null,
            ];
        }

        return $items;
    }

    /**
     * Zile de acoperire pentru recomandări = lead time real al furnizorului
     * (media ultimelor PO-uri recepționate) + 7 zile ciclu de comandă.
     * Fallback 10 zile (7+3) pentru furnizori fără istoric.
     */
    private function resolveCoverDays(int $supplierId): int
    {
        $avgLead = \Illuminate\Support\Facades\DB::table('purchase_orders')
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseOrder::STATUS_RECEIVED)
            ->whereNotNull('lead_time_days')
            ->orderByDesc('received_at')
            ->limit(10)
            ->avg('lead_time_days');

        if ($avgLead === null) {
            return 10;
        }

        return max(7, (int) ceil((float) $avgLead) + 7);
    }

    /**
     * Cantități aflate deja pe comenzi de achiziție deschise (nerecepționate),
     * per produs — se scad din recomandări ca să nu comandăm de două ori.
     *
     * @param  int[]  $productIds
     * @return array<int, float>
     */
    private function getOnOrderQtys(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereIn('po.status', [
                PurchaseOrder::STATUS_PENDING_APPROVAL,
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
            ->whereIn('poi.woo_product_id', $productIds)
            ->selectRaw('poi.woo_product_id, SUM(GREATEST(0, poi.quantity - COALESCE(poi.received_quantity, 0))) as qty')
            ->groupBy('poi.woo_product_id')
            ->pluck('qty', 'woo_product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Produse ale furnizorului care au rulaj dar nu sunt în necesare.
     *
     * @param  int[]  $excludeProductIds
     * @param  array<int, array{hint:int, name:string, sku:string, supplier_sku:?string}>  $velocityItems
     */
    private function buildVelocityOnlyItems(int $supplierId, array $excludeProductIds, array $velocityItems): array
    {
        $items = [];

        foreach ($velocityItems as $productId => $data) {
            if (in_array($productId, $excludeProductIds, true)) {
                continue;
            }

            if ($data['hint'] <= 0) {
                continue;
            }

            // Round hint to order multiple if set
            $hint = $data['hint'];
            $ps = ProductSupplier::where('woo_product_id', $productId)
                ->where('supplier_id', $supplierId)
                ->first();
            if ($ps && $hint > 0) {
                $hint = (int) $ps->roundToOrderMultiple((float) $hint);
            }

            $recData = [
                'from_requests'     => 0,
                'reserved_qty'      => 0,
                'general_qty'       => 0,
                'current_stock'     => $data['stock'],
                'velocity_day'      => $data['velocity_day'],
                'sales_7d'          => $data['sales_7d'],
                'sales_30d'         => $data['sales_30d'],
                'min_stock_qty'     => $data['min_stock_qty'],
                'max_stock_qty'     => $data['max_stock_qty'],
                'additional_store'  => $hint,
                'total_recommended' => $hint,
                'calc_method'       => $data['max_stock_qty'] !== null ? 'max_stock' : 'velocity',
            ];

            $items[] = [
                'woo_product_id'      => $productId,
                'product_name'        => \App\Models\WooProduct::query()->find($productId)?->decoded_name ?? $data['name'],
                'sku'                 => $data['sku'],
                'supplier_sku'        => $data['supplier_sku'],
                'quantity'            => null,
                'unit_price'          => null,
                'notes'               => null,
                'sources_json'        => null,
                'recommendation_json' => json_encode($recData, JSON_UNESCAPED_UNICODE),
                'quantity_hint'       => $hint > 0 ? $hint : null,
                'info_stock'             => $data['stock'],
                'info_sales_7d'          => $data['sales_7d'],
                'info_sales_30d'         => $data['sales_30d'],
                'info_days_stockout'     => $data['days_to_stockout'],
                'info_purchase_uom'      => $ps?->purchase_uom,
                'info_conversion_factor' => $ps?->conversion_factor ? (float) $ps->conversion_factor : null,
            ];
        }

        return $items;
    }
}
