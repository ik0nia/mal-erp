<?php

namespace App\Filament\App\Resources\SamedayAwbResource\Pages;

use App\Filament\App\Resources\SamedayAwbResource;
use App\Models\IntegrationConnection;
use App\Models\SamedayAwb;
use App\Models\WooOrder;
use App\Services\Courier\SamedayAwbService;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSamedayAwbs extends ListRecords
{
    protected static string $resource = SamedayAwbResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            // Pentru AWB-uri create la Sameday dar nesalvate în ERP (ex. eroare la creare):
            // se introduce numărul din platforma Sameday, îl verificăm live și îl legăm de comandă.
            Actions\Action::make('attach_awb')
                ->label('Atașează AWB existent')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->schema([
                    TextInput::make('awb_number')
                        ->label('Număr AWB (din platforma Sameday)')
                        ->required()
                        ->placeholder('1ONB...'),
                    TextInput::make('order_number')
                        ->label('Numărul comenzii (opțional)')
                        ->placeholder('156775')
                        ->helperText('Dacă îl completezi, AWB-ul se leagă de comandă.'),
                ])
                ->action(function (array $data): void {
                    $awbNumber = trim($data['awb_number']);

                    if (SamedayAwb::where('awb_number', $awbNumber)->exists()) {
                        Notification::make()->warning()->title('Există deja')
                            ->body('AWB-ul ' . $awbNumber . ' este deja înregistrat în ERP.')->send();

                        return;
                    }

                    $order = filled($data['order_number'] ?? null)
                        ? WooOrder::where('number', trim($data['order_number']))->first()
                        : null;

                    $connection = IntegrationConnection::query()
                        ->where('provider', IntegrationConnection::PROVIDER_SAMEDAY)
                        ->where('is_active', true)
                        ->first();
                    if (! $connection) {
                        Notification::make()->danger()->title('Fără conexiune Sameday activă')->send();

                        return;
                    }

                    try {
                        // verificare reală: dacă Sameday nu-l recunoaște, aruncă excepție
                        $tracking = app(SamedayAwbService::class)->getAwbStatusHistory($connection, $awbNumber);
                        $last = $tracking['history'][0] ?? null;

                        SamedayAwb::create([
                            'location_id'               => auth()->user()?->location_id,
                            'user_id'                   => auth()->id(),
                            'integration_connection_id' => $connection->id,
                            'woo_order_id'              => $order?->id,
                            'provider'                  => IntegrationConnection::PROVIDER_SAMEDAY,
                            'status'                    => SamedayAwb::STATUS_CREATED,
                            'awb_number'                => $awbNumber,
                            'recipient_name'            => $order ? trim(data_get($order->billing, 'first_name') . ' ' . data_get($order->billing, 'last_name')) : '(atașat manual)',
                            'recipient_phone'           => $order ? (string) data_get($order->billing, 'phone', '') : '',
                            'recipient_county'          => $order ? (string) data_get($order->shipping, 'state', data_get($order->billing, 'state', '')) : '',
                            'recipient_city'            => $order ? (string) data_get($order->shipping, 'city', data_get($order->billing, 'city', '')) : '',
                            'recipient_address'         => $order ? (string) data_get($order->shipping, 'address_1', data_get($order->billing, 'address_1', '')) : '',
                            'package_count'             => 1,
                            'package_weight_kg'         => (float) ($tracking['summary']['awb_weight'] ?? 1),
                            'cod_amount'                => (float) ($tracking['summary']['cash_on_delivery'] ?? 0),
                            'reference'                 => $order?->number,
                            'observation'               => 'Atașat manual — AWB creat la Sameday, nesalvat inițial în ERP.',
                            'courier_status'            => $last['label'] ?? null,
                            'courier_status_at'         => $last['date'] ?? null,
                        ]);

                        Notification::make()->success()->title('AWB atașat')
                            ->body('AWB ' . $awbNumber . ($order ? ' legat de comanda #' . $order->number : '') . '. Ultimul status: ' . ($last['label'] ?? '—'))
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('AWB negăsit la Sameday')
                            ->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
