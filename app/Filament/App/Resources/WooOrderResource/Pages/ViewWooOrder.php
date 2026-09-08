<?php

namespace App\Filament\App\Resources\WooOrderResource\Pages;

use App\Filament\App\Resources\WooOrderResource;
use App\Models\ProductStock;
use App\Models\ProductSupplier;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\WooOrder;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use App\Services\WooCommerce\WooOrderSyncService;
use App\Filament\App\Pages\WinmentorVanzariDetailPage;
use App\Services\Winmentor\ImportWooOrderToWinmentorService;
use Filament\Actions\Action;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewWooOrder extends ViewRecord
{
    protected static string $resource = WooOrderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->syncOrderFromWoo();
    }

    /**
     * Factura WinMentor asociată comenzii — citită din coloanele SALVATE pe comandă
     * (populate de `winmentor:match-woo-facturi`, read-only, local). Instant, fără COM/LIKE.
     */
    public function winmentorInvoice(): ?object
    {
        if (! $this->record->winmentor_invoice_nr) {
            return null;
        }

        return (object) [
            'nr_factura' => $this->record->winmentor_invoice_nr,
            'serie'      => $this->record->winmentor_invoice_serie,
            'an'         => $this->record->winmentor_invoice_an,
            'luna'       => $this->record->winmentor_invoice_luna,
            'data'       => $this->record->winmentor_invoice_data,
            'total'      => $this->record->winmentor_invoice_total,
        ];
    }

    /** Diferența valorică absolută între total comandă și total factură WinMentor (cu TVA). */
    public function facturaDiferenta(): float
    {
        $inv = $this->winmentorInvoice();
        if (! $inv) {
            return 0.0;
        }
        return abs((float) $this->record->total - (float) $inv->total);
    }

    /** True dacă factura coincide valoric cu comanda (toleranță 0,05 RON la rotunjiri). */
    public function facturaValoricOk(): bool
    {
        return $this->facturaDiferenta() <= 0.05;
    }

    /** Statusuri în care conținutul comenzii mai poate fi editat. */
    private const EDITABLE_STATUSES = ['pending', 'processing', 'on-hold'];

    public function isOrderEditable(): bool
    {
        return in_array((string) $this->record->status, self::EDITABLE_STATUSES, true)
            && $this->record->woo_id;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('factura_winmentor')
                ->label(function (): string {
                    $inv = $this->winmentorInvoice();
                    if (! $inv) {
                        return 'Nefacturat în WinMentor';
                    }
                    $serie = $inv->serie ?: $inv->nr_factura;
                    return $this->facturaValoricOk()
                        ? 'Facturat: ' . $serie
                        : '⚠ Facturat: ' . $serie . ' — diferență ' . number_format($this->facturaDiferenta(), 2, ',', '.') . ' RON';
                })
                ->icon(function (): string {
                    $inv = $this->winmentorInvoice();
                    if (! $inv) {
                        return 'heroicon-o-document';
                    }
                    return $this->facturaValoricOk() ? 'heroicon-o-document-check' : 'heroicon-o-exclamation-triangle';
                })
                ->color(function (): string {
                    $inv = $this->winmentorInvoice();
                    if (! $inv) {
                        return 'gray';
                    }
                    return $this->facturaValoricOk() ? 'success' : 'danger';
                })
                ->disabled(fn (): bool => $this->winmentorInvoice() === null)
                ->url(function (): ?string {
                    $inv = $this->winmentorInvoice();
                    return $inv
                        ? WinmentorVanzariDetailPage::getUrl(['nr' => $inv->nr_factura, 'an' => $inv->an, 'luna' => $inv->luna])
                        : null;
                }),
            Action::make('import_winmentor')
                ->label(fn (): string => $this->record->winmentor_sync_status === 'synced'
                    ? 'Trimisă în WinMentor'
                    : 'Import în WinMentor')
                ->icon('heroicon-o-arrow-up-tray')
                ->color(fn (): string => $this->record->winmentor_sync_status === 'synced' ? 'gray' : 'success')
                ->disabled(fn (): bool => $this->record->winmentor_sync_status === 'synced')
                ->requiresConfirmation()
                ->modalHeading('Import comandă client în WinMentor')
                ->modalDescription('Se verifică întâi că toate produsele există ca articol în WinMentor (firma MAL2019). '
                    .'Dacă lipsește vreunul, importul se OPREȘTE și ți se arată produsele. '
                    .'Altfel: se verifică/creează clientul și se creează comanda client. Comanda se marchează ca trimisă (nu se dublează).')
                ->modalSubmitActionLabel('Importă în WinMentor')
                ->action(function (): void {
                    /** @var WooOrder $order */
                    $order = $this->record;

                    $result = (new ImportWooOrderToWinmentorService())->import($order);

                    if ($result['success'] ?? false) {
                        $client = $result['client'] ?? [];
                        $clientInfo = ($client['name'] ?? '—').(($client['created'] ?? false) ? ' (client nou creat)' : ' (client existent)');

                        Notification::make()
                            ->success()
                            ->title('Comandă importată în WinMentor')
                            ->body('Comanda '.$result['nrComanda'].' creată. Client: '.$clientInfo.'.')
                            ->persistent()
                            ->send();

                        $this->record = $order->fresh();
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Import oprit')
                            ->body($result['error'] ?? 'Eroare necunoscută.')
                            ->persistent()
                            ->send();

                        $this->record = $order->fresh();
                    }
                }),

            Action::make('create_awb')
                ->label(function (): string {
                    $awb = \App\Models\SamedayAwb::where('woo_order_id', $this->record->id)->latest('id')->first();
                    return $awb ? 'AWB: '.$awb->awb_number : 'Creează AWB';
                })
                ->icon('heroicon-o-truck')
                ->color(fn (): string => \App\Models\SamedayAwb::where('woo_order_id', $this->record->id)->exists() ? 'gray' : 'primary')
                ->url(function (): string {
                    $awb = \App\Models\SamedayAwb::where('woo_order_id', $this->record->id)->latest('id')->first();
                    return $awb
                        ? \App\Filament\App\Resources\SamedayAwbResource::getUrl('index', ['tableSearch' => $awb->awb_number])
                        : $this->buildCreateAwbUrl();
                }),

            Action::make('simulate_shipping')
                ->label('Simulează transport')
                ->icon('heroicon-o-calculator')
                ->color('info')
                ->action(function (): void {
                    $this->simulateShippingCost();
                }),

            Action::make('add_note')
                ->label('Adaugă notă')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('gray')
                ->form([
                    Textarea::make('note')
                        ->label('Notă')
                        ->required()
                        ->rows(3),
                    Toggle::make('customer_note')
                        ->label('Vizibil pentru client')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    /** @var WooOrder $order */
                    $order = $this->record;

                    try {
                        $client = new WooClient($order->connection);
                        $client->addOrderNote((int) $order->woo_id, $data['note'], (bool) ($data['customer_note'] ?? false));

                        Notification::make()->success()->title('Notă adăugată')->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Eroare')->body($e->getMessage())->send();
                    }
                }),

            Action::make('resync')
                ->label('Resync')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Resincronizare comandă')
                ->modalDescription('Datele comenzii vor fi actualizate din WooCommerce.')
                ->action(function (): void {
                    if ($this->syncOrderFromWoo()) {
                        Notification::make()->success()->title('Comandă resincronizată')->send();
                    }
                }),

            Action::make('create_purchase_request')
                ->label('Creare Necesar')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Creare Necesar din comandă')
                ->modalDescription(function (): string {
                    $items = $this->getDeficitItems();
                    if (empty($items)) {
                        return 'Toate produsele din comandă au stoc suficient. Nu este necesar un referat.';
                    }
                    $lines = ['Vor fi adăugate în Necesar (doar cantitățile lipsă):'];
                    $lines[] = '';
                    foreach ($items as $item) {
                        $supplier = $item['supplier_name'] ? ' — '.$item['supplier_name'] : ' — fără furnizor';
                        $lines[] = '• '.$item['product_name'].' | Comandat: '.$item['ordered'].' | Stoc: '.$item['stock'].' | Lipsă: '.$item['deficit'].$supplier;
                    }
                    return implode("\n", $lines);
                })
                ->modalSubmitActionLabel('Creează Necesar')
                ->hidden(fn (): bool => empty($this->getDeficitItems()))
                ->action(function (): void {
                    $items = $this->getDeficitItems();
                    if (empty($items)) {
                        Notification::make()->warning()->title('Stoc suficient')->body('Nu există produse cu deficit.')->send();
                        return;
                    }

                    /** @var WooOrder $order */
                    $order = $this->record;

                    $pr = PurchaseRequest::create([
                        'location_id' => $order->location_id,
                        'source_type' => PurchaseRequest::SOURCE_WOO_ORDER,
                        'woo_order_id' => $order->id,
                        'notes'       => 'Generat automat din comanda #'.$order->number,
                        'status'      => PurchaseRequest::STATUS_SUBMITTED,
                    ]);

                    foreach ($items as $item) {
                        PurchaseRequestItem::create([
                            'purchase_request_id' => $pr->id,
                            'woo_product_id'      => $item['woo_product_id'],
                            'supplier_id'         => $item['supplier_id'],
                            'product_name'        => $item['product_name'],
                            'sku'                 => $item['sku'],
                            'quantity'            => $item['deficit'],
                            'status'              => PurchaseRequestItem::STATUS_PENDING,
                        ]);
                    }

                    Notification::make()
                        ->success()
                        ->title('Necesar creat: '.$pr->number)
                        ->body(count($items).' produs(e) cu deficit adăugate.')
                        ->send();
                }),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function buildEditableItems(): array
    {
        return $this->record->items->map(function ($item) {
            // cota TVA reală a itemului, dedusă din valorile Woo (net + tax)
            $rate = 21;
            if ((float) $item->subtotal > 0 && (float) $item->tax >= 0) {
                $computed = (int) round(((float) $item->tax / (float) $item->subtotal) * 100);
                if (in_array($computed, [0, 5, 9, 19, 21], true)) {
                    $rate = $computed;
                }
            }

            return [
                'woo_item_id' => $item->woo_item_id,
                'vat_rate'    => $rate,
                'name'        => $item->name.' ('.($item->sku ?: 'fără SKU').')',
                'quantity'    => (int) $item->quantity,
                'price_gross' => round((float) $item->price * (1 + $rate / 100), 2),
            ];
        })->values()->all();
    }

    /** Snapshot complet al unui item — suficient pentru re-adăugare la undo */
    private function itemSnapshot(\App\Models\WooOrderItem $item): array
    {
        return [
            'woo_item_id'    => (int) $item->woo_item_id,
            'woo_product_id' => (int) ($item->data['product_id'] ?? $item->woo_product_id),
            'name'           => $item->name,
            'sku'            => $item->sku,
            'quantity'       => (float) $item->quantity,
            'price'          => (float) $item->price,     // net, per bucată
            'subtotal'       => (float) $item->subtotal,
            'total'          => (float) $item->total,
        ];
    }

    private function logEdit(string $action, string $label, ?array $before, ?array $after): void
    {
        \App\Models\WooOrderEdit::create([
            'woo_order_id' => $this->record->id,
            'user_email'   => auth()->user()?->email,
            'action'       => $action,
            'label'        => $label,
            'before'       => $before,
            'after'        => $after,
        ]);
    }

    public function deleteOrderProduct(int $wooItemId): void
    {
        /** @var WooOrder $order */
        $order = $this->record;
        $item  = $order->items->firstWhere('woo_item_id', $wooItemId);

        if (! $this->isOrderEditable() || ! $item) {
            Notification::make()->danger()->title('Produsul nu poate fi șters')->send();
            return;
        }

        try {
            $client = new WooClient($order->connection);
            $client->updateOrder((int) $order->woo_id, ['line_items' => [['id' => $wooItemId, 'quantity' => 0]]]);

            $this->logEdit('remove_item', 'Șters: '.$item->name.' × '.(float) $item->quantity, $this->itemSnapshot($item), null);
            $this->syncOrderFromWoo();

            Notification::make()->success()->title('Produs șters din comandă')
                ->body('Poți reveni oricând din secțiunea „Istoric modificări".')->send();
            $this->redirect(WooOrderResource::getUrl('view', ['record' => $order]));
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Eroare la ștergere')->body($e->getMessage())->persistent()->send();
        }
    }

    public function addOrderProduct(array $data): void
    {
        /** @var WooOrder $order */
        $order   = $this->record;
        $product = \App\Models\WooProduct::find($data['product_id'] ?? null);

        if (! $this->isOrderEditable() || ! $product?->woo_id) {
            Notification::make()->danger()->title('Produsul nu poate fi adăugat')->send();
            return;
        }

        $qty  = max(1, (int) $data['quantity']);
        $line = ['product_id' => (int) $product->woo_id, 'quantity' => $qty];

        if (filled($data['price_gross'] ?? null)) {
            $net  = round((float) $data['price_gross'] / 1.21, 4);
            $tot  = number_format(round($net * $qty, 2), 2, '.', '');
            $line['subtotal'] = $tot;
            $line['total']    = $tot;
        }

        try {
            $existingIds = $order->items->pluck('woo_item_id')->map(fn ($v) => (int) $v)->all();

            $client = new WooClient($order->connection);
            $resp   = $client->updateOrder((int) $order->woo_id, ['line_items' => [$line]]);

            // Identificăm linia nou-creată din răspuns (pentru undo)
            $newLine = collect($resp['line_items'] ?? [])
                ->first(fn ($l) => ! in_array((int) $l['id'], $existingIds, true)
                    && (int) $l['product_id'] === (int) $product->woo_id);

            $this->logEdit('add_item', 'Adăugat: '.$product->decoded_name.' × '.$qty, null, [
                'woo_item_id'    => (int) ($newLine['id'] ?? 0),
                'woo_product_id' => (int) $product->woo_id,
                'name'           => $product->decoded_name,
                'quantity'       => $qty,
            ]);

            $this->syncOrderFromWoo();

            Notification::make()->success()->title('Produs adăugat în comandă')->send();
            $this->redirect(WooOrderResource::getUrl('view', ['record' => $order]));
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Eroare la adăugare')->body($e->getMessage())->persistent()->send();
        }
    }

    /**
     * Undo pentru o modificare din istoric (apelat din blade-ul de istoric).
     */
    public function revertEdit(int $editId): void
    {
        /** @var WooOrder $order */
        $order = $this->record;
        $edit  = \App\Models\WooOrderEdit::where('woo_order_id', $order->id)->find($editId);

        if (! $edit?->isRevertible() || ! $this->isOrderEditable()) {
            Notification::make()->danger()->title('Modificarea nu mai poate fi anulată')->send();
            return;
        }

        try {
            $client  = new WooClient($order->connection);
            $payload = null;

            switch ($edit->action) {
                case 'remove_item':
                    $s = $edit->before;
                    $line = ['product_id' => (int) $s['woo_product_id'], 'quantity' => (int) $s['quantity']];
                    if (isset($s['subtotal'])) {
                        $line['subtotal'] = number_format((float) $s['subtotal'], 2, '.', '');
                        $line['total']    = number_format((float) $s['total'], 2, '.', '');
                    }
                    $payload = ['line_items' => [$line]];
                    break;

                case 'add_item':
                    if (empty($edit->after['woo_item_id'])) {
                        throw new \RuntimeException('Linia adăugată nu a putut fi identificată pentru anulare.');
                    }
                    $payload = ['line_items' => [['id' => (int) $edit->after['woo_item_id'], 'quantity' => 0]]];
                    break;

                case 'edit_items':
                    $payload = ['line_items' => array_map(fn ($l) => [
                        'id'       => (int) $l['woo_item_id'],
                        'quantity' => (int) $l['quantity'],
                        'subtotal' => number_format((float) $l['subtotal'], 2, '.', ''),
                        'total'    => number_format((float) $l['total'], 2, '.', ''),
                    ], $edit->before['lines'] ?? [])];
                    break;

                case 'edit_address':
                    $payload = $edit->before;
                    break;
            }

            if (empty($payload)) {
                throw new \RuntimeException('Nimic de restaurat.');
            }

            $client->updateOrder((int) $order->woo_id, $payload);

            $edit->update(['reverted_at' => now(), 'reverted_by' => auth()->user()?->email]);
            $this->syncOrderFromWoo();

            Notification::make()->success()->title('Modificare anulată')->body($edit->label.' — revenit la starea anterioară.')->send();
            $this->redirect(WooOrderResource::getUrl('view', ['record' => $order]));
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Eroare la anulare')->body($e->getMessage())->persistent()->send();
        }
    }

    public function buildBillingForm(): array
    {
        $b = (array) ($this->record->billing ?? []);

        return [
            \Filament\Schemas\Components\Section::make('Date de facturare')
                ->columns(12)
                ->schema([
                    \Filament\Forms\Components\TextInput::make('b_first_name')->label('Prenume')->default($b['first_name'] ?? '')->columnSpan(3),
                    \Filament\Forms\Components\TextInput::make('b_last_name')->label('Nume')->default($b['last_name'] ?? '')->columnSpan(3),
                    \Filament\Forms\Components\TextInput::make('b_company')->label('Companie')->default($b['company'] ?? '')->columnSpan(6),
                    \Filament\Forms\Components\TextInput::make('b_address_1')->label('Adresă')->default($b['address_1'] ?? '')->columnSpan(8),
                    \Filament\Forms\Components\TextInput::make('b_address_2')->label('Detalii (bl/sc/ap)')->default($b['address_2'] ?? '')->columnSpan(4),
                    \Filament\Forms\Components\TextInput::make('b_city')->label('Localitate')->default($b['city'] ?? '')->columnSpan(4),
                    \Filament\Forms\Components\TextInput::make('b_state')->label('Județ (cod)')->default($b['state'] ?? '')->helperText('ex: BH, CJ, B')->columnSpan(3),
                    \Filament\Forms\Components\TextInput::make('b_postcode')->label('Cod poștal')->default($b['postcode'] ?? '')->columnSpan(3),
                    \Filament\Forms\Components\TextInput::make('b_phone')->label('Telefon')->default($b['phone'] ?? '')->columnSpan(2),
                    \Filament\Forms\Components\TextInput::make('b_email')->label('Email')->email()->default($b['email'] ?? '')->columnSpan(6),
                ]),
        ];
    }

    public function buildShippingForm(): array
    {
        $shipping = (array) ($this->record->shipping ?? []);
        $shipLine = collect($this->record->data['shipping_lines'] ?? [])->first();

        $grossShipping = round((float) $this->record->shipping_total * 1.21, 2);

        return [
            \Filament\Schemas\Components\Section::make('Adresa de livrare')
                ->columns(12)
                ->schema([
                    \Filament\Forms\Components\TextInput::make('s_first_name')->label('Prenume')->default($shipping['first_name'] ?? '')->columnSpan(3),
                    \Filament\Forms\Components\TextInput::make('s_last_name')->label('Nume')->default($shipping['last_name'] ?? '')->columnSpan(3),
                    \Filament\Forms\Components\TextInput::make('s_company')->label('Companie')->default($shipping['company'] ?? '')->columnSpan(6),
                    \Filament\Forms\Components\TextInput::make('s_address_1')->label('Adresă')->default($shipping['address_1'] ?? '')->required()->columnSpan(8),
                    \Filament\Forms\Components\TextInput::make('s_address_2')->label('Detalii (bl/sc/ap)')->default($shipping['address_2'] ?? '')->columnSpan(4),
                    \Filament\Forms\Components\TextInput::make('s_city')->label('Localitate')->default($shipping['city'] ?? '')->required()->columnSpan(4),
                    \Filament\Forms\Components\TextInput::make('s_state')->label('Județ (cod)')->default($shipping['state'] ?? '')->helperText('ex: BH, CJ, B')->columnSpan(3),
                    \Filament\Forms\Components\TextInput::make('s_postcode')->label('Cod poștal')->default($shipping['postcode'] ?? '')->columnSpan(3),
                    \Filament\Forms\Components\TextInput::make('s_phone')->label('Telefon livrare')->default($shipping['phone'] ?? '')->columnSpan(2),
                ]),
            \Filament\Schemas\Components\Section::make('Transport & Easybox')
                ->columns(12)
                ->schema([
                    \Filament\Forms\Components\TextInput::make('ship_method')
                        ->label('Metodă transport')
                        ->default($shipLine['method_title'] ?? '')
                        ->columnSpan(6),
                    \Filament\Forms\Components\TextInput::make('ship_cost_gross')
                        ->label('Cost transport (cu TVA)')
                        ->numeric()->minValue(0)->step('0.01')->suffix('RON')
                        ->default($grossShipping)
                        ->helperText($shipLine ? 'Recalculează totalul comenzii.' : 'Comanda nu are linie de transport.')
                        ->disabled(! $shipLine)
                        ->columnSpan(6),
                    Select::make('locker_id')
                        ->label(fn (): string => ($l = $this->currentLocker()) ? 'Căsuță Easybox (curentă: '.($l['name'] ?? $l['lockerId'] ?? '?').')' : 'Căsuță Easybox (opțional — livrare în locker)')
                        ->searchable()
                        ->default(fn () => $this->currentLocker()['lockerId'] ?? null)
                        ->getSearchResultsUsing(fn (string $search) => \Illuminate\Support\Facades\DB::table('sameday_lockers')
                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                ->orWhere('city', 'like', "%{$search}%")
                                ->orWhere('address', 'like', "%{$search}%"))
                            ->limit(30)->get()
                            ->mapWithKeys(fn ($l) => [$l->locker_id => $l->name.' — '.$l->address.', '.$l->city.' ('.$l->county.')'])
                            ->all())
                        ->getOptionLabelUsing(function ($value) {
                            $l = \Illuminate\Support\Facades\DB::table('sameday_lockers')->where('locker_id', $value)->first();
                            return $l
                                ? $l->name.' — '.$l->address.', '.$l->city
                                : 'Easybox #'.$value.' (ales de client — valid, dar lipsește din nomenclatorul local)';
                        })
                        ->helperText('Alege căsuța de pe hartă sau caut-o. Golește câmpul pentru livrare la adresă (fără Easybox).')
                        ->columnSpan(12),
                    \Filament\Forms\Components\ViewField::make('locker_map_shipping')
                        ->label('')
                        ->view('filament.app.sameday-locker-map', ['lockerField' => 'locker_id'])
                        ->dehydrated(false)
                        ->columnSpan(12),
                ]),
            \Filament\Schemas\Components\Section::make('Notă client')
                ->schema([
                    \Filament\Forms\Components\Textarea::make('customer_note')->label('')->rows(2)
                        ->default((string) $this->record->customer_note),
                ]),
        ];
    }

    public function saveBilling(array $data): void
    {
        /** @var WooOrder $order */
        $order = $this->record;

        if (! $this->isOrderEditable()) {
            Notification::make()->danger()->title('Comanda nu mai poate fi editată')->send();
            return;
        }

        $billing    = (array) ($order->billing ?? []);
        $newBilling = array_merge($billing, [
            'first_name' => trim($data['b_first_name'] ?? ''),
            'last_name'  => trim($data['b_last_name'] ?? ''),
            'company'    => trim($data['b_company'] ?? ''),
            'address_1'  => trim($data['b_address_1'] ?? ''),
            'address_2'  => trim($data['b_address_2'] ?? ''),
            'city'       => trim($data['b_city'] ?? ''),
            'state'      => strtoupper(trim($data['b_state'] ?? '')),
            'postcode'   => trim($data['b_postcode'] ?? ''),
            'phone'      => trim($data['b_phone'] ?? ''),
            'email'      => trim($data['b_email'] ?? ''),
        ]);

        if ($newBilling == $billing) {
            Notification::make()->info()->title('Nicio modificare')->send();
            return;
        }

        $this->pushOrderPayload(['billing' => $newBilling], ['billing' => $billing], 'Editat: date facturare');
    }

    public function saveShipping(array $data): void
    {
        /** @var WooOrder $order */
        $order = $this->record;

        if (! $this->isOrderEditable()) {
            Notification::make()->danger()->title('Comanda nu mai poate fi editată')->send();
            return;
        }

        $shipping    = (array) ($order->shipping ?? []);
        $newShipping = array_merge($shipping, [
            'first_name' => trim($data['s_first_name'] ?? ''),
            'last_name'  => trim($data['s_last_name'] ?? ''),
            'company'    => trim($data['s_company'] ?? ''),
            'address_1'  => trim($data['s_address_1'] ?? ''),
            'address_2'  => trim($data['s_address_2'] ?? ''),
            'city'       => trim($data['s_city'] ?? ''),
            'state'      => strtoupper(trim($data['s_state'] ?? '')),
            'postcode'   => trim($data['s_postcode'] ?? ''),
            'phone'      => trim($data['s_phone'] ?? ''),
        ]);

        $payload = [];
        $before  = [];

        if ($newShipping != $shipping) {
            $payload['shipping'] = $newShipping;
            $before['shipping']  = $shipping;
        }

        if (trim($data['customer_note'] ?? '') !== (string) $order->customer_note) {
            $payload['customer_note'] = trim($data['customer_note'] ?? '');
            $before['customer_note']  = (string) $order->customer_note;
        }

        // Transport (metodă/cost) + căsuță Easybox — aceleași câmpuri ca în modalul Transport
        if ($this->applyTransportChanges($data, $payload, $before) === false) {
            return; // căsuță invalidă — mesajul a fost deja afișat
        }

        if (empty($payload)) {
            Notification::make()->info()->title('Nicio modificare')->send();
            return;
        }

        $this->pushOrderPayload($payload, $before, 'Editat: livrare/transport');
    }

    /**
     * Populează $payload/$before cu modificările de transport (metodă, cost) și căsuță
     * Easybox din $data. Returnează false dacă o căsuță selectată e invalidă (blochează salvarea).
     * Sursă unică folosită de saveShipping() și saveTransport().
     */
    private function applyTransportChanges(array $data, array &$payload, array &$before): bool
    {
        /** @var WooOrder $order */
        $order = $this->record;

        $shipLine = collect($order->data['shipping_lines'] ?? [])->first();
        if ($shipLine && array_key_exists('ship_cost_gross', $data)) {
            $newGross = round((float) ($data['ship_cost_gross'] ?? 0), 2);
            $oldGross = round((float) $order->shipping_total * 1.21, 2);
            $newTitle = trim($data['ship_method'] ?? '');

            if (abs($newGross - $oldGross) >= 0.01 || ($newTitle !== '' && $newTitle !== ($shipLine['method_title'] ?? ''))) {
                $line = ['id' => $shipLine['id'], 'total' => number_format(round($newGross / 1.21, 2), 2, '.', '')];
                if ($newTitle !== '') {
                    $line['method_title'] = $newTitle;
                }
                $payload['shipping_lines'] = [$line];
                $before['shipping_lines']  = [[
                    'id'           => $shipLine['id'],
                    'total'        => number_format((float) $order->shipping_total, 2, '.', ''),
                    'method_title' => $shipLine['method_title'] ?? '',
                ]];
            }
        }

        if (! array_key_exists('locker_id', $data)) {
            return true;
        }

        $locker    = $this->currentLocker();
        $currentId = (string) ($locker['lockerId'] ?? '');
        $newId     = (string) ($data['locker_id'] ?? '');

        if ($newId !== $currentId) {
            if ($newId === '') {
                $newMeta = '';
            } else {
                $l = \Illuminate\Support\Facades\DB::table('sameday_lockers')->where('locker_id', $newId)->first();
                if (! $l) {
                    Notification::make()->danger()->title('Căsuța selectată nu există')->send();
                    return false;
                }
                $newMeta = json_encode([
                    'lockerId' => (string) $l->locker_id,
                    'oohType'  => $locker['oohType'] ?? '0',
                    'name'     => $l->name,
                    'address'  => $l->address,
                    'city'     => $l->city,
                    'county'   => $l->county,
                ] + array_diff_key($locker ?? [], array_flip(['lockerId', 'name', 'address', 'city', 'county'])), JSON_UNESCAPED_UNICODE);
            }

            $payload['meta_data'] = [['key' => '_sameday_shipping_locker_id', 'value' => $newMeta]];
            $before['meta_data']  = [['key' => '_sameday_shipping_locker_id', 'value' => $locker ? json_encode($locker, JSON_UNESCAPED_UNICODE) : '']];
        }

        return true;
    }

    /** Trimite payload-ul în WooCommerce, loghează cu snapshot pentru undo și resincronizează. */
    private function pushOrderPayload(array $payload, array $before, string $label): void
    {
        /** @var WooOrder $order */
        $order = $this->record;

        try {
            $client = new WooClient($order->connection);
            $client->updateOrder((int) $order->woo_id, $payload);

            $this->logEdit('edit_address', $label, $before, $payload);
            $this->syncOrderFromWoo();

            $body = 'Salvat în WooCommerce: '.implode(', ', array_keys($payload)).'.';
            if ($order->winmentor_sync_status === 'synced') {
                $body .= ' ATENȚIE: comanda a fost deja trimisă în WinMentor — verifică dacă modificarea contează și acolo!';
            }

            \Log::info('WooOrder editată din ERP', ['order' => $order->number, 'user' => auth()->user()?->email, 'campuri' => array_keys($payload)]);
            Notification::make()->success()->title('Comandă actualizată în WooCommerce')->body($body)->send();

            $this->redirect(WooOrderResource::getUrl('view', ['record' => $order]));
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Eroare la actualizare')->body($e->getMessage())->persistent()->send();
        }
    }

    public function saveOrderItems(array $data): void
    {
        /** @var WooOrder $order */
        $order = $this->record;

        if (! $this->isOrderEditable()) {
            Notification::make()->danger()->title('Comanda nu mai poate fi editată')->send();
            return;
        }

        $original = $order->items->keyBy('woo_item_id');
        $lines    = [];
        $beforeLines = [];
        $afterLines  = [];
        $removedSnapshots = [];

        foreach ($data['items'] ?? [] as $row) {
            $itemId = (int) ($row['woo_item_id'] ?? 0);
            $orig   = $original->get($itemId);
            if (! $orig) {
                continue;
            }

            $qty   = max(1, (int) $row['quantity']);
            $rate  = (float) ($row['vat_rate'] ?? 21);
            $net   = round((float) $row['price_gross'] / (1 + $rate / 100), 4);
            $line  = number_format(round($net * $qty, 2), 2, '.', '');

            $origGross = round((float) $orig->price * (1 + $rate / 100), 2);
            $changed   = $qty !== (int) $orig->quantity
                || abs((float) $row['price_gross'] - $origGross) >= 0.01;

            if ($changed) {
                $lines[] = ['id' => $itemId, 'quantity' => $qty, 'subtotal' => $line, 'total' => $line];
                $beforeLines[] = ['woo_item_id' => $itemId, 'name' => $orig->name, 'quantity' => (int) $orig->quantity, 'subtotal' => (float) $orig->subtotal, 'total' => (float) $orig->total];
                $afterLines[]  = ['woo_item_id' => $itemId, 'name' => $orig->name, 'quantity' => $qty, 'subtotal' => (float) $line, 'total' => (float) $line];
            }

            $original->forget($itemId);
        }

        // rândurile șterse din repeater = eliminate din comandă (Woo: quantity 0)
        foreach ($original as $removed) {
            $lines[] = ['id' => (int) $removed->woo_item_id, 'quantity' => 0];
            $removedSnapshots[] = $this->itemSnapshot($removed);
        }

        if (empty($lines)) {
            Notification::make()->info()->title('Nicio modificare')->body('Cantitățile și prețurile sunt neschimbate.')->send();
            return;
        }

        try {
            $client = new WooClient($order->connection);
            $client->updateOrder((int) $order->woo_id, ['line_items' => $lines]);

            if (! empty($beforeLines)) {
                $this->logEdit('edit_items', 'Editat: '.count($beforeLines).' produs(e)', ['lines' => $beforeLines], ['lines' => $afterLines]);
            }
            foreach ($removedSnapshots as $snap) {
                $this->logEdit('remove_item', 'Șters: '.$snap['name'].' × '.$snap['quantity'], $snap, null);
            }

            $this->syncOrderFromWoo();

            $body = count($lines).' modificare(ări) trimise. Totalurile au fost recalculate de WooCommerce.';
            if ($order->winmentor_sync_status === 'synced') {
                $body .= ' ATENȚIE: comanda a fost deja trimisă în WinMentor — corectează manual și acolo!';
            }

            \Log::info('WooOrder editată din ERP', [
                'order' => $order->number, 'user' => auth()->user()?->email, 'lines' => $lines,
            ]);

            Notification::make()->success()->title('Comandă actualizată în WooCommerce')->body($body)->persistent()->send();

            $this->redirect(WooOrderResource::getUrl('view', ['record' => $order]));
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Eroare la actualizare')->body($e->getMessage())->persistent()->send();
        }
    }

    private function syncOrderFromWoo(): bool
    {
        /** @var WooOrder $order */
        $order = $this->record;

        try {
            $client = new WooClient($order->connection);
            $raw    = $client->getOrder((int) $order->woo_id);

            if (empty($raw)) {
                Notification::make()->warning()->title('Comandă negăsită în WooCommerce')->send();

                return false;
            }

            (new WooOrderSyncService())->upsertOrder($order->connection_id, $order->location_id, $raw);

            $this->record = $order->fresh();

            return true;
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Eroare resync')->body($e->getMessage())->send();

            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function getDeficitItems(): array
    {
        /** @var WooOrder $order */
        $order      = $this->record;
        $locationId = (int) $order->location_id;
        $result     = [];

        foreach ($order->items as $item) {
            if (! $item->woo_product_id) {
                continue;
            }

            $localProduct = WooProduct::where('woo_id', $item->woo_product_id)->first(['id', 'sku']);
            if (! $localProduct) {
                continue;
            }

            $stock = (float) ProductStock::where('woo_product_id', $localProduct->id)
                ->when($locationId > 0, fn ($q) => $q->where('location_id', $locationId))
                ->value('quantity') ?? 0.0;

            $deficit = max(0, (float) $item->quantity - $stock);
            if ($deficit <= 0) {
                continue;
            }

            $preferred = ProductSupplier::where('woo_product_id', $localProduct->id)
                ->where('is_preferred', true)
                ->first(['id', 'supplier_id']);

            if (! $preferred) {
                $preferred = ProductSupplier::where('woo_product_id', $localProduct->id)
                    ->first(['id', 'supplier_id']);
            }

            $supplierName = null;
            if ($preferred) {
                $supplierName = \App\Models\Supplier::where('id', $preferred->supplier_id)->value('name');
            }

            $result[] = [
                'woo_product_id' => $localProduct->id,
                'supplier_id'    => $preferred?->supplier_id,
                'supplier_name'  => $supplierName,
                'product_name'   => $item->name,
                'sku'            => $item->sku ?? $localProduct->sku,
                'ordered'        => (float) $item->quantity,
                'stock'          => $stock,
                'deficit'        => $deficit,
            ];
        }

        return $result;
    }

    /** Cere estimarea de cost Sameday pe datele comenzii și o compară cu transportul încasat. */
    public function simulateShippingCost(): void
    {
        /** @var WooOrder $order */
        $order      = $this->record;
        $locationId = (int) ($order->location_id ?: auth()->user()?->location_id ?? 0);

        $connection = \App\Filament\App\Resources\SamedayAwbResource::resolveSamedayConnectionForLocation($locationId);
        if (! $connection) {
            Notification::make()->warning()->title('Simulare nereușită')
                ->body('Locația comenzii nu are conexiune Sameday activă.')->send();

            return;
        }

        $countyText = (string) data_get($order->shipping, 'state', data_get($order->billing, 'state', ''));
        $cityText   = (string) data_get($order->shipping, 'city', data_get($order->billing, 'city', ''));

        $countyId = \App\Filament\App\Resources\SamedayAwbResource::resolveCountyIdFromText($locationId, $countyText);
        $cityId   = $countyId
            ? \App\Filament\App\Resources\SamedayAwbResource::resolveCityIdFromText($locationId, $countyId, $cityText)
            : null;

        if (! $countyId || ! $cityId) {
            Notification::make()->warning()->title('Simulare nereușită')
                ->body("Nu am putut mapa adresa pe nomenclatorul Sameday (județ: {$countyText}, oraș: {$cityText}).")->send();

            return;
        }

        // Greutatea comenzii din greutățile produselor (Woo data->weight, kg)
        $weight = 0.0;
        $missingWeight = [];
        foreach ($order->items as $item) {
            $productWeight = (float) WooProduct::where('woo_id', $item->woo_product_id)->value('data->weight');
            if ($productWeight <= 0) {
                $missingWeight[] = $item->name;
            }
            $weight += $productWeight * (float) $item->quantity;
        }
        if ($weight <= 0) {
            $weight = \App\Filament\App\Resources\SamedayAwbResource::defaultPackageWeightForLocation($locationId);
        }

        $isLocker  = $this->currentLocker() !== null;
        $serviceId = $isLocker ? 15 : 7; // Locker NextDay / 24H

        try {
            $estimate = app(\App\Services\Courier\SamedayAwbService::class)->estimateAwbCost($connection, [
                'service_id'            => $serviceId,
                'recipient_name'        => (string) $order->customer_name,
                'recipient_phone'       => (string) ($order->customer_phone ?: '0700000000'),
                'recipient_county_id'   => $countyId,
                'recipient_city_id'     => $cityId,
                'recipient_address'     => (string) data_get($order->shipping, 'address_1', data_get($order->billing, 'address_1', '-')),
                'recipient_postal_code' => (string) data_get($order->shipping, 'postcode', data_get($order->billing, 'postcode', '')),
                'cod_amount'            => $order->payment_method === 'cod' ? (float) $order->total : 0,
                'parcels'               => [['weight_kg' => round(max(0.1, $weight), 2)]],
            ]);
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Simulare nereușită')->body($e->getMessage())->send();

            return;
        }

        $cost    = (float) ($estimate['cost'] ?? 0);
        $charged = (float) $order->shipping_total;
        $diff    = $charged - $cost;

        $lines = [
            sprintf('Cost real Sameday: <strong>%s RON</strong> (%s, %s kg%s)',
                number_format($cost, 2, ',', '.'),
                $isLocker ? 'Easybox' : 'livrare la domiciliu',
                number_format($weight, 2, ',', '.'),
                $order->payment_method === 'cod' ? ', cu ramburs' : ''),
            sprintf('Transport încasat la checkout: <strong>%s RON</strong>', number_format($charged, 2, ',', '.')),
            sprintf('Diferență: <strong>%s%s RON</strong> %s',
                $diff >= 0 ? '+' : '−',
                number_format(abs($diff), 2, ',', '.'),
                $diff >= 0 ? '(acoperit)' : '(transport sub cost!)'),
        ];
        if (! empty($missingWeight)) {
            $lines[] = 'Fără greutate setată: '.implode(', ', array_slice($missingWeight, 0, 3))
                .(count($missingWeight) > 3 ? ' +'.(count($missingWeight) - 3) : '');
        }

        Notification::make()
            ->{$diff >= 0 ? 'success' : 'warning'}()
            ->title('Simulare cost transport')
            ->body(implode("<br>", $lines))
            ->persistent()
            ->send();
    }

    public function buildCreateAwbUrl(): string
    {
        /** @var WooOrder $order */
        $order = $this->record;

        $params = array_filter([
            'woo_order_id'          => $order->id,
            'recipient_name'        => $order->customer_name,
            'recipient_phone'       => $order->customer_phone,
            'recipient_email'       => $order->customer_email,
            'recipient_address'     => (string) data_get($order->shipping, 'address_1', data_get($order->billing, 'address_1', '')),
            'recipient_city'        => (string) data_get($order->shipping, 'city', data_get($order->billing, 'city', '')),
            'recipient_county'      => (string) data_get($order->shipping, 'state', data_get($order->billing, 'state', '')),
            'recipient_postal_code' => (string) data_get($order->shipping, 'postcode', data_get($order->billing, 'postcode', '')),
            'cod_amount'            => $order->payment_method === 'cod' ? (string) $order->total : null,
            'reference'             => $order->number,
            // Comandă Easybox → precompletăm căsuța (formularul AWB o preia automat)
            'locker_last_mile'      => $this->currentLocker()['lockerId'] ?? null,
        ]);

        return route('filament.app.resources.sameday-awbs.create').'?'.http_build_query($params);
    }

    /* ─── Acțiuni montabile din carduri (creioane, stil WooCommerce) ─────────── */

    public function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Schimbă status')
            ->modalHeading(fn (): string => 'Schimbă status — comanda #'.$this->record->number)
            ->form([
                Select::make('status')
                    ->label('Status nou')
                    ->options(WooOrder::STATUS_LABELS)
                    ->default(fn (): string => (string) $this->record->status)
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                /** @var WooOrder $order */
                $order = $this->record;

                try {
                    $client = new WooClient($order->connection);
                    $client->updateOrderStatus((int) $order->woo_id, $data['status']);

                    $order->update(['status' => $data['status']]);

                    Notification::make()->success()->title('Status actualizat')->send();
                    $this->redirect(WooOrderResource::getUrl('view', ['record' => $order]));
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Eroare')->body($e->getMessage())->send();
                }
            });
    }

    public function editBillingAction(): Action
    {
        return Action::make('editBilling')
            ->label('Editează facturarea')
            ->modalHeading(fn (): string => 'Date facturare — comanda #'.$this->record->number)
            ->modalDescription('Modificările se salvează direct în WooCommerce și se resincronizează în ERP.')
            ->modalSubmitActionLabel('Salvează în WooCommerce')
            ->modalWidth('3xl')
            ->form(fn (): array => $this->buildBillingForm())
            ->action(fn (array $data) => $this->saveBilling($data));
    }

    public function editShippingAction(): Action
    {
        return Action::make('editShipping')
            ->label('Editează livrarea')
            ->modalHeading(fn (): string => 'Livrare & transport — comanda #'.$this->record->number)
            ->modalDescription('Modificările se salvează direct în WooCommerce; costul de transport modificat recalculează totalul comenzii.')
            ->modalSubmitActionLabel('Salvează în WooCommerce')
            ->modalWidth('3xl')
            ->form(fn (): array => $this->buildShippingForm())
            ->action(fn (array $data) => $this->saveShipping($data));
    }

    public function addProductAction(): Action
    {
        return Action::make('addProduct')
            ->label('Adaugă produs')
            ->modalHeading(fn (): string => 'Adaugă produs — comanda #'.$this->record->number)
            ->modalDescription('Produsul se adaugă în comandă în WooCommerce, cu recalcularea totalurilor. Lasă prețul gol pentru prețul curent de pe site.')
            ->modalSubmitActionLabel('Adaugă în comandă')
            ->form([
                Select::make('product_id')
                    ->label('Produs')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => WooProduct::query()
                        ->whereNotNull('woo_id')->where('is_placeholder', false)
                        ->where('status', 'publish')
                        ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn ($p) => [$p->id => $p->decoded_name.' — '.$p->sku.' ('.number_format((float) $p->regular_price, 2).' lei)'])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => WooProduct::find($value)?->decoded_name),
                \Filament\Forms\Components\TextInput::make('quantity')
                    ->label('Cantitate')->numeric()->minValue(1)->default(1)->required(),
                \Filament\Forms\Components\TextInput::make('price_gross')
                    ->label('Preț cu TVA (opțional — implicit prețul de pe site)')
                    ->numeric()->minValue(0)->step('0.01')->suffix('RON'),
            ])
            ->action(fn (array $data) => $this->addOrderProduct($data));
    }

    /** Editarea unei singure linii (creionul de pe rând): cantitate și/sau preț. */
    public function editLineAction(): Action
    {
        $item = fn (array $arguments) => $this->record->items->firstWhere('woo_item_id', (int) ($arguments['itemId'] ?? 0));

        return Action::make('editLine')
            ->modalHeading(fn (array $arguments): string => 'Editează: '.($item($arguments)?->name ?? 'produs'))
            ->modalDescription('Modificarea se trimite în WooCommerce (totaluri și TVA recalculate) și rămâne în Istoricul modificărilor, cu opțiune de revenire.')
            ->modalSubmitActionLabel('Salvează în WooCommerce')
            ->modalWidth('md')
            ->form(function (array $arguments) use ($item): array {
                $it = $item($arguments);
                if (! $it) return [];
                $row = collect($this->buildEditableItems())->firstWhere('woo_item_id', $it->woo_item_id);

                return [
                    \Filament\Forms\Components\TextInput::make('quantity')
                        ->label('Cantitate')->numeric()->minValue(1)->required()
                        ->default($row['quantity'] ?? 1),
                    \Filament\Forms\Components\TextInput::make('price_gross')
                        ->label('Preț cu TVA (doar pentru această comandă)')
                        ->numeric()->minValue(0)->step('0.01')->suffix('RON')->required()
                        ->default($row['price_gross'] ?? 0),
                ];
            })
            ->action(fn (array $data, array $arguments) => $this->saveSingleLine((int) ($arguments['itemId'] ?? 0), $data));
    }

    /** Aplică editarea unei singure linii, păstrând restul neschimbat (diff + log în saveOrderItems). */
    public function saveSingleLine(int $wooItemId, array $data): void
    {
        $items = collect($this->buildEditableItems())->map(function (array $row) use ($wooItemId, $data) {
            if ((int) $row['woo_item_id'] === $wooItemId) {
                $row['quantity']    = max(1, (int) $data['quantity']);
                $row['price_gross'] = (float) $data['price_gross'];
            }
            return $row;
        })->values()->all();

        $this->saveOrderItems(['items' => $items]);
    }

    /** Căsuța Easybox curentă a comenzii (meta _sameday_shipping_locker_id) sau null. */
    public function currentLocker(): ?array
    {
        $meta = collect($this->record->data['meta_data'] ?? [])
            ->firstWhere('key', '_sameday_shipping_locker_id');

        $val = $meta['value'] ?? null;
        if (is_string($val) && $val !== '') {
            $decoded = json_decode($val, true);
            return is_array($decoded) ? $decoded : null;
        }
        return is_array($val) ? $val : null;
    }

    /** Editorul de transport (rândul 🚚 de sub produse): metodă, cost, căsuță Easybox. */
    public function editTransportAction(): Action
    {
        return Action::make('editTransport')
            ->label('Editează transportul')
            ->modalHeading(fn (): string => 'Transport — comanda #'.$this->record->number)
            ->modalDescription('Modificările se salvează direct în WooCommerce. Schimbarea căsuței Easybox actualizează și pluginul Sameday de pe site.')
            ->modalSubmitActionLabel('Salvează în WooCommerce')
            ->modalWidth('2xl')
            ->form(function (): array {
                $shipLine = collect($this->record->data['shipping_lines'] ?? [])->first();
                $locker   = $this->currentLocker();

                return array_filter([
                    \Filament\Forms\Components\TextInput::make('ship_method')
                        ->label('Metodă transport')
                        ->default($shipLine['method_title'] ?? ''),
                    \Filament\Forms\Components\TextInput::make('ship_cost_gross')
                        ->label('Cost transport (cu TVA)')
                        ->numeric()->minValue(0)->step('0.01')->suffix('RON')
                        ->default(round((float) $this->record->shipping_total * 1.21, 2))
                        ->helperText($shipLine ? 'Recalculează totalul comenzii.' : 'Comanda nu are linie de transport.')
                        ->disabled(! $shipLine),
                    Select::make('locker_id')
                        ->label($locker ? 'Căsuță Easybox (curentă: '.($locker['name'] ?? $locker['lockerId'] ?? '?').')' : 'Căsuță Easybox (opțional — transformă livrarea în Easybox)')
                        ->searchable()
                        ->default($locker['lockerId'] ?? null)
                        ->getSearchResultsUsing(fn (string $search) => \Illuminate\Support\Facades\DB::table('sameday_lockers')
                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                ->orWhere('city', 'like', "%{$search}%")
                                ->orWhere('address', 'like', "%{$search}%"))
                            ->limit(30)->get()
                            ->mapWithKeys(fn ($l) => [$l->locker_id => $l->name.' — '.$l->address.', '.$l->city.' ('.$l->county.')'])
                            ->all())
                        ->getOptionLabelUsing(function ($value) {
                            $l = \Illuminate\Support\Facades\DB::table('sameday_lockers')->where('locker_id', $value)->first();
                            return $l
                                ? $l->name.' — '.$l->address.', '.$l->city
                                : 'Easybox #'.$value.' (ales de client — valid, dar lipsește din nomenclatorul local)';
                        })
                        ->helperText('Alege căsuța de pe hartă sau caut-o după nume/oraș/adresă. Golește câmpul pentru livrare la adresă (fără Easybox).'),
                    \Filament\Forms\Components\ViewField::make('locker_map_transport')
                        ->label('')
                        ->view('filament.app.sameday-locker-map', ['lockerField' => 'locker_id'])
                        ->dehydrated(false),
                ]);
            })
            ->action(fn (array $data) => $this->saveTransport($data));
    }

    public function saveTransport(array $data): void
    {
        /** @var WooOrder $order */
        $order = $this->record;

        if (! $this->isOrderEditable()) {
            Notification::make()->danger()->title('Comanda nu mai poate fi editată')->send();
            return;
        }

        $payload = [];
        $before  = [];

        if ($this->applyTransportChanges($data, $payload, $before) === false) {
            return; // căsuță invalidă — mesajul a fost deja afișat
        }

        if (empty($payload)) {
            Notification::make()->info()->title('Nicio modificare')->send();
            return;
        }

        $this->pushOrderPayload($payload, $before, 'Editat: transport'.(isset($payload['meta_data']) ? ' + căsuță Easybox' : ''));
    }
}
