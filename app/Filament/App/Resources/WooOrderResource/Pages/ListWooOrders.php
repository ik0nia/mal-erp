<?php

namespace App\Filament\App\Resources\WooOrderResource\Pages;

use App\Filament\App\Resources\WooOrderResource;
use App\Models\IntegrationConnection;
use App\Services\WooCommerce\WooClient;
use App\Services\WooCommerce\WooOrderSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListWooOrders extends ListRecords
{
    protected static string $resource = WooOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_orders')
                ->label('Sincronizare comenzi')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $connections = IntegrationConnection::query()
                        ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
                        ->where('is_active', true)
                        ->get();

                    if ($connections->isEmpty()) {
                        Notification::make()->warning()->title('Nicio conexiune WooCommerce activă')->send();

                        return;
                    }

                    $service = new WooOrderSyncService();
                    $total   = 0;

                    foreach ($connections as $connection) {
                        try {
                            $client = new WooClient($connection);
                            $orders = $client->getOrders(1, 100);

                            foreach ($orders as $raw) {
                                $service->upsertOrder($connection->id, $connection->location_id, $raw);
                                $total++;
                            }
                        } catch (Throwable $e) {
                            Notification::make()->danger()
                                ->title("Eroare ({$connection->name})")
                                ->body($e->getMessage())
                                ->send();
                        }
                    }

                    Notification::make()->success()
                        ->title("Sincronizate {$total} comenzi")
                        ->send();
                }),
        ];
    }
}
