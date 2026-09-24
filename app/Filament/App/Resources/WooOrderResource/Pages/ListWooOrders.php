<?php

namespace App\Filament\App\Resources\WooOrderResource\Pages;

use App\Filament\App\Resources\WooOrderResource;
use App\Models\IntegrationConnection;
use App\Models\WooOrder;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use App\Services\WooCommerce\WooOrderSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class ListWooOrders extends ListRecords
{
    protected static string $resource = WooOrderResource::class;

    /** Memo per-request al alocării FIFO (calculată o dată, refolosită de tab-uri + badge-uri). */
    private ?array $shippableSets = null;

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

        // Alocare FIFO a stocului pe comenzile deschise (vezi shippableSets()).
        $sets = $this->shippableSets();
        $badge = fn (array $ids): ?string => count($ids) > 0 ? (string) count($ids) : null;

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
                ->modifyQueryUsing(fn ($query) => $query->whereIn('id', $sets['de_expediat']))
                ->badge($badge($sets['de_expediat']))
                ->badgeColor('info'),

            'asteapta_stoc' => Tab::make('⏳ Așteaptă stoc')
                ->modifyQueryUsing(fn ($query) => $query->whereIn('id', $sets['asteapta_stoc']))
                ->badge($badge($sets['asteapta_stoc']))
                ->badgeColor('warning'),

            'finalizate' => Tab::make('Finalizate')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'completed'))
                ->badge($count(['completed']))
                ->badgeColor('success'),

            'anulate' => Tab::make('Anulate')
                ->modifyQueryUsing(fn ($query) => $query->whereIn('status', ['cancelled', 'refunded', 'failed'])),
        ];
    }

    /**
     * Împarte comenzile deschise în „de expediat" și „așteaptă stoc" printr-o alocare
     * FIFO a stocului: cele mai vechi comenzi au prioritate. O comandă e „de expediat"
     * doar dacă TOATE produsele ei încap în stocul rămas după ce s-a rezervat pentru
     * comenzile mai vechi. Altfel filtrul ar afișa aceeași bucată ca disponibilă pe mai
     * multe comenzi simultan (over-promise).
     *
     * @return array{de_expediat: array<int>, asteapta_stoc: array<int>}
     */
    private function shippableSets(): array
    {
        if ($this->shippableSets !== null) {
            return $this->shippableSets;
        }

        // Comenzi deschise, non-ridicare din depozit, fără AWB valid — FIFO (cele mai vechi întâi).
        $orders = WooOrder::query()
            ->whereIn('status', ['processing', 'on-hold'])
            ->where('data', 'not like', '%local_pickup%')
            ->whereDoesntHave('samedayAwbs', fn ($q) => $q
                ->whereNotNull('awb_number')->where('awb_number', '!=', '')->where('status', '!=', 'cancelled'))
            ->with('items:id,order_id,woo_product_id,quantity')
            ->orderByRaw('COALESCE(order_date, created_at) ASC')
            ->get(['id', 'location_id', 'order_date', 'created_at']);

        if ($orders->isEmpty()) {
            return $this->shippableSets = ['de_expediat' => [], 'asteapta_stoc' => []];
        }

        // woo_order_items.woo_product_id = id REMOTE → mapăm la id LOCAL (woo_products.woo_id).
        $remoteIds = $orders->flatMap(fn ($o) => $o->items)->pluck('woo_product_id')->filter()->unique()->values();
        $localByRemote = WooProduct::whereIn('woo_id', $remoteIds)->pluck('id', 'woo_id'); // [remote => local]

        // Stoc rămas per [id local][locație].
        $remaining = [];
        foreach (DB::table('product_stocks')
            ->whereIn('woo_product_id', $localByRemote->values())
            ->select('woo_product_id', 'location_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('woo_product_id', 'location_id')->get() as $r) {
            $remaining[(int) $r->woo_product_id][(int) $r->location_id] = (float) $r->qty;
        }

        // Disponibil: locație 0 pe comandă = suma pe toate locațiile; altfel doar locația comenzii.
        $avail = function (int $lid, int $loc) use (&$remaining): float {
            if (! isset($remaining[$lid])) {
                return 0.0;
            }
            return $loc === 0 ? array_sum($remaining[$lid]) : ($remaining[$lid][$loc] ?? 0.0);
        };
        $deduct = function (int $lid, int $loc, float $amt) use (&$remaining): void {
            if ($amt <= 0 || ! isset($remaining[$lid])) {
                return;
            }
            if ($loc !== 0) {
                $remaining[$lid][$loc] = ($remaining[$lid][$loc] ?? 0.0) - $amt;
                return;
            }
            foreach ($remaining[$lid] as $l => $q) { // consumă din orice locație (comandă fără locație fixă)
                if ($amt <= 0) {
                    break;
                }
                $take = min($q, $amt);
                $remaining[$lid][$l] = $q - $take;
                $amt -= $take;
            }
        };

        $de = [];
        $wait = [];
        foreach ($orders as $o) {
            $loc = (int) ($o->location_id ?? 0);

            // Necesar per produs local (o comandă poate avea același produs pe mai multe linii).
            $needs = [];
            foreach ($o->items as $it) {
                $lid = $localByRemote[$it->woo_product_id] ?? null;
                if ($lid === null) {
                    continue; // produs fără corespondent local → nu blochează expedierea
                }
                $needs[(int) $lid] = ($needs[(int) $lid] ?? 0.0) + (float) $it->quantity;
            }

            $covered = true;
            foreach ($needs as $lid => $need) {
                if ($avail($lid, $loc) < $need) {
                    $covered = false;
                    break;
                }
            }

            if ($covered) {
                foreach ($needs as $lid => $need) {
                    $deduct($lid, $loc, $need); // rezervă stocul pentru această comandă (FIFO)
                }
                $de[] = $o->id;
            } else {
                $wait[] = $o->id;
            }
        }

        return $this->shippableSets = ['de_expediat' => $de, 'asteapta_stoc' => $wait];
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
