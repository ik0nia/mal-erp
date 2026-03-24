<?php

namespace App\Console\Commands;

use App\Models\ChatLog;
use Illuminate\Console\Command;

class CleanupChatLogsCommand extends Command
{
    protected $signature   = 'gdpr:cleanup-chat-logs {--days=90 : Numărul de zile după care se anonimizează IP-urile}';
    protected $description = 'Anonimizează IP-urile din chat_logs mai vechi de N zile (GDPR compliance)';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $count = ChatLog::where('created_at', '<', $cutoff)
            ->whereNotNull('ip')
            ->where('ip', '!=', '0.0.0.0')
            ->count();

        if ($count === 0) {
            $this->info('Nu există înregistrări de anonimizat.');

            return self::SUCCESS;
        }

        ChatLog::where('created_at', '<', $cutoff)
            ->whereNotNull('ip')
            ->where('ip', '!=', '0.0.0.0')
            ->update(['ip' => '0.0.0.0']);

        $this->info("Anonimizate {$count} înregistrări chat_logs mai vechi de {$days} zile.");

        return self::SUCCESS;
    }
}
