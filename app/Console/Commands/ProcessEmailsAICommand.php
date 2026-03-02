<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmailAIJob;
use App\Models\AppSetting;
use App\Models\EmailMessage;
use Illuminate\Console\Command;

/**
 * Dispatch-uiește procesarea AI pentru emailurile neprocesate.
 * Poate fi rulat manual sau adăugat în schedule.
 *
 * Usage:
 *   php artisan email:process-ai              # toate neprocesate, max 200
 *   php artisan email:process-ai --limit=50   # doar 50
 *   php artisan email:process-ai --supplier=5 # doar pentru furnizorul 5
 *   php artisan email:process-ai --sync       # rulare sincronă (fără queue)
 */
class ProcessEmailsAICommand extends Command
{
    protected $signature = 'email:process-ai
                            {--limit=200 : Număr maxim de emailuri de procesat}
                            {--supplier= : Procesează doar emailurile unui furnizor (ID)}
                            {--sync : Rulează sincron în loc de queue}';

    protected $description = 'Procesează emailuri cu Claude AI și extrage date structurate';

    public function handle(): int
    {
        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY);

        if (blank($apiKey)) {
            $this->error('Cheia Anthropic API nu este configurată. Mergi la Setări → Inteligență Artificială.');
            return self::FAILURE;
        }

        $limit      = (int) $this->option('limit');
        $supplierId = $this->option('supplier');
        $sync       = $this->option('sync');

        $query = EmailMessage::whereNull('agent_processed_at')
            ->whereIn('imap_folder', ['INBOX', 'INBOX.Sent'])  // procesăm inbox + trimise
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->orderByDesc('sent_at')
            ->limit($limit);

        $total = $query->count();

        if ($total === 0) {
            $this->info('Nu există emailuri neprocesate.');
            return self::SUCCESS;
        }

        $this->info("Procesare {$total} emailuri cu Claude AI...");
        if ($sync) {
            $this->warn('Mod sincron — rularea va dura ceva timp.');
        }

        $bar = $this->output->createProgressBar($total);
        $dispatched = 0;

        $query->each(function (EmailMessage $email) use ($sync, $bar, &$dispatched) {
            if ($sync) {
                (new ProcessEmailAIJob($email->id))->handle();
            } else {
                ProcessEmailAIJob::dispatch($email->id);
            }
            $bar->advance();
            $dispatched++;
        });

        $bar->finish();
        $this->newLine();

        if ($sync) {
            $this->info("Procesate sincron: {$dispatched} emailuri.");
        } else {
            $this->info("Dispatch-uite în queue: {$dispatched} job-uri. Monitorizează cu: php artisan queue:work");
        }

        return self::SUCCESS;
    }
}
