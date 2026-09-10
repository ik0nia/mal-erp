<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\SamedayAwb;
use App\Services\Courier\SamedayAwbService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Împrospătează periodic statusul curierului pentru AWB-urile active:
 * create în ultimele 14 zile, cu număr valid, necanulate/neeșuate și
 * nelivrate încă. AWB-urile livrate nu se mai verifică (status terminal).
 */
class RefreshAwbCourierStatusCommand extends Command
{
    protected $signature = 'awb:refresh-courier-status {--days=0 : Limitează la AWB-uri din ultimele N zile (0 = toate)}';

    protected $description = 'Actualizează statusul curier (tracking Sameday) pentru AWB-urile active';

    public function handle(SamedayAwbService $service): int
    {
        $days = (int) $this->option('days');
        $awbs = SamedayAwb::query()
            ->whereNotNull('awb_number')->where('awb_number', '!=', '')
            ->whereNotIn('status', [SamedayAwb::STATUS_CANCELLED, SamedayAwb::STATUS_FAILED])
            ->when($days > 0, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)))
            ->where(function ($q) {
                // se opresc din verificare doar statusurile TERMINALE + marcajul «indisponibil»
                $q->whereNull('courier_status')
                    ->orWhere(function ($w) {
                        $w->whereRaw("courier_status NOT REGEXP 'livrat|retur|rambur|anulat|refuz'")
                            ->where('courier_status', '!=', 'indisponibil');
                    });
            })
            ->orderBy('id')
            ->get();

        $this->info('AWB-uri active de verificat: ' . $awbs->count());

        $fallback = IntegrationConnection::where('provider', IntegrationConnection::PROVIDER_SAMEDAY)
            ->where('is_active', true)->first();

        $updated = 0;
        $delivered = 0;
        foreach ($awbs as $awb) {
            try {
                $connection = $awb->connection
                    ?? IntegrationConnection::find($awb->integration_connection_id)
                    ?? $fallback;
                if (! $connection) continue;

                $tracking = $service->getAwbStatusHistory($connection, $awb->awb_number);
                $last = $tracking['history'][0] ?? null;
                $deliveredAt = $tracking['summary']['delivered_at'] ?? null;

                $awb->update([
                    'courier_status'    => $deliveredAt ? ('Livrat — ' . $deliveredAt) : ($last['label'] ?? $awb->courier_status),
                    'courier_status_at' => $last['date'] ?? $awb->courier_status_at,
                ]);
                $updated++;
                if ($deliveredAt) $delivered++;
            } catch (\Throwable $e) {
                Log::warning('[AwbStatusRefresh] ' . $awb->awb_number . ': ' . substr($e->getMessage(), 0, 120));
                // AWB vechi care n-a avut NICIODATĂ status și trackingul eșuează → nu-l mai reverificăm
                if ($awb->courier_status === null && $awb->created_at->lt(now()->subDays(3))) {
                    $awb->update(['courier_status' => 'indisponibil', 'courier_status_at' => now()]);
                }
            }

            usleep(400000); // politețe față de API-ul Sameday
        }

        $this->info("Actualizate: {$updated} (din care livrate: {$delivered}).");

        return self::SUCCESS;
    }
}
