<?php

namespace App\Filament\App\Resources\PurchaseOrderResource\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Mail\PurchaseOrderMail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use App\Notifications\PurchaseOrderNeedsApprovalNotification;
use App\Notifications\PurchaseOrderRejectedNotification;
use App\Notifications\PurchaseOrderReceivedPartialNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => $this->record->status === PurchaseOrder::STATUS_DRAFT),

            // Plasează comanda
            Actions\Action::make('place')
                ->label('Plasează comanda')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn (): bool => $this->record->status === PurchaseOrder::STATUS_DRAFT)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->refresh()->loadMissing(['supplier', 'items']);

                    if ($this->record->needsApproval()) {
                        $this->record->update(['status' => PurchaseOrder::STATUS_PENDING_APPROVAL]);
                        Notification::make()->warning()
                            ->title('Comanda necesită aprobare.')
                            ->body('A fost trimisă spre aprobare.')
                            ->send();

                        // Notificăm managerii/directorii care pot aproba
                        $approvers = User::query()
                            ->whereIn('role', [
                                User::ROLE_MANAGER,
                                User::ROLE_DIRECTOR_FINANCIAR,
                                User::ROLE_DIRECTOR_VANZARI,
                            ])
                            ->orWhere('is_super_admin', true)
                            ->where('id', '!=', auth()->id())
                            ->get();

                        foreach ($approvers as $approver) {
                            $approver->notify(new PurchaseOrderNeedsApprovalNotification($this->record));
                        }
                    } else {
                        $this->record->update([
                            'status'      => PurchaseOrder::STATUS_APPROVED,
                            'approved_at' => now(),
                            'approved_by' => auth()->id(),
                        ]);
                        Notification::make()->success()
                            ->title('Comanda a fost aprobată automat.')
                            ->send();
                    }

                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Aprobă
            Actions\Action::make('approve')
                ->label('Aprobă')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool =>
                    $this->record->status === PurchaseOrder::STATUS_PENDING_APPROVAL
                    && PurchaseOrderResource::canApprove($this->record)
                )
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status'      => PurchaseOrder::STATUS_APPROVED,
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                    ]);
                    Notification::make()->success()->title('Comanda a fost aprobată.')->send();
                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Respinge
            Actions\Action::make('reject')
                ->label('Respinge')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool =>
                    $this->record->status === PurchaseOrder::STATUS_PENDING_APPROVAL
                    && PurchaseOrderResource::canApprove($this->record)
                )
                ->form([
                    Textarea::make('rejection_reason')
                        ->label('Motiv respingere')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    // Revenim request items la PENDING înainte de a schimba statusul PO
                    $this->revertRequestItemsToPending();

                    $this->record->update([
                        'status'           => PurchaseOrder::STATUS_REJECTED,
                        'rejected_by'      => auth()->id(),
                        'rejected_at'      => now(),
                        'rejection_reason' => $data['rejection_reason'],
                    ]);
                    Notification::make()->danger()->title('Comanda a fost respinsă.')->send();

                    // Notificăm buyer-ul care a creat comanda
                    $this->record->loadMissing('buyer');
                    if ($this->record->buyer && $this->record->buyer->id !== auth()->id()) {
                        $this->record->buyer->notify(new PurchaseOrderRejectedNotification(
                            $this->record,
                            auth()->user()->name,
                        ));
                    }

                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Trimite email furnizor
            Actions\Action::make('send_email')
                ->label('Trimite email furnizor')
                ->icon('heroicon-o-envelope')
                ->color('info')
                ->visible(fn (): bool => in_array($this->record->status, [
                    PurchaseOrder::STATUS_APPROVED,
                    PurchaseOrder::STATUS_SENT,
                    PurchaseOrder::STATUS_RECEIVED,
                ]))
                ->modalHeading('Trimite comandă pe email')
                ->modalSubmitActionLabel('Trimite email')
                ->modalWidth('2xl')
                ->form(function (): array {
                    $this->record->loadMissing(['supplier.contacts', 'items']);
                    $supplier = $this->record->supplier;

                    // Preia emailul contactului primar, fallback pe emailul furnizorului
                    $primaryContact = $supplier?->contacts
                        ->sortByDesc('is_primary')
                        ->first(fn ($c) => filled($c->email));
                    $defaultEmail   = $primaryContact?->email ?? $supplier?->email ?? '';

                    $lines = [];
                    foreach ($this->record->items as $item) {
                        $line  = "- {$item->product_name}";
                        $codes = [];
                        if ($item->sku) {
                            $codes[] = "Barcode: {$item->sku}";
                        }
                        if ($item->supplier_sku) {
                            $codes[] = "cod produs: {$item->supplier_sku}";
                        }
                        if ($codes) {
                            $line .= ' (' . implode(' | ', $codes) . ')';
                        }
                        $qty      = fmod((float) $item->quantity, 1) == 0 ? (int) $item->quantity : $item->quantity;
                        $line    .= ": {$qty} buc.";
                        $lines[] = $line;
                    }

                    $body  = "Stimate partener,\n\n";
                    $body .= "Vă transmitem comanda noastră nr. {$this->record->number} din data " . now()->format('d.m.Y') . ":\n\n";
                    $body .= implode("\n", $lines);
                    if ($this->record->notes_supplier) {
                        $body .= "\n\nObservații: {$this->record->notes_supplier}";
                    }
                    $body .= "\n\nVă rugăm să confirmați primirea comenzii și disponibilitatea produselor.\n\n";
                    $body .= 'Cu stimă,' . "\n" . (auth()->user()?->name ?? 'Echipa Achiziții') . "\nSC Malinco Prodex SRL";

                    return [
                        TextInput::make('to_email')
                            ->label('Destinatar')
                            ->email()
                            ->required()
                            ->default($defaultEmail)
                            ->helperText($primaryContact ? "Contact: {$primaryContact->name}" : null),

                        TextInput::make('subject')
                            ->label('Subiect')
                            ->required()
                            ->default("Comandă furnizor {$this->record->number}"),

                        Textarea::make('body')
                            ->label('Mesaj')
                            ->rows(12)
                            ->required()
                            ->default($body),
                    ];
                })
                ->action(function (array $data): void {
                    try {
                        $this->record->loadMissing(['supplier', 'items']);

                        Mail::to($data['to_email'])->send(new PurchaseOrderMail(
                            emailSubject: $data['subject'],
                            emailBody:    $data['body'],
                            order:        $this->record,
                        ));

                        // Dacă era approved, tranzitie automată la sent
                        if ($this->record->status === PurchaseOrder::STATUS_APPROVED) {
                            $this->record->update([
                                'status'  => PurchaseOrder::STATUS_SENT,
                                'sent_at' => now(),
                            ]);
                            $this->markRequestItemsAsOrdered();
                        }

                        Notification::make()->success()
                            ->title('Email trimis cu succes.')
                            ->body("Trimis la: {$data['to_email']}")
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()->danger()
                            ->title('Eroare la trimiterea emailului.')
                            ->body($e->getMessage())
                            ->send();
                    }

                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Trimite pe WhatsApp
            Actions\Action::make('send_whatsapp')
                ->label('WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, [
                    PurchaseOrder::STATUS_APPROVED,
                    PurchaseOrder::STATUS_SENT,
                    PurchaseOrder::STATUS_RECEIVED,
                ]))
                ->modalHeading('Trimite comandă pe WhatsApp')
                ->modalSubmitActionLabel('Deschide WhatsApp')
                ->modalWidth('2xl')
                ->form(function (): array {
                    $this->record->loadMissing(['supplier.contacts', 'items']);
                    $supplier = $this->record->supplier;

                    // Preia telefonul contactului primar, fallback pe telefonul furnizorului
                    $primaryContact = $supplier?->contacts
                        ->sortByDesc('is_primary')
                        ->first(fn ($c) => filled($c->phone));
                    $defaultPhone = $primaryContact?->phone ?? $supplier?->phone ?? '';

                    // Construiește mesajul
                    $lines = [];
                    foreach ($this->record->items as $item) {
                        $line  = "• {$item->product_name}";
                        $codes = [];
                        if ($item->sku) {
                            $codes[] = "Barcode: {$item->sku}";
                        }
                        if ($item->supplier_sku) {
                            $codes[] = "cod produs: {$item->supplier_sku}";
                        }
                        if ($codes) {
                            $line .= ' (' . implode(' | ', $codes) . ')';
                        }
                        $qty   = fmod((float) $item->quantity, 1) == 0 ? (int) $item->quantity : $item->quantity;
                        $line .= ": {$qty} buc.";
                        if ($item->unit_price > 0) {
                            $line .= " × " . number_format($item->unit_price, 2, ',', '.') . " lei";
                        }
                        $lines[] = $line;
                    }

                    $message  = "Bună ziua,\n\n";
                    $message .= "Comanda nr. {$this->record->number} din " . now()->format('d.m.Y') . ":\n\n";
                    $message .= implode("\n", $lines);
                    if ($this->record->total_value > 0) {
                        $message .= "\n\n*Total: " . number_format($this->record->total_value, 2, ',', '.') . " lei*";
                    }
                    if ($this->record->notes_supplier) {
                        $message .= "\n\nObservații: {$this->record->notes_supplier}";
                    }
                    $message .= "\n\nVă rugăm confirmați disponibilitatea.\nMulțumim!";

                    return [
                        \Filament\Forms\Components\TextInput::make('phone')
                            ->label('Număr telefon')
                            ->tel()
                            ->default($defaultPhone)
                            ->required()
                            ->helperText('Format: 07xxxxxxxx sau +407xxxxxxxx'),
                        \Filament\Forms\Components\Textarea::make('message')
                            ->label('Mesaj')
                            ->default($message)
                            ->rows(12)
                            ->required(),
                    ];
                })
                ->action(function (array $data): void {
                    $phone = preg_replace('/[^0-9+]/', '', $data['phone']);
                    // Convertește format românesc la internațional
                    if (str_starts_with($phone, '0')) {
                        $phone = '4' . $phone;
                    }
                    if (! str_starts_with($phone, '+')) {
                        $phone = '+' . $phone;
                    }

                    $message = urlencode($data['message']);
                    $url     = "https://wa.me/{$phone}?text={$message}";

                    // Marchează ca trimis dacă e approved
                    if ($this->record->status === PurchaseOrder::STATUS_APPROVED) {
                        $this->record->update([
                            'status'  => PurchaseOrder::STATUS_SENT,
                            'sent_at' => now(),
                        ]);
                        $this->markRequestItemsAsOrdered();
                    }

                    $this->record->refresh();
                    $this->fillForm();

                    // Redirect la WhatsApp
                    $this->js("window.open('{$url}', '_blank')");
                }),

            // Marchează trimis (fără email)
            Actions\Action::make('mark_sent')
                ->label('Marchează trimis')
                ->icon('heroicon-o-check')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === PurchaseOrder::STATUS_APPROVED)
                ->requiresConfirmation()
                ->modalHeading('Marchezi comanda ca trimisă?')
                ->modalDescription('Folosește această opțiune dacă ai comunicat comanda prin alt canal (telefon, fax). Pentru trimitere prin email folosește butonul "Trimite email furnizor".')
                ->action(function (): void {
                    $this->record->update([
                        'status'  => PurchaseOrder::STATUS_SENT,
                        'sent_at' => now(),
                    ]);

                    $this->markRequestItemsAsOrdered();

                    Notification::make()->success()->title('Comanda a fost marcată ca trimisă.')->send();
                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Marchează recepționat — detaliat pe linii
            Actions\Action::make('mark_received')
                ->label('Recepție marfă')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === PurchaseOrder::STATUS_SENT)
                ->modalHeading('Recepție marfă — ' . $this->record->number)
                ->modalDescription('Introduceți cantitățile efectiv recepționate. Lipsurile vor fi returnate automat în coada de cumpărare.')
                ->modalWidth('2xl')
                ->form(function (): array {
                    $this->record->loadMissing('items');

                    $defaultItems = $this->record->items->map(function ($item) {
                        // Get last purchase price from history if available
                        $lastPrice = (float) $item->unit_price;
                        if ($item->woo_product_id) {
                            $historyPrice = \App\Models\ProductPurchasePriceLog::where('woo_product_id', $item->woo_product_id)
                                ->latest('recorded_at')
                                ->value('purchase_price');
                            if ($historyPrice) {
                                $lastPrice = (float) $historyPrice;
                            }
                        }

                        $qty = (float) $item->quantity;
                        return [
                            'id'          => $item->id,
                            'name'        => $item->product_name . ($item->sku ? " [{$item->sku}]" : ''),
                            'ordered_qty' => floor($qty) == $qty ? number_format($qty, 0, '.', '') : number_format($qty, 2, '.', ''),
                            'qty'         => $qty,
                            'price'       => round($lastPrice, 2),
                        ];
                    })->values()->all();

                    return [
                        Repeater::make('items')
                            ->label('')
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('name')->label('Produs')->disabled()->dehydrated(false)->columnSpan(2),
                                TextInput::make('ordered_qty')->label('Comandat')->disabled()->dehydrated(false)->suffix('buc.'),
                                TextInput::make('qty')->label('Recepționat')->numeric()->minValue(0)->suffix('buc.')->required()
                                    ->live(onBlur: true),
                                TextInput::make('price')->label('Preț achiziție (fără TVA)')->numeric()->minValue(0)->suffix('RON')->required()
                                    ->live(onBlur: true),
                            ])
                            ->columns(5)
                            ->default($defaultItems)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),

                        Placeholder::make('total_reception')
                            ->label('')
                            ->content(function (Get $get): HtmlString {
                                $items = $get('items') ?? [];
                                $total = 0;
                                $lines = [];
                                foreach ($items as $item) {
                                    $qty = (float) ($item['qty'] ?? 0);
                                    $price = (float) ($item['price'] ?? 0);
                                    $lineTotal = $qty * $price;
                                    $total += $lineTotal;
                                }
                                $totalFormatted = number_format($total, 2, ',', '.');
                                $totalWithVat = number_format($total * 1.21, 2, ',', '.');
                                return new HtmlString("
                                    <div style=\"display:flex;justify-content:flex-end;gap:24px;padding:12px 0;border-top:2px solid #e5e7eb;font-size:0.95rem;\">
                                        <div><span style=\"color:#6b7280;\">Total fără TVA:</span> <strong>{$totalFormatted} RON</strong></div>
                                        <div><span style=\"color:#6b7280;\">Total cu TVA (21%):</span> <strong>{$totalWithVat} RON</strong></div>
                                    </div>
                                ");
                            }),

                        Textarea::make('received_notes')
                            ->label('Observații recepție')
                            ->placeholder('Ex: Factură nr. xxx, lipsuri notate, produse deteriorate...')
                            ->rows(2),
                    ];
                })
                ->action(function (array $data): void {
                    $this->record->loadMissing('items');

                    $affectedRequestIds = [];
                    $hasShortfall       = false;
                    $shortfallProducts  = [];

                    $itemsById = $this->record->items->keyBy('id');

                    foreach ($data['items'] as $row) {
                        $orderItem = $itemsById->get((int) $row['id']);
                        if (! $orderItem) {
                            continue;
                        }

                        $receivedQty = (float) ($row['qty'] ?? (float) $orderItem->quantity);
                        $orderedQty  = (float) $orderItem->quantity;
                        $shortfall   = max(0, $orderedQty - $receivedQty);

                        $orderItem->update([
                            'received_quantity' => $receivedQty,
                            'unit_price'        => (float) $row['price'],
                        ]);

                        if ($shortfall <= 0) {
                            continue;
                        }

                        $hasShortfall      = true;
                        $shortfallProducts[] = $orderItem->product_name;

                        // Returnăm shortfall-ul la request items (backorder)
                        $this->revertShortfallToRequestItems($orderItem, $shortfall, $affectedRequestIds);
                    }

                    foreach (array_unique($affectedRequestIds) as $requestId) {
                        \App\Models\PurchaseRequest::find($requestId)?->recalculateStatus();
                    }

                    $this->record->update([
                        'status'         => PurchaseOrder::STATUS_RECEIVED,
                        'received_at'    => now(),
                        'received_by'    => auth()->id(),
                        'received_notes' => $data['received_notes'] ?? null,
                    ]);

                    // Update last purchase info on product-supplier pivot
                    foreach ($this->record->items as $item) {
                        if ($item->woo_product_id && $this->record->supplier_id) {
                            \App\Models\ProductSupplier::where('woo_product_id', $item->woo_product_id)
                                ->where('supplier_id', $this->record->supplier_id)
                                ->update([
                                    'last_purchase_date'  => now()->toDateString(),
                                    'last_purchase_price' => $item->unit_price,
                                ]);
                        }
                    }

                    $msg = $hasShortfall
                        ? 'Recepție înregistrată. Lipsurile au fost returnate în coada de cumpărare.'
                        : 'Recepție completă înregistrată.';

                    Notification::make()->success()->title($msg)->send();

                    // Notificăm consultanții care au items cu lipsuri
                    if ($hasShortfall && ! empty($shortfallProducts)) {
                        $consultantIds = collect($affectedRequestIds)
                            ->unique()
                            ->map(fn ($id) => \App\Models\PurchaseRequest::find($id)?->user_id)
                            ->filter()
                            ->unique();

                        $consultants = User::query()
                            ->whereIn('id', $consultantIds)
                            ->where('id', '!=', auth()->id())
                            ->get();

                        foreach ($consultants as $consultant) {
                            $consultant->notify(new PurchaseOrderReceivedPartialNotification(
                                $this->record,
                                $shortfallProducts,
                            ));
                        }
                    }

                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Descarcă PDF
            Actions\Action::make('download_pdf')
                ->label('Descarcă PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (): \Symfony\Component\HttpFoundation\StreamedResponse {
                    $this->record->loadMissing(['supplier', 'buyer', 'approvedBy', 'items']);

                    $pdf = Pdf::loadView('pdf.purchase-order', ['order' => $this->record])
                        ->setPaper('a4', 'portrait');

                    $filename = str_replace('/', '-', $this->record->number) . '.pdf';

                    return response()->streamDownload(
                        fn () => print($pdf->output()),
                        $filename,
                        ['Content-Type' => 'application/pdf'],
                    );
                }),

            // Anulează
            Actions\Action::make('cancel')
                ->label('Anulează')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->visible(fn (): bool => ! in_array($this->record->status, [
                    PurchaseOrder::STATUS_SENT,
                    PurchaseOrder::STATUS_RECEIVED,
                    PurchaseOrder::STATUS_CANCELLED,
                ]))
                ->requiresConfirmation()
                ->modalHeading('Anulezi comanda?')
                ->modalDescription('Produsele asociate din necesare vor fi revenite la starea "în așteptare".')
                ->action(function (): void {
                    $this->revertRequestItemsToPending();
                    $this->record->update(['status' => PurchaseOrder::STATUS_CANCELLED]);
                    Notification::make()->success()->title('Comanda a fost anulată.')->send();
                    $this->record->refresh();
                    $this->fillForm();
                }),
        ];
    }

    /**
     * Marchează request items ca ordered când PO ajunge la status "sent".
     * Iterează toate sursele din sources_json (nu doar FK-ul primar).
     * Marchează ca ORDERED doar dacă item-ul e complet acoperit (ordered_quantity >= quantity).
     */
    private function markRequestItemsAsOrdered(): void
    {
        $affectedRequestIds = [];
        $processedItemIds   = [];

        foreach ($this->record->items as $orderItem) {
            // Colectăm toți request item IDs din sources_json
            $sourceItemIds = [];

            if (filled($orderItem->sources_json)) {
                $sources = json_decode($orderItem->sources_json, true);
                if (is_array($sources)) {
                    foreach ($sources as $source) {
                        $id = $source['request_item_id'] ?? null;
                        if ($id && ($source['allocated_qty'] ?? 0) > 0) {
                            $sourceItemIds[] = $id;
                        }
                    }
                }
            }

            // Fallback pe FK-ul primar dacă sources_json e gol
            if (empty($sourceItemIds) && $orderItem->purchase_request_item_id) {
                $sourceItemIds[] = $orderItem->purchase_request_item_id;
            }

            foreach ($sourceItemIds as $requestItemId) {
                if (in_array($requestItemId, $processedItemIds, true)) {
                    continue;
                }
                $processedItemIds[] = $requestItemId;

                $requestItem = PurchaseRequestItem::find($requestItemId);
                if (! $requestItem) {
                    continue;
                }

                // Marcăm ca ORDERED doar dacă cantitatea e complet acoperită
                if ($requestItem->isFullyOrdered()) {
                    $requestItem->update(['status' => PurchaseRequestItem::STATUS_ORDERED]);
                }

                $affectedRequestIds[] = $requestItem->purchase_request_id;
            }
        }

        foreach (array_unique($affectedRequestIds) as $requestId) {
            \App\Models\PurchaseRequest::find($requestId)?->recalculateStatus();
        }
    }

    /**
     * Returnează shortfall-ul (cantitate nelivrată) înapoi la request items ca backorder.
     * Scade proportional din allocated_qty în sources_json, în ordine inversă FIFO.
     */
    private function revertShortfallToRequestItems(\App\Models\PurchaseOrderItem $orderItem, float $shortfall, array &$affectedRequestIds): void
    {
        if (blank($orderItem->sources_json)) {
            return;
        }

        $sources = json_decode($orderItem->sources_json, true);
        if (! is_array($sources)) {
            return;
        }

        // Inversăm FIFO: ultimii alocați sunt primii returnați
        $remaining = $shortfall;
        foreach (array_reverse($sources) as $source) {
            if ($remaining <= 0) {
                break;
            }

            $allocatedQty  = (float) ($source['allocated_qty'] ?? 0);
            $requestItemId = $source['request_item_id'] ?? null;

            if ($allocatedQty <= 0 || ! $requestItemId) {
                continue;
            }

            $requestItem = PurchaseRequestItem::find($requestItemId);
            if (! $requestItem) {
                continue;
            }

            $reduction     = min($remaining, $allocatedQty);
            $newOrderedQty = max(0, (float) $requestItem->ordered_quantity - $reduction);

            $updates = [
                'ordered_quantity' => $newOrderedQty,
                'status'           => PurchaseRequestItem::STATUS_PENDING,
            ];

            // Dacă nu mai are nimic ordered → curățăm referința
            if ($newOrderedQty <= 0) {
                $updates['purchase_order_item_id'] = null;
            }

            $requestItem->update($updates);
            $affectedRequestIds[] = $requestItem->purchase_request_id;
            $remaining -= $reduction;
        }
    }

    /**
     * Reverte request items când PO este anulată.
     * Scade allocated_qty din ordered_quantity (din sources_json).
     * Dacă ordered_quantity ajunge la 0 → STATUS_PENDING + șterge referința PO.
     * Dacă mai rămâne ordered (din alte PO-uri) → rămâne pending cu ordered_quantity redus.
     */
    private function revertRequestItemsToPending(): void
    {
        $affectedRequestIds = [];

        foreach ($this->record->items as $orderItem) {
            if (blank($orderItem->sources_json)) {
                continue;
            }

            $sources = json_decode($orderItem->sources_json, true);
            if (! is_array($sources)) {
                continue;
            }

            foreach ($sources as $source) {
                $allocatedQty  = (float) ($source['allocated_qty'] ?? 0);
                $requestItemId = $source['request_item_id'] ?? null;

                if ($allocatedQty <= 0 || ! $requestItemId) {
                    continue;
                }

                $requestItem = PurchaseRequestItem::find($requestItemId);
                if (! $requestItem) {
                    continue;
                }

                $newOrderedQty = max(0, (float) $requestItem->ordered_quantity - $allocatedQty);

                $updates = ['ordered_quantity' => $newOrderedQty];

                // Dacă nu mai are nimic comandat → status pending + clear referință PO
                if ($newOrderedQty <= 0) {
                    $updates['status']                 = PurchaseRequestItem::STATUS_PENDING;
                    $updates['purchase_order_item_id'] = null;
                    $updates['ordered_quantity']       = 0;
                } else {
                    // Mai are cantitate comandată din altă PO → rămâne pending
                    $updates['status'] = PurchaseRequestItem::STATUS_PENDING;
                }

                $requestItem->update($updates);
                $affectedRequestIds[] = $requestItem->purchase_request_id;
            }
        }

        foreach (array_unique($affectedRequestIds) as $requestId) {
            $request = \App\Models\PurchaseRequest::find($requestId);
            $request?->recalculateStatus();
        }
    }
}
