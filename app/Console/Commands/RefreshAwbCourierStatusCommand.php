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
    protected $signature = 'awb:refresh-courier-status
        {--days=0 : Limitează la AWB-uri din ultimele N zile (0 = toate)}
        {--delay=1.5 : Secunde de pauză între apeluri}
        {--limit=60 : Max AWB-uri per rulare (0 = toate)}
        {--cod : DOAR livrate cu ramburs neîncasat (verificarea zilnică de încasare)}';

    protected $description = 'Actualizează statusul curier (tracking Sameday) pentru AWB-urile active';

    public function handle(SamedayAwbService $service): int
    {
        $days = (int) $this->option('days');
        $codOnly = (bool) $this->option('cod');
        $awbs = SamedayAwb::query()
            ->whereNotNull('sameday_awbs.awb_number')->where('sameday_awbs.awb_number', '!=', '')
            ->whereNotIn('sameday_awbs.status', [SamedayAwb::STATUS_CANCELLED, SamedayAwb::STATUS_FAILED])
            ->when($days > 0, fn ($q) => $q->where('sameday_awbs.created_at', '>=', now()->subDays($days)))
            ->whereRaw("COALESCE(sameday_awbs.courier_status,'') NOT REGEXP 'retur|anulat|refuz|rambur.*(transferat|compensat)'")
            ->where(fn ($q) => $q->whereNull('sameday_awbs.courier_status')->orWhere('sameday_awbs.courier_status', '!=', 'indisponibil'))
            ->when($codOnly,
                // --cod: DOAR livrate cu ramburs neîncasat (rulate o dată pe zi)
                fn ($q) => $q->whereRaw("sameday_awbs.courier_status REGEXP 'livrat'")
                    ->where('sameday_awbs.cod_amount', '>', 0),
                // implicit: DOAR nelivrate / fără status — cele livrate cu COD
                // NU se verifică des, au rulaje zilnice dedicate (--cod)
                fn ($q) => $q->where(fn ($w) => $w->whereNull('sameday_awbs.courier_status')
                    ->orWhereRaw("sameday_awbs.courier_status NOT REGEXP 'livrat'"))
            )
            // cele mai NOI fără status primele — după data COMENZII reale
            // (created_at din ERP minte la cele importate în bloc de pe site);
            // cele care au tot eșuat trec la coadă, să nu blocheze lotul
            ->leftJoin('woo_orders', 'woo_orders.id', '=', 'sameday_awbs.woo_order_id')
            ->select('sameday_awbs.*')
            ->orderByRaw('(sameday_awbs.courier_status IS NULL) DESC, sameday_awbs.tracking_attempts ASC, COALESCE(woo_orders.order_date, sameday_awbs.created_at) DESC')
            ->when((int) $this->option('limit') > 0, fn ($q) => $q->limit((int) $this->option('limit')))
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
                    'tracking_history'  => $tracking, // istoricul complet, salvat local
                    'tracking_attempts' => 0,
                ]);
                $updated++;
                if ($deliveredAt) $delivered++;
            } catch (\Throwable $e) {
                $msg = $e->getMessage() !== '' ? $e->getMessage() : class_basename($e);
                Log::warning('[AwbStatusRefresh] ' . $awb->awb_number . ': ' . substr($msg, 0, 120));
                $attempts = $awb->tracking_attempts + 1;
                // NotFound = Sameday nu mai are AWB-ul (istoric purjat / cont diferit) —
                // nu are rost să insistăm; restul erorilor pot fi rate-limit temporar,
                // deci răbdare: renunțăm («indisponibil») abia după 8 eșecuri
                $prag = $e instanceof \Sameday\Exceptions\SamedayNotFoundException ? 2 : 8;
                if ($awb->courier_status === null && $attempts >= $prag) {
                    $awb->update(['courier_status' => 'indisponibil', 'courier_status_at' => now(), 'tracking_attempts' => $attempts]);
                } else {
                    $awb->update(['tracking_attempts' => $attempts]);
                }
            }

            usleep((int) ((float) $this->option('delay') * 1_000_000)); // politețe față de API-ul Sameday
        }

        $this->info("Actualizate: {$updated} (din care livrate: {$delivered}).");

        return self::SUCCESS;
    }
}
