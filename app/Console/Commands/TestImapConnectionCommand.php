<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;
use Webklex\PHPIMAP\ClientManager;

class TestImapConnectionCommand extends Command
{
    protected $signature = 'imap:test
                            {--limit=10 : Număr emailuri de afișat}';

    protected $description = 'Testează conexiunea IMAP și afișează ultimele emailuri (read-only, fără modificări pe server)';

    public function handle(): int
    {
        $host       = AppSetting::get(AppSetting::KEY_IMAP_HOST);
        $port       = AppSetting::get(AppSetting::KEY_IMAP_PORT, '993');
        $encryption = AppSetting::get(AppSetting::KEY_IMAP_ENCRYPTION, 'ssl');
        $username   = AppSetting::get(AppSetting::KEY_IMAP_USERNAME);
        $password   = AppSetting::getEncrypted(AppSetting::KEY_IMAP_PASSWORD);

        if (blank($host) || blank($username) || blank($password)) {
            $this->error('Credențialele IMAP nu sunt configurate. Mergi la Admin → Setări aplicație.');
            return self::FAILURE;
        }

        $this->info("Conexiune la {$host}:{$port} ({$encryption}) ca {$username}...");

        $cm = new ClientManager();

        $client = $cm->make([
            'host'          => $host,
            'port'          => (int) $port,
            'encryption'    => $encryption,
            'validate_cert' => false,
            'username'      => $username,
            'password'      => $password,
            'protocol'      => 'imap',
        ]);

        try {
            $client->connect();
        } catch (\Throwable $e) {
            $this->error('Conexiune eșuată: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Conectat cu succes!');
        $this->newLine();

        $folder   = $client->getFolder('INBOX');
        $limit    = (int) $this->option('limit');

        // PEEK mode — nu marchează ca citite pe server
        $messages = $folder->messages()
            ->all()
            ->leaveUnread()
            ->setFetchOrder('desc')
            ->limit($limit)
            ->get();

        $this->info("Ultimele {$messages->count()} emailuri din INBOX:");
        $this->newLine();

        $rows = [];
        foreach ($messages as $message) {
            $from    = $message->getFrom()->first();
            $subject = $message->getSubject()->first() ?? '(fără subiect)';
            $date    = $message->getDate()->first()?->toDateTimeString() ?? '-';
            $hasAtt  = $message->hasAttachments() ? '📎' : '';

            $rows[] = [
                $message->getUid(),
                $from?->mail ?? '-',
                mb_strimwidth((string) $subject, 0, 60, '…'),
                $date,
                $hasAtt,
            ];
        }

        $this->table(
            ['UID', 'De la', 'Subiect', 'Data', 'Att'],
            $rows
        );

        $client->disconnect();

        return self::SUCCESS;
    }
}
