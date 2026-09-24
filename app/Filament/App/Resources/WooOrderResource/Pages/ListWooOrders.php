<?php

namespace App\Filament\App\Resources\WooOrderResource\Pages;

use App\Filament\App\Resources\WooOrderResource;
use App\Models\IntegrationConnection;
use App\Models\WooOrder;
use App\Services\WooCommerce\WooClient;
use App\Services\WooCommerce\WooOrderSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ListWooOrders extends ListRecords
{
    protected static string $resource = WooOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\App\Widgets\WooOrderTimelineWidget::class,
            \App\Filament\App\Widgets\AwbDeliveryStatsWidget::class,
        ];
    }

    /** Tab-uri de status cu contoare — fluxul zilnic la un click.
     *  ⚠ Parametrul closure-ului TREBUIE să se numească $query (Filament îl injectează
     *  după nume). Un alt nume + type-hint Builder → Filament rezolvă un Builder gol
     *  din container (fără model) → eroare „newQueryWithoutRelationships on null". */
    public function getTabs(): array
    {
        $deProcesat = ['pending', 'processing', 'on-hold'];

        $count = fn (array $statuses): ?string => ($n = WooOrder::query()->whereIn('status', $statuses)->count()) > 0 ? (string) $n : null;

        // De expediat = GATA de expediat: în procesare, fără AWB valid, non-ridicare din depozit,
        // ȘI cu stoc suficient pentru TOATE produsele la locația comenzii (poate fi trimisă acum).
        $shortfall = 'EXISTS (SELECT 1 FROM woo_order_items oi JOIN woo_products wp ON wp.woo_id = oi.woo_product_id'
            .' WHERE oi.order_id = woo_orders.id AND oi.quantity > COALESCE((SELECT SUM(ps.quantity) FROM product_stocks ps'
            .' WHERE ps.woo_product_id = wp.id AND (woo_orders.location_id = 0 OR ps.location_id = woo_orders.location_id)), 0))';

        $deExpediatQ = fn ($query) => $query
            ->whereIn('status', ['processing', 'on-hold'])
            ->where('data', 'not like', '%local_pickup%')
            ->whereDoesntHave('samedayAwbs', fn ($q) => $q
                ->whereNotNull('awb_number')->where('awb_number', '!=', '')->where('status', '!=', 'cancelled'))
            ->whereRaw('NOT '.$shortfall);

        $countQ = fn (callable $m): ?string => ($n = $m(WooOrder::query())->count()) > 0 ? (string) $n : null;

        return [
            'toate' => Tab::make('Toate'),

            'de_procesat' => Tab::make('De procesat')
                ->modifyQueryUsing(fn ($query) => $query->whereIn('status', $deProcesat))
                ->badge($count($deProcesat))
                ->badgeColor('warning'),

            // În procesare, dar nefacturate în WinMentor SAU cu discrepanță de total.
            'de_atentie' => Tab::make('⚠ De atenție')
                ->modifyQueryUsing(fn ($query) => $query
                    ->whereIn('status', $deProcesat)
                    ->whereRaw('(winmentor_invoice_nr IS NULL OR ABS(total - COALESCE(winmentor_invoice_total, total)) > 0.05)'))
                ->badgeColor('danger'),

            'de_expediat' => Tab::make('📦 De expediat')
                ->modifyQueryUsing($deExpediatQ)
                ->badge($countQ($deExpediatQ))
                ->badgeColor('info'),

            'finalizate' => Tab::make('Finalizate')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'completed'))
                ->badge($count(['completed']))
                ->badgeColor('success'),

            'anulate' => Tab::make('Anulate')
                ->modifyQueryUsing(fn ($query) => $query->whereIn('status', ['cancelled', 'refunded', 'failed'])),
        ];
    }

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
