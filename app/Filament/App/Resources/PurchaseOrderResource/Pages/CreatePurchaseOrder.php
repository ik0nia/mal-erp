<?php

namespace App\Filament\App\Resources\PurchaseOrderResource\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public bool $skipEmptyCheck = false;
    public int  $emptyItemCount = 0;
    public int  $supplierId     = 0;

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
        if (! $this->skipEmptyCheck) {
            $rawState  = $this->form->getRawState();
            $emptyCount = collect($rawState['items'] ?? [])
                ->filter(fn ($item) => ! isset($item['quantity']) || (float) $item['quantity'] <= 0)
                ->count();

            if ($emptyCount > 0) {
                $this->emptyItemCount = $emptyCount;
                $this->mountAction('confirmEmptyItems');

                return;
            }
        }

        $this->skipEmptyCheck = false;
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

                    $items = $this->data['items'] ?? [];
                    foreach ($items as &$item) {
                        $pid = $item['woo_product_id'] ?? null;
                        if ($pid && isset($newQtys[$pid])) {
                            $item['quantity'] = (float) $newQtys[$pid];
                        }
                    }
                    unset($item);

                    $this->data['items'] = $items;
                    $this->form->fill($this->data);

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Cantități aplicate în PO')
                        ->send();
                })
                ->visible(fn (): bool => $this->supplierId > 0),

            Action::make('confirmEmptyItems')
                ->hidden()
                ->requiresConfirmation()
                ->modalHeading('Poziții fără cantitate')
                ->modalDescription(fn () => 'Există ' . $this->emptyItemCount . ' ' .
                    ($this->emptyItemCount === 1 ? 'poziție' : 'poziții') .
                    ' fără cantitate completată. Dacă continui, acestea vor fi ignorate. Ești sigur că vrei să creezi PO-ul?')
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalIconColor('warning')
                ->modalSubmitActionLabel('Da, creează PO')
                ->modalCancelActionLabel('Nu, vreau să completez')
                ->color('warning')
                ->action(function () {
                    $this->skipEmptyCheck = true;
                    $this->create();
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
                if (blank($orderItem->sources_json)) {
                    continue;
                }

                $sources = json_decode($orderItem->sources_json, true);
                if (! is_array($sources) || empty($sources)) {
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
     * Construiește structura produselor furnizorului grupate pe categorii,
     * cu cantități recomandate pe baza velocității.
     */
    private function buildCategoryStructure(int $supplierId): array
    {
        if (! $supplierId) {
            return [];
        }

        // Produse furnizor cu date velocity și stoc
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
                'wp.id', 'wp.name', 'wp.sku', 'wp.min_stock_qty', 'wp.max_stock_qty',
                'ps.supplier_sku',
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
        $products   = [];

        foreach ($rows as $row) {
            $avg7  = (float) $row->avg7;
            $avg30 = (float) $row->avg30;
            $avg90 = (float) $row->avg90;
            $stock = (float) $row->stock;

            $base  = max($avg7, $avg30, $avg90);
            if ($base <= 0) {
                continue; // fără rulaj — skip
            }

            $trend  = ($avg30 > 0 && $avg7 > 0 && $avg7 < ($avg30 * 0.85))
                ? max(0.5, $avg7 / $avg30) : 1.0;
            $daily  = $base * $trend;

            $maxStock = $row->max_stock_qty !== null ? (float) $row->max_stock_qty : null;
            if ($maxStock !== null && $maxStock > 0) {
                $recommended = max(0, (int) ceil($maxStock - $stock));
            } else {
                $safety      = $daily * 3;
                $recommended = max(0, (int) ceil($daily * 7 + $safety - $stock));
            }

            // Round to order multiple if set
            if ($recommended > 0) {
                $psRec = ProductSupplier::where('woo_product_id', $row->id)
                    ->where('supplier_id', $supplierId)
                    ->first();
                if ($psRec) {
                    $recommended = (int) $psRec->roundToOrderMultiple((float) $recommended);
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

        // Produse fără nicio categorie
        $uncategorized = array_keys(array_diff_key($products, $catRows->toArray()));
        foreach ($uncategorized as $productId) {
            $categories['Fără categorie'][] = $products[$productId];
        }

        ksort($categories);

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
                // post-livrare: currentStock + generalQty + additionalStore >= maxStock
                $additionalStore = max(0, (int) ceil($maxStock - $currentStock - $generalQty));
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
                'info_stock'          => $vi['stock'] ?? null,
                'info_sales_7d'       => $vi['sales_7d'] ?? null,
                'info_days_stockout'  => $vi['days_to_stockout'] ?? null,
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
    private function getVelocityItems(int $supplierId, array $excludeProductIds, int $coverDays = 7): array
    {
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

        foreach ($rows as $row) {
            $avg7  = (float) $row->avg7;
            $avg30 = (float) $row->avg30;
            $avg90 = (float) $row->avg90;
            $stock = (float) $row->stock;

            $base  = max($avg7, $avg30, $avg90);
            $trend = 1.0;
            if ($avg30 > 0 && $avg7 > 0 && $avg7 < ($avg30 * 0.85)) {
                $trend = max(0.5, $avg7 / $avg30);
            }

            $adjustedDaily = $base * $trend;
            $safetyStock   = $adjustedDaily * 3;
            $recommended   = (int) ceil($adjustedDaily * $coverDays + $safetyStock - $stock);

            $daysUntilStockout = $avg7 > 0 ? round($stock / $avg7, 1) : null;

            $items[$row->woo_product_id] = [
                'hint'              => max(0, $recommended),
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
                'info_stock'          => $data['stock'],
                'info_sales_7d'       => $data['sales_7d'],
                'info_days_stockout'  => $data['days_to_stockout'],
            ];
        }

        return $items;
    }
}
