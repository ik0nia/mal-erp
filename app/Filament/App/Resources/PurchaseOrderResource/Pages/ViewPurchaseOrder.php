<?php

namespace App\Filament\App\Resources\PurchaseOrderResource\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Jobs\PushComenziFurnizoriToWinmentorJob;
use App\Mail\PurchaseOrderMail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use App\Notifications\PurchaseOrderNeedsApprovalNotification;
use App\Notifications\PurchaseOrderRejectedNotification;
use App\Notifications\PurchaseOrderReceivedPartialNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public function getTitle(): string
    {
        return $this->record->number . ' — ' . ($this->record->supplier?->name ?? '');
    }

    public function mount(int | string $record): void
    {
        parent::mount($record);

        if ($this->record->status === PurchaseOrder::STATUS_RECEIVED) {
            $hasUnconfirmedPrices = $this->record->items()->where('unit_price', 0)->exists();
            if ($hasUnconfirmedPrices) {
                Notification::make()
                    ->warning()
                    ->title('Prețuri de achiziție neconfirmate')
                    ->body('Unele produse au prețul de achiziție necompletat. Folosiți butonul "Confirmă prețuri" pentru a introduce prețurile și datele de factură.')
                    ->persistent()
                    ->send();
            }
        }
    }

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
                ->label(fn (): string => $this->record->sent_via === 'email' ? 'Retrimite email furnizor' : 'Trimite email furnizor')
                ->icon('heroicon-o-envelope')
                ->color(fn (): string => $this->record->status === PurchaseOrder::STATUS_APPROVED ? 'info' : 'gray')
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
                        $qty = fmod((float) $item->quantity, 1) == 0 ? (int) $item->quantity : $item->quantity;
                        if ($item->supplier_sku) {
                            // Avem codul furnizorului — trimitem cu codul lor
                            $line = "- {$item->product_name} (cod produs: {$item->supplier_sku}): {$qty} buc.";
                        } elseif ($item->sku) {
                            // Fără cod furnizor — trimitem cu SKU-ul nostru (barcode)
                            $line = "- {$item->product_name} (Barcode: {$item->sku}): {$qty} buc.";
                        } else {
                            $line = "- {$item->product_name}: {$qty} buc.";
                        }
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
                                'status'   => PurchaseOrder::STATUS_SENT,
                                'sent_at'  => now(),
                                'sent_via' => 'email',
                                'sent_by'  => auth()->id(),
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
                ->color(fn (): string => $this->record->status === PurchaseOrder::STATUS_APPROVED ? 'success' : 'gray')
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
                        $qty = fmod((float) $item->quantity, 1) == 0 ? (int) $item->quantity : $item->quantity;
                        if ($item->supplier_sku) {
                            $line = "• {$item->product_name} (cod produs: {$item->supplier_sku}): {$qty} buc.";
                        } elseif ($item->sku) {
                            $line = "• {$item->product_name} (Barcode: {$item->sku}): {$qty} buc.";
                        } else {
                            $line = "• {$item->product_name}: {$qty} buc.";
                        }
                        $lines[] = $line;
                    }

                    $message  = "Bună ziua,\n\n";
                    $message .= "Comanda nr. {$this->record->number} din " . now()->format('d.m.Y') . ":\n\n";
                    $message .= implode("\n", $lines);
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
                            'status'   => PurchaseOrder::STATUS_SENT,
                            'sent_at'  => now(),
                            'sent_via' => 'whatsapp',
                            'sent_by'  => auth()->id(),
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
                        'status'   => PurchaseOrder::STATUS_SENT,
                        'sent_at'  => now(),
                        'sent_via' => 'manual',
                        'sent_by'  => auth()->id(),
                    ]);

                    $this->markRequestItemsAsOrdered();

                    Notification::make()->success()->title('Comanda a fost marcată ca trimisă.')->send();
                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Recepție cantitativă — fără prețuri
            Actions\Action::make('quantitative_receive')
                ->label('Recepție cantitativă')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === PurchaseOrder::STATUS_SENT)
                ->modalHeading('Recepție cantitativă — ' . $this->record->number)
                ->modalDescription('Introduceți cantitățile recepționate. Produsele șterse sau cu cantitate 0 vor fi returnate în coada de cumpărare.')
                ->modalWidth('5xl')
                ->extraModalWindowAttributes(['x-on:keydown.enter' => '$event.preventDefault()'])
                ->form(function (): array {
                    $this->record->loadMissing('items');

                    $defaultItems = $this->record->items->map(function ($item) {
                        $qty = (float) $item->quantity;
                        return [
                            'id'          => $item->id,
                            'name'        => $item->product_name . ($item->sku ? " [{$item->sku}]" : ''),
                            'ordered_qty' => floor($qty) == $qty ? number_format($qty, 0, '.', '') : number_format($qty, 2, '.', ''),
                            'qty'         => $qty,
                        ];
                    })->values()->all();

                    return [
                        Repeater::make('items')
                            ->label('')
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('name')->label('Produs')
                                    ->disabled()
                                    ->dehydrated()
                                    ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => !empty($get('id')))
                                    ->columnSpan(4),
                                \Filament\Forms\Components\Select::make('woo_product_id')
                                    ->label('Selectează produs')
                                    ->searchable()
                                    ->getSearchResultsUsing(function (string $search) {
                                        $supplierId = $this->record->supplier_id;
                                        return \App\Models\WooProduct::query()
                                            ->where(function ($q) use ($search) {
                                                $q->where('name', 'like', "%{$search}%")
                                                  ->orWhere('sku', 'like', "%{$search}%");
                                            })
                                            ->when($supplierId, function ($q) use ($supplierId) {
                                                $q->whereHas('suppliers', fn ($s) => $s->where('suppliers.id', $supplierId));
                                            })
                                            ->limit(20)
                                            ->get()
                                            ->mapWithKeys(fn ($p) => [$p->id => $p->name . ' [' . $p->sku . ']'])
                                            ->all();
                                    })
                                    ->getOptionLabelUsing(fn ($value) => \App\Models\WooProduct::find($value)?->name)
                                    ->afterStateUpdated(function ($state, \Filament\Forms\Set $set) {
                                        if ($state) {
                                            $product = \App\Models\WooProduct::find($state);
                                            if ($product) {
                                                $set('name', $product->name . ' [' . $product->sku . ']');
                                            }
                                        }
                                    })
                                    ->live()
                                    ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => empty($get('id')))
                                    ->columnSpan(4),
                                TextInput::make('ordered_qty')->label('Comandat')->disabled()->dehydrated(false)->suffix('buc.'),
                                TextInput::make('qty')->label('Recepționat')->numeric()->minValue(0)->suffix('buc.')->required(),
                            ])
                            ->columns(6)
                            ->default($defaultItems)
                            ->addable(true)
                            ->addActionLabel('+ Adaugă produs suplimentar')
                            ->deletable(true)
                            ->reorderable(false),

                        Textarea::make('received_notes')
                            ->label('Observații recepție')
                            ->placeholder('Ex: lipsuri notate, produse deteriorate...')
                            ->rows(2),
                    ];
                })
                ->action(function (array $data): void {
                    $this->record->loadMissing('items');

                    $affectedRequestIds = [];
                    $hasShortfall       = false;
                    $shortfallProducts  = [];

                    // Map submitted rows by existing item id
                    $submittedById = [];
                    $newRows       = [];
                    foreach ($data['items'] as $row) {
                        $itemId = (int) ($row['id'] ?? 0);
                        if ($itemId) {
                            $submittedById[$itemId] = (float) ($row['qty'] ?? 0);
                        } else {
                            $newRows[] = $row;
                        }
                    }

                    foreach ($this->record->items as $orderItem) {
                        // Items deleted from repeater → treat as qty 0
                        $receivedQty = $submittedById[$orderItem->id] ?? 0.0;
                        $orderedQty  = (float) $orderItem->quantity;
                        $shortfall   = max(0, $orderedQty - $receivedQty);

                        $orderItem->update(['received_quantity' => $receivedQty]);

                        if ($shortfall > 0) {
                            $hasShortfall = true;
                            $shortfallProducts[] = $orderItem->product_name;
                            $this->revertShortfallToRequestItems($orderItem, $shortfall, $affectedRequestIds);
                        }
                    }

                    // New products added during reception
                    foreach ($newRows as $row) {
                        $receivedQty = (float) ($row['qty'] ?? 0);
                        if ($receivedQty <= 0) {
                            continue;
                        }

                        $wooProductId = $row['woo_product_id'] ?? null;
                        $productName  = $row['name'] ?? '';
                        $sku          = null;

                        if ($wooProductId) {
                            $product = \App\Models\WooProduct::find($wooProductId);
                            if ($product) {
                                $productName = $product->name;
                                $sku         = $product->sku;
                            }
                        }

                        if (! $productName) {
                            continue;
                        }

                        $this->record->items()->create([
                            'product_name'     => $productName,
                            'sku'              => $sku,
                            'woo_product_id'   => $wooProductId,
                            'quantity'         => $receivedQty,
                            'received_quantity' => $receivedQty,
                            'unit_price'       => 0,
                            'notes'            => 'Adăugat la recepție cantitativă (suplimentar)',
                        ]);
                    }

                    foreach (array_unique($affectedRequestIds) as $requestId) {
                        \App\Models\PurchaseRequest::find($requestId)?->recalculateStatus();
                    }

                    $this->record->update([
                        'status'                => PurchaseOrder::STATUS_RECEIVED,
                        'received_at'           => now(),
                        'received_by'           => auth()->id(),
                        'received_notes'        => $data['received_notes'] ?? null,
                        'winmentor_sync_status' => PurchaseOrder::WINMENTOR_PENDING,
                        'winmentor_sync_error'  => null,
                    ]);

                    PushComenziFurnizoriToWinmentorJob::dispatch($this->record->id)->afterCommit();

                    $msg = $hasShortfall
                        ? 'Recepție cantitativă înregistrată. Lipsurile au fost returnate în coada de cumpărare.'
                        : 'Recepție cantitativă completă înregistrată.';

                    Notification::make()->success()->title($msg)->send();

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

            // Marchează recepționat — detaliat pe linii
            // Vizibil și după recepție cantitativă (STATUS_RECEIVED cu prețuri lipsă) pentru a adăuga prețurile
            Actions\Action::make('mark_received')
                ->label('Recepție marfă')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('success')
                ->visible(fn (): bool =>
                    $this->record->status === PurchaseOrder::STATUS_SENT
                    || (
                        $this->record->status === PurchaseOrder::STATUS_RECEIVED
                        && $this->record->items()->where('unit_price', 0)->exists()
                    )
                )
                ->modalHeading('Recepție marfă — ' . $this->record->number)
                ->modalDescription(fn (): string => $this->record->status === PurchaseOrder::STATUS_RECEIVED
                    ? 'Cantitățile au fost preluate din recepția cantitativă. Introduceți prețurile și datele de factură.'
                    : 'Introduceți cantitățile efectiv recepționate. Lipsurile vor fi returnate automat în coada de cumpărare.'
                )
                ->modalWidth('7xl')
                ->extraModalWindowAttributes(['x-on:keydown.enter' => '$event.preventDefault()'])
                ->form(function (): array {
                    $this->record->loadMissing('items');
                    $isPriceOnly = $this->record->status === PurchaseOrder::STATUS_RECEIVED;

                    $defaultItems = $this->record->items->map(function ($item) use ($isPriceOnly) {
                        $lastPrice = (float) $item->unit_price;
                        if (! $isPriceOnly && $item->woo_product_id) {
                            $historyPrice = \App\Models\ProductPurchasePriceLog::where('woo_product_id', $item->woo_product_id)
                                ->latest('acquired_at')
                                ->value('unit_price');
                            if ($historyPrice) {
                                $lastPrice = (float) $historyPrice;
                            }
                        }

                        // În modul preț-only: cantitatea vine din received_quantity (deja recepționat)
                        $qty = $isPriceOnly
                            ? (float) $item->received_quantity
                            : (float) $item->quantity;

                        return [
                            'id'          => $item->id,
                            'name'        => $item->product_name . ($item->sku ? " [{$item->sku}]" : ''),
                            'ordered_qty' => $isPriceOnly
                                ? (floor($qty) == $qty ? number_format($qty, 0, '.', '') : number_format($qty, 2, '.', ''))
                                : (floor((float) $item->quantity) == (float) $item->quantity ? number_format((float) $item->quantity, 0, '.', '') : number_format((float) $item->quantity, 2, '.', '')),
                            'qty'         => $qty,
                            'price'       => round($lastPrice, 2),
                        ];
                    })->values()->all();

                    return [
                        \Filament\Schemas\Components\Grid::make(3)->schema([
                            TextInput::make('invoice_number')
                                ->label('Serie / Nr. factură')
                                ->default(trim(($this->record->invoice_series ? $this->record->invoice_series . ' ' : '') . ($this->record->invoice_number ?? '')))
                                ->placeholder('Ex: FAV-SER-1068895 sau RO 1234'),
                            \Filament\Forms\Components\DatePicker::make('invoice_date')->label('Data factură')->displayFormat('d.m.Y')->default($this->record->invoice_date),
                            \Filament\Forms\Components\DatePicker::make('invoice_due_date')->label('Scadență')->displayFormat('d.m.Y')->default($this->record->invoice_due_date),
                        ]),

                        Repeater::make('items')
                            ->label('')
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('name')->label('Produs')
                                    ->disabled()
                                    ->dehydrated()
                                    ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => !empty($get('id')))
                                    ->columnSpan(3),
                                \Filament\Forms\Components\Select::make('woo_product_id')
                                    ->label('Selectează produs')
                                    ->searchable()
                                    ->getSearchResultsUsing(function (string $search) {
                                        $supplierId = $this->record->supplier_id;
                                        return \App\Models\WooProduct::query()
                                            ->where(function ($q) use ($search) {
                                                $q->where('name', 'like', "%{$search}%")
                                                  ->orWhere('sku', 'like', "%{$search}%");
                                            })
                                            ->when($supplierId, function ($q) use ($supplierId) {
                                                $q->whereHas('suppliers', fn ($s) => $s->where('suppliers.id', $supplierId));
                                            })
                                            ->limit(20)
                                            ->get()
                                            ->mapWithKeys(fn ($p) => [$p->id => $p->name . ' [' . $p->sku . ']'])
                                            ->all();
                                    })
                                    ->getOptionLabelUsing(fn ($value) => \App\Models\WooProduct::find($value)?->name)
                                    ->afterStateUpdated(function ($state, \Filament\Forms\Set $set) {
                                        if ($state) {
                                            $product = \App\Models\WooProduct::find($state);
                                            if ($product) {
                                                $set('name', $product->name . ' [' . $product->sku . ']');
                                                $lastPrice = \App\Models\ProductPurchasePriceLog::where('woo_product_id', $product->id)
                                                    ->latest('acquired_at')
                                                    ->value('unit_price');
                                                if ($lastPrice) {
                                                    $set('price', round((float) $lastPrice, 2));
                                                }
                                            }
                                        }
                                    })
                                    ->live()
                                    ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => empty($get('id')))
                                    ->columnSpan(3),
                                TextInput::make('ordered_qty')
                                    ->label($isPriceOnly ? 'Recepționat' : 'Comandat')
                                    ->disabled()->dehydrated(false)->suffix('buc.'),
                                TextInput::make('qty')->label('Recepționat')->numeric()->minValue(0)->suffix('buc.')->required()
                                    ->live(onBlur: true)
                                    ->disabled($isPriceOnly)
                                    ->dehydrated(! $isPriceOnly),
                                TextInput::make('price')->label('Preț achiziție (fără TVA)')->numeric()->minValue(0)->suffix('RON')->required()
                                    ->live(onBlur: true),
                            ])
                            ->columns($isPriceOnly ? 5 : 6)
                            ->default($defaultItems)
                            ->addable(! $isPriceOnly)
                            ->addActionLabel('+ Adaugă produs suplimentar')
                            ->deletable(false)
                            ->reorderable(false),

                        \Filament\Schemas\Components\Grid::make(2)->schema([
                            TextInput::make('transport_cost')
                                ->label('Cost transport (se adaugă)')
                                ->numeric()->default(0)->suffix('RON')
                                ->live(onBlur: true),
                            TextInput::make('discount_value')
                                ->label('Discount (se scade)')
                                ->numeric()->default(0)->suffix('RON')
                                ->live(onBlur: true),
                        ]),

                        Placeholder::make('total_reception')
                            ->label('')
                            ->content(function (Get $get) use ($isPriceOnly): HtmlString {
                                $items = $get('items') ?? [];
                                $subtotal = 0;
                                $totalQty = 0;
                                foreach ($items as $item) {
                                    $qty = $isPriceOnly
                                        ? (float) ($item['ordered_qty'] ?? 0)
                                        : (float) ($item['qty'] ?? 0);
                                    $price = (float) ($item['price'] ?? 0);
                                    $subtotal += $qty * $price;
                                    $totalQty += $qty;
                                }
                                $transport = (float) ($get('transport_cost') ?? 0);
                                $discount = (float) ($get('discount_value') ?? 0);
                                $total = $subtotal + $transport - $discount;

                                $adjustPerUnit = $totalQty > 0 ? ($transport - $discount) / $totalQty : 0;
                                $adjustText = '';
                                if ($transport > 0 || $discount > 0) {
                                    $sign = $adjustPerUnit >= 0 ? '+' : '';
                                    $adjustText = "<div style=\"color:#6b7280;font-size:0.8rem;\">Ajustare/buc: {$sign}" . number_format($adjustPerUnit, 4, ',', '.') . " RON</div>";
                                }

                                $subtotalFmt = number_format($subtotal, 2, ',', '.');
                                $totalFmt = number_format($total, 2, ',', '.');
                                $totalVat = number_format($total * 1.21, 2, ',', '.');
                                return new HtmlString("
                                    <div style=\"display:flex;justify-content:flex-end;gap:24px;align-items:center;padding:12px 0;border-top:2px solid #e5e7eb;font-size:0.95rem;flex-wrap:wrap;\">
                                        <div><span style=\"color:#6b7280;\">Subtotal produse:</span> <strong>{$subtotalFmt} RON</strong></div>
                                        <div><span style=\"color:#6b7280;\">Total fără TVA:</span> <strong>{$totalFmt} RON</strong></div>
                                        <div><span style=\"color:#6b7280;\">Total cu TVA (21%):</span> <strong>{$totalVat} RON</strong></div>
                                        {$adjustText}
                                    </div>
                                ");
                            }),

                        Textarea::make('received_notes')
                            ->label('Observații recepție')
                            ->placeholder('Ex: Factură nr. xxx, lipsuri notate, produse deteriorate...')
                            ->default($this->record->received_notes)
                            ->rows(2),
                    ];
                })
                ->action(function (array $data): void {
                    $this->record->loadMissing('items');
                    $isPriceOnly = $this->record->status === PurchaseOrder::STATUS_RECEIVED;

                    $itemsById = $this->record->items->keyBy('id');

                    $transport     = (float) ($data['transport_cost'] ?? 0);
                    $discount      = (float) ($data['discount_value'] ?? 0);
                    $totalQty      = $isPriceOnly
                        ? $this->record->items->sum(fn ($item) => (float) $item->received_quantity)
                        : collect($data['items'])->sum(fn ($r) => (float) ($r['qty'] ?? 0));
                    $adjustPerUnit = $totalQty > 0 ? ($transport - $discount) / $totalQty : 0;

                    $affectedRequestIds = [];
                    $hasShortfall       = false;
                    $shortfallProducts  = [];

                    if ($isPriceOnly) {
                        // Mod preț-only: cantitățile sunt fixe din recepția cantitativă
                        foreach ($data['items'] as $row) {
                            $item = $itemsById->get((int) ($row['id'] ?? 0));
                            if (! $item) {
                                continue;
                            }
                            $price = (float) ($row['price'] ?? 0) + $adjustPerUnit;
                            $item->update(['unit_price' => $price]);
                        }
                    } else {
                        // Mod normal: actualizăm cantitățile și prețurile
                        foreach ($data['items'] as $row) {
                            $itemId    = (int) ($row['id'] ?? 0);
                            $orderItem = $itemId ? $itemsById->get($itemId) : null;

                            $receivedQty = (float) ($row['qty'] ?? 0);
                            $price       = (float) ($row['price'] ?? 0) + $adjustPerUnit;

                            if ($orderItem) {
                                $orderedQty = (float) $orderItem->quantity;
                                $shortfall  = max(0, $orderedQty - $receivedQty);

                                $orderItem->update([
                                    'received_quantity' => $receivedQty,
                                    'unit_price'        => $price,
                                ]);

                                if ($shortfall > 0) {
                                    $hasShortfall        = true;
                                    $shortfallProducts[] = $orderItem->product_name;
                                    $this->revertShortfallToRequestItems($orderItem, $shortfall, $affectedRequestIds);
                                }
                            } elseif ($receivedQty > 0 && (!empty($row['name']) || !empty($row['woo_product_id']))) {
                                $productName  = $row['name'] ?? '';
                                $sku          = null;
                                $wooProductId = $row['woo_product_id'] ?? null;

                                if ($wooProductId) {
                                    $product = \App\Models\WooProduct::find($wooProductId);
                                    if ($product) {
                                        $productName = $product->name;
                                        $sku         = $product->sku;
                                    }
                                }

                                $this->record->items()->create([
                                    'product_name'     => $productName,
                                    'sku'              => $sku,
                                    'woo_product_id'   => $wooProductId,
                                    'quantity'         => $receivedQty,
                                    'received_quantity' => $receivedQty,
                                    'unit_price'       => $price,
                                    'notes'            => 'Adăugat la recepție (suplimentar)',
                                ]);
                            }
                        }

                        foreach (array_unique($affectedRequestIds) as $requestId) {
                            \App\Models\PurchaseRequest::find($requestId)?->recalculateStatus();
                        }
                    }

                    // Date comune indiferent de mod
                    $updateData = [
                        'invoice_series'   => null,
                        'invoice_number'   => $data['invoice_number'] ?? null,
                        'invoice_date'     => $data['invoice_date'] ?? null,
                        'invoice_due_date' => $data['invoice_due_date'] ?? null,
                        'received_notes'   => $data['received_notes'] ?? null,
                    ];

                    if (! $isPriceOnly) {
                        $updateData['status']      = PurchaseOrder::STATUS_RECEIVED;
                        $updateData['received_at'] = now();
                        $updateData['received_by'] = auth()->id();
                    }

                    if (! $isPriceOnly) {
                        $updateData['winmentor_sync_status'] = PurchaseOrder::WINMENTOR_PENDING;
                        $updateData['winmentor_sync_error']  = null;
                    }

                    $this->record->update($updateData);
                    $this->record->recalculateTotals();

                    if (! $isPriceOnly) {
                        \App\Jobs\PushComenziFurnizoriToWinmentorJob::dispatch($this->record->id)->afterCommit();
                    }

                    // Actualizăm pivotul product-supplier cu prețul de achiziție
                    $this->record->refresh();
                    foreach ($this->record->items as $item) {
                        if ($item->woo_product_id && $this->record->supplier_id && $item->unit_price > 0) {
                            \App\Models\ProductSupplier::where('woo_product_id', $item->woo_product_id)
                                ->where('supplier_id', $this->record->supplier_id)
                                ->update([
                                    'last_purchase_date'  => $this->record->received_at?->toDateString() ?? now()->toDateString(),
                                    'last_purchase_price' => $item->unit_price,
                                ]);
                        }
                    }

                    if ($isPriceOnly) {
                        Notification::make()->success()->title('Prețuri de achiziție salvate.')->send();
                    } else {
                        $msg = $hasShortfall
                            ? 'Recepție înregistrată. Lipsurile au fost returnate în coada de cumpărare.'
                            : 'Recepție completă înregistrată.';
                        Notification::make()->success()->title($msg)->send();

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
                    $content  = \App\Services\PurchaseOrderPdf::get($this->record);
                    $filename = \App\Services\PurchaseOrderPdf::filename($this->record);

                    return response()->streamDownload(
                        fn () => print($content),
                        $filename,
                        ['Content-Type' => 'application/pdf'],
                    );
                }),

            // Descarcă CSV furnizor
            Actions\Action::make('download_csv')
                ->label('Descarcă CSV')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->action(function (): \Symfony\Component\HttpFoundation\StreamedResponse {
                    $this->record->loadMissing('items');

                    $filename = str_replace('/', '-', $this->record->number) . '.csv';

                    return response()->streamDownload(function () {
                        foreach ($this->record->items as $item) {
                            $code = $item->supplier_sku ?: $item->sku;
                            $qty  = rtrim(rtrim((string) $item->quantity, '0'), '.');
                            echo $code . ' ' . $qty . "\n";
                        }
                    }, $filename, ['Content-Type' => 'text/csv']);
                }),

            // Import CSV cantități
            Actions\Action::make('import_csv')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === PurchaseOrder::STATUS_DRAFT)
                ->form([
                    FileUpload::make('csv_file')
                        ->label('Fișier CSV (cod_furnizor,cantitate)')
                        ->disk('local')
                        ->directory('csv-imports-tmp')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/octet-stream'])
                        ->maxSize(512)
                        ->required()
                        ->helperText('Format: o linie per produs — cod_furnizor,cantitate'),
                ])
                ->action(function (array $data): void {
                    $path = is_array($data['csv_file']) ? reset($data['csv_file']) : $data['csv_file'];
                    $content = Storage::disk('local')->get($path);

                    if (! $content) {
                        Notification::make()->danger()->title('Fișierul nu a putut fi citit.')->send();
                        return;
                    }

                    $this->record->loadMissing('items');

                    // Indexăm itemele după supplier_sku și sku (fallback)
                    $itemsBySupplierSku = $this->record->items->keyBy(fn ($i) => strtolower(trim((string) $i->supplier_sku)));
                    $itemsBySku         = $this->record->items->keyBy(fn ($i) => strtolower(trim((string) $i->sku)));

                    $updated   = 0;
                    $notFound  = [];
                    $skipped   = 0;

                    $lines = preg_split('/\r\n|\r|\n/', trim($content));
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '') continue;

                        $cols = str_getcsv($line);
                        if (count($cols) < 2) { $skipped++; continue; }

                        $code = strtolower(trim($cols[0]));
                        $qty  = (float) str_replace(',', '.', trim($cols[1]));

                        if ($qty <= 0) { $skipped++; continue; }

                        $item = $itemsBySupplierSku[$code] ?? $itemsBySku[$code] ?? null;

                        if ($item) {
                            $item->update(['quantity' => $qty]);
                            $updated++;
                        } else {
                            $notFound[] = $cols[0];
                        }
                    }

                    $this->record->recalculateTotals();
                    $this->refreshFormData(['items', 'total_amount']);

                    if ($updated > 0 && empty($notFound)) {
                        Notification::make()->success()
                            ->title("CSV importat: {$updated} produse actualizate.")
                            ->send();
                    } elseif ($updated > 0) {
                        $missing = implode(', ', array_slice($notFound, 0, 5)) . (count($notFound) > 5 ? '...' : '');
                        Notification::make()->warning()
                            ->title("{$updated} produse actualizate, " . count($notFound) . ' negăsite.')
                            ->body("Coduri negăsite pe comandă: {$missing}")
                            ->send();
                    } else {
                        $missing = implode(', ', array_slice($notFound, 0, 10));
                        Notification::make()->danger()
                            ->title('Niciun produs nu a putut fi asociat.')
                            ->body("Coduri negăsite: {$missing}")
                            ->send();
                    }
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
