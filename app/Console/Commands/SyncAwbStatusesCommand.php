<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\IntegrationConnection;
use App\Models\SamedayAwb;
use App\Services\Courier\SamedayAwbService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Actualizare bulk a statusurilor AWB prin /api/client/status-sync:
 * un apel per fereastră de 2h aduce TOATE schimbările din cont, fără
 * apeluri per AWB. Când un AWB devine terminal, i se aduce o singură
 * dată istoricul complet (pentru timeline-ul salvat local), apoi nu se
 * mai face niciun apel pentru el.
 */
class SyncAwbStatusesCommand extends Command
{
    private const LAST_TS_KEY = 'awb_status_sync_last_ts';

    protected $signature = 'awb:sync-courier-status
        {--hours=0 : Forțează fereastra la ultimele N ore (0 = de la ultima rulare)}';

    protected $description = 'Sincronizează bulk statusurile AWB Sameday (status-sync, un apel per 2h)';

    public function handle(SamedayAwbService $service): int
    {
        $connection = IntegrationConnection::where('provider', IntegrationConnection::PROVIDER_SAMEDAY)
            ->where('is_active', true)->first();
        if (! $connection) {
            $this->error('Nicio conexiune Sameday activă.');

            return self::FAILURE;
        }

        $now = time();
        $hours = (int) $this->option('hours');
        if ($hours > 0) {
            $from = $now - $hours * 3600;
        } else {
            $lastTs = (int) (AppSetting::get(self::LAST_TS_KEY) ?? 0);
            // suprapunere de 10 min ca să nu pierdem evenimente la granițe;
            // plafon 24h ca să nu facem zeci de ferestre după o pauză lungă
            $from = max($lastTs > 0 ? $lastTs - 600 : $now - 7000, $now - 86400);
        }

        try {
            $events = $service->getStatusSyncEvents($connection, $from, $now);
        } catch (\Throwable $e) {
            $this->error('status-sync a eșuat: ' . $e->getMessage());
            Log::warning('[AwbStatusSync] ' . substr($e->getMessage(), 0, 200));

            return self::FAILURE;
        }

        $byAwb = collect($events)->groupBy('awb');
        $this->info('Evenimente: ' . count($events) . ' pe ' . $byAwb->count() . ' AWB-uri.');

        $updated = 0;
        $finalized = 0;
        foreach ($byAwb as $awbNumber => $awbEvents) {
            $awb = SamedayAwb::where('awb_number', $awbNumber)->first();
            if (! $awb || $awb->isTrackingTerminal()) {
                continue;
            }

            // completează istoricul local cu evenimentele noi (dedupe pe label+dată)
            $tracking = $awb->tracking_history ?? ['summary' => [], 'history' => []];
            $known = collect($tracking['history'] ?? [])
                ->map(fn ($h) => ($h['label'] ?? '') . '|' . ($h['date'] ?? ''))->all();
            foreach ($awbEvents as $event) {
                $key = $event['label'] . '|' . ($event['date'] ?? '');
                if (! in_array($key, $known, true)) {
                    $tracking['history'][] = [
                        'label' => $event['label'], 'state' => $event['state'],
                        'date' => $event['date'], 'county' => null, 'transit' => null,
                    ];
                    $known[] = $key;
                }
            }
            usort($tracking['history'], fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

            $latest = $awbEvents->last();
            $label = $latest['label'];

            // «livrat» din eveniment (nu «nelivrat»/«nu a fost livrat»)
            $isDelivered = preg_match('/livrat/i', $label)
                && ! preg_match('/nelivrat|nu a (fost|putut)/i', $label);
            $deliveredAt = $isDelivered ? $latest['date'] : null;
            if ($deliveredAt && ! preg_match('/rambur|retur/i', $label)) {
                $label = 'Livrat — ' . $deliveredAt
                    . ((float) $awb->cod_amount > 0 ? ' (ramburs în așteptare)' : '');
                $tracking['summary']['delivered_at'] = $deliveredAt;
            }

            $pickedUp = collect($awbEvents)
                ->filter(fn ($e) => preg_match('/ridicat/i', $e['label']))
                ->pluck('date')->filter()->sort()->first();

            $awb->update([
                'courier_status'    => $label,
                'courier_status_at' => $latest['date'] ?? now(),
                'picked_up_at'      => $awb->picked_up_at ?? $pickedUp,
                'delivered_at'      => $awb->delivered_at ?? $deliveredAt,
                'tracking_history'  => $tracking,
            ]);
            $updated++;

            // a devenit terminal → un singur apel de istoric complet, apoi liniște definitivă
            if ($awb->fresh()->isTrackingTerminal()) {
                try {
                    $full = $service->getAwbStatusHistory($connection, $awb->awb_number);
                    $awb->update([
                        'tracking_history' => $full,
                        'delivered_at'     => $awb->delivered_at
                            ?? ($full['summary']['delivered_at'] ?? null),
                    ]);
                    $finalized++;
                } catch (\Throwable $e) {
                    Log::info('[AwbStatusSync] istoric final ' . $awb->awb_number . ': ' . substr($e->getMessage(), 0, 120));
                }
            }
        }

        AppSetting::set(self::LAST_TS_KEY, (string) $now);
        $this->info("AWB-uri actualizate: {$updated} (finalizate cu istoric complet: {$finalized}).");

        return self::SUCCESS;
    }
}
