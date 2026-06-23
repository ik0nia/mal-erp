<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Gdpr\RetentionService;
use Illuminate\Console\Command;

/**
 * Retenție GDPR. IMPLICIT DOAR RAPORTEAZĂ (dry-run) — nu modifică nimic.
 * Anonimizează efectiv DOAR cu --apply + ferestre configurate + confirmare.
 * NU este programată automat: se rulează manual, deliberat.
 */
class GdprApplyRetentionCommand extends Command
{
    protected $signature = 'gdpr:apply-retention {--apply : Aplică efectiv anonimizarea (implicit: doar raport)}';

    protected $description = 'Raportează (și opțional, cu --apply, aplică) anonimizarea PII conform politicilor de retenție';

    public function handle(RetentionService $svc): int
    {
        $apply = (bool) $this->option('apply');

        $windows = [
            ['key' => 'gdpr_retention_sameday_months', 'label' => 'AWB-uri Sameday', 'method' => 'anonymizeSamedayAwbs'],
            ['key' => 'gdpr_retention_wooorder_months', 'label' => 'Comenzi online', 'method' => 'anonymizeWooOrders'],
            ['key' => 'gdpr_retention_email_months', 'label' => 'Emailuri', 'method' => 'anonymizeEmailMessages'],
        ];
        foreach ($windows as &$w) {
            $w['months'] = (int) AppSetting::get($w['key'], 0);
        }
        unset($w);

        $anyEnabled = collect($windows)->contains(fn ($w) => $w['months'] > 0);
        if (! $anyEnabled) {
            $this->info('Nicio politică de retenție activă (toate ferestrele = 0). Anonimizarea este DEZACTIVATĂ — nimic de făcut.');

            return self::SUCCESS;
        }

        if ($apply && ! $this->confirm('ATENȚIE: vei anonimiza IREVERSIBIL datele personale din înregistrările vechi. Continui?')) {
            $this->warn('Anulat — nimic modificat.');

            return self::SUCCESS;
        }

        $dryRun = ! $apply;
        $total = 0;
        foreach ($windows as $w) {
            if ($w['months'] <= 0) {
                $this->line("  {$w['label']}: dezactivat");

                continue;
            }
            $cutoff = now()->subMonths($w['months']);
            $n = $svc->{$w['method']}($cutoff, $dryRun);
            $total += $n;
            $verb = $dryRun ? 'ar fi anonimizate' : 'anonimizate';
            $this->line("  {$w['label']}: {$n} {$verb} (mai vechi de {$w['months']} luni)");
        }

        if (! $dryRun && $total > 0) {
            activity('audit')->event('retention')
                ->withProperties(['total' => $total])
                ->log("Retenție GDPR aplicată: {$total} înregistrări anonimizate");
        }

        $this->info($dryRun
            ? 'RAPORT (dry-run) — NIMIC modificat. Rulează cu --apply pentru a anonimiza efectiv.'
            : "Gata: {$total} înregistrări anonimizate.");

        return self::SUCCESS;
    }
}
