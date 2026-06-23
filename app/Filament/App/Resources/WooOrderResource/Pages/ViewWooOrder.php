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

            Action::make('change_status')
                ->label('Schimbă status')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
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
                        $this->refreshFormData(['status']);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Eroare')->body($e->getMessage())->send();
                    }
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

            Action::make('create_awb')
                ->label('Creare AWB Sameday')
                ->icon('heroicon-o-truck')
                ->color('success')
                ->url(fn (): string => $this->buildCreateAwbUrl())
                ->openUrlInNewTab(false),

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

    private function buildCreateAwbUrl(): string
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
        ]);

        return route('filament.app.resources.sameday-awbs.create').'?'.http_build_query($params);
    }
}
