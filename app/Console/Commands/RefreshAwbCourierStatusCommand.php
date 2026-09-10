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
                // Terminale: retur/ramburs transferat/anulat/refuz + marcajul «indisponibil».
                // «Livrat» e terminal DOAR fără ramburs — la COD așteptăm și transferul banilor.
                $q->whereNull('courier_status')
                    ->orWhere(function ($w) {
                        $w->whereRaw("courier_status NOT REGEXP 'retur|rambur|anulat|refuz'")
                            ->where('courier_status', '!=', 'indisponibil')
                            ->where(function ($v) {
                                $v->whereRaw("courier_status NOT REGEXP 'livrat'")
                                    ->orWhere('cod_amount', '>', 0);
                            });
                    });
            })
            ->orderBy('courier_status_at') // cele neverificate demult primele
            ->limit(60) // max per rulare — Sameday face rate-limiting agresiv
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

                try {
                    $tracking = $service->getAwbStatusHistory($connection, $awb->awb_number);
                } catch (\Throwable $first) {
                    sleep(4); // rate-limit Sameday: o singură reîncercare, cu pauză
                    $tracking = $service->getAwbStatusHistory($connection, $awb->awb_number);
                }
                $last = $tracking['history'][0] ?? null;
                $deliveredAt = $tracking['summary']['delivered_at'] ?? null;

                // momentul ridicării = cel mai vechi eveniment «ridicat» din istoric
                $pickedUp = collect($tracking['history'])
                    ->filter(fn ($h) => preg_match('/ridicat/i', (string) ($h['label'] ?? '')))
                    ->pluck('date')->filter()->sort()->first();

                $label = $last['label'] ?? $awb->courier_status;
                // după livrare, evenimentele de ramburs/retur au prioritate (închid ciclul COD)
                if ($deliveredAt && ! preg_match('/rambur|retur/i', (string) $label)) {
                    $label = 'Livrat — ' . $deliveredAt
                        . ((float) $awb->cod_amount > 0 ? ' (ramburs în așteptare)' : '');
                }
                $awb->update([
                    'courier_status'    => $label,
                    'courier_status_at' => $last['date'] ?? $awb->courier_status_at,
                    'picked_up_at'      => $pickedUp ?? $awb->picked_up_at,
                    'delivered_at'      => $deliveredAt ?? $awb->delivered_at,
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

            usleep(1500000); // politețe față de API-ul Sameday (rate-limit strict)
        }

        $this->info("Actualizate: {$updated} (din care livrate: {$delivered}).");

        return self::SUCCESS;
    }
}
