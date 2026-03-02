<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\EmailMessage;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

/**
 * Importă emailuri din IMAP cu memorie minimă.
 *
 * Strategia:
 *  1. Raw UID SEARCH pe server (returnează doar o listă de numere — bytes)
 *  2. Filtrăm UID-urile deja existente în DB
 *  3. Fetch complet câte un mesaj pe rând → max un email în memorie simultan
 */
class ImportEmailHistoryCommand extends Command
{
    protected $signature   = 'email:import-history {--since=2025-01-01}';
    protected $description = 'Importă istoricul emailurilor din IMAP (RAM-safe: UID search + fetch individual)';

    private const SKIP_SUFFIXES = ['trash', 'spam', 'junk', 'drafts'];

    // Format dată pentru IMAP SEARCH: "01-Jan-2025"
    private const IMAP_DATE_FORMAT = 'd-M-Y';

    private $supplierIndex;

    public function handle(): int
    {
        ini_set('memory_limit', '-1'); // un singur email mare poate depăși orice limită fixă

        $since = Carbon::parse($this->option('since'))->startOfDay();
        $today = now()->addDay()->startOfDay(); // BEFORE este exclusiv → tomorrow

        $this->info("Import emailuri: {$since->toDateString()} → " . now()->toDateString());

        $host       = AppSetting::get(AppSetting::KEY_IMAP_HOST);
        $port       = AppSetting::get(AppSetting::KEY_IMAP_PORT, '993');
        $encryption = AppSetting::get(AppSetting::KEY_IMAP_ENCRYPTION, 'ssl');
        $username   = AppSetting::get(AppSetting::KEY_IMAP_USERNAME);
        $password   = AppSetting::getEncrypted(AppSetting::KEY_IMAP_PASSWORD);

        if (blank($host) || blank($username) || blank($password)) {
            $this->error('Credențiale IMAP lipsă în setări.');
            return self::FAILURE;
        }

        $this->supplierIndex = Supplier::whereNotNull('email')->pluck('id', 'email');

        // ── Conectare + lista foldere ────────────────────────────────────────
        $client = $this->connect($host, $port, $encryption, $username, $password);
        $folders = [];
        $this->collectFolders($client->getFolders(false), $folders);
        $client->disconnect();
        unset($client);

        $this->info('Foldere de procesat: ' . count($folders));
        $this->newLine();

        $totalSaved   = 0;
        $totalSkipped = 0;

        foreach ($folders as $folderMeta) {
            $folderPath = $folderMeta->path;
            $this->line("<fg=cyan;options=bold>{$folderPath}</>");

            // Reconectăm per folder — izolat, fără state acumulat
            $client = $this->connect($host, $port, $encryption, $username, $password);
            $folder = $this->findFolder($client, $folderPath);

            if (! $folder) {
                $this->warn("  Folder negăsit, skip.");
                $client->disconnect();
                unset($client);
                continue;
            }

            // ── PASS 1: UID SEARCH — returnează doar o listă de int-uri ────
            $connection = $client->getConnection();
            $connection->selectFolder($folder->path);

            try {
                $searchResult = $connection->search([
                    'SINCE',  $since->format(self::IMAP_DATE_FORMAT),
                    'BEFORE', $today->format(self::IMAP_DATE_FORMAT),
                ], IMAP::ST_UID)->validatedData();
            } catch (\Throwable $e) {
                $this->warn("  SEARCH eroare: " . $e->getMessage());
                $client->disconnect();
                unset($client, $folder, $connection);
                continue;
            }

            $serverUids = collect($searchResult)->map(fn ($v) => (string) $v)->filter()->values();
            $this->line("  {$serverUids->count()} mesaje pe server.");

            if ($serverUids->isEmpty()) {
                $client->disconnect();
                unset($client, $folder, $connection);
                continue;
            }

            // ── Filtrăm ce avem deja în DB ──────────────────────────────────
            $existingUids = EmailMessage::where('imap_folder', $folderPath)
                ->pluck('imap_uid')
                ->flip();

            $newUids  = $serverUids->filter(fn ($uid) => ! $existingUids->has($uid))->values();
            $skipped  = $serverUids->count() - $newUids->count();

            unset($serverUids, $existingUids);
            gc_collect_cycles();

            $this->line("  <fg=yellow>{$newUids->count()} noi</> de descărcat, {$skipped} deja existente.");

            if ($newUids->isEmpty()) {
                $totalSkipped += $skipped;
                $client->disconnect();
                unset($client, $folder, $connection, $newUids);
                continue;
            }

            // ── PASS 2: fetch complet, câte un mesaj, reconectăm la fiecare 50 ─
            $bar         = $this->output->createProgressBar($newUids->count());
            $saved       = 0;
            $batchSize   = 50;
            $batches     = $newUids->chunk($batchSize);

            foreach ($batches as $batch) {
                // Reconectăm IMAP pentru fiecare batch → eliberăm starea internă
                $client->disconnect();
                unset($client, $folder);
                gc_collect_cycles();

                $client = $this->connect($host, $port, $encryption, $username, $password);
                $folder = $this->findFolder($client, $folderPath);

                if (! $folder) {
                    $this->warn("\n  Folder dispărut la reconectare, skip.");
                    break;
                }

                foreach ($batch as $uid) {
                    $bar->advance();

                    try {
                        $msg = $folder->messages()
                            ->whereUid((int) $uid)
                            ->leaveUnread()
                            ->get()
                            ->first();

                        if (! $msg) {
                            continue;
                        }

                        $from      = $msg->getFrom()->first();
                        $fromEmail = $from?->mail ?? '';

                        $toList = [];
                        foreach ($msg->getTo() as $addr) {
                            $toList[] = ['email' => $addr->mail, 'name' => $addr->personal ?? null];
                        }

                        $ccList = [];
                        foreach ($msg->getCc() as $addr) {
                            $ccList[] = ['email' => $addr->mail, 'name' => $addr->personal ?? null];
                        }

                        $attList = [];
                        foreach ($msg->getAttachments() as $att) {
                            $attList[] = [
                                'name'      => $att->getName(),
                                'size'      => $att->getSize(),
                                'mime_type' => $att->getMimeType(),
                            ];
                        }

                        EmailMessage::create([
                            'imap_uid'      => $uid,
                            'imap_folder'   => $folderPath,
                            'from_email'    => $fromEmail,
                            'from_name'     => $from?->personal ?? null,
                            'subject'       => $this->decodeMimeStr((string) ($msg->getSubject()->first() ?? '')),
                            'body_html'     => (string) ($msg->getHTMLBody() ?? ''),
                            'body_text'     => (string) ($msg->getTextBody() ?? ''),
                            'to_recipients' => $toList,
                            'cc_recipients' => $ccList ?: null,
                            'attachments'   => $attList ?: null,
                            'sent_at'       => $msg->getDate()->first(),
                            'supplier_id'   => $this->supplierIndex->get($fromEmail),
                        ]);

                        $saved++;

                    } catch (\Throwable $e) {
                        if (! str_contains($e->getMessage(), 'Duplicate entry')) {
                            $this->warn("\n  UID {$uid}: " . $e->getMessage());
                        }
                    } finally {
                        unset($msg);
                    }
                }
            }

            $bar->finish();
            $this->newLine();
            $this->line(sprintf(
                '  <fg=green>+%d salvate</>  |  %d existente  |  RAM: %dMB',
                $saved,
                $skipped,
                round(memory_get_usage(true) / 1024 / 1024)
            ));
            $this->newLine();

            $totalSaved   += $saved;
            $totalSkipped += $skipped;

            $client->disconnect();
            unset($client, $folder, $connection, $newUids);
            gc_collect_cycles();
        }

        $this->info("Import complet: <fg=green>{$totalSaved} emailuri noi</>, {$totalSkipped} deja existente.");

        return self::SUCCESS;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function connect(string $host, string $port, string $encryption, string $username, string $password)
    {
        $client = (new ClientManager())->make([
            'host'          => $host,
            'port'          => (int) $port,
            'encryption'    => $encryption,
            'validate_cert' => false,
            'username'      => $username,
            'password'      => $password,
            'protocol'      => 'imap',
        ]);
        $client->connect();
        return $client;
    }

    private function findFolder($client, string $path)
    {
        $stack = $client->getFolders(false)->toArray();
        while ($stack) {
            $f = array_shift($stack);
            if ($f->path === $path) {
                return $f;
            }
            foreach ($f->children as $child) {
                $stack[] = $child;
            }
        }
        return null;
    }

    private function collectFolders($folders, array &$result): void
    {
        foreach ($folders as $folder) {
            $pathLower = strtolower($folder->path);
            $skip      = false;
            foreach (self::SKIP_SUFFIXES as $suffix) {
                if (str_ends_with($pathLower, $suffix)) {
                    $skip = true;
                    break;
                }
            }
            if (! $skip) {
                $result[] = $folder;
            }
            if ($folder->children->count() > 0) {
                $this->collectFolders($folder->children, $result);
            }
        }
    }

    private function decodeMimeStr(string $str): string
    {
        if (! str_contains($str, '=?')) {
            return $str;
        }
        $decoded = mb_decode_mimeheader($str);
        return ($decoded !== false && $decoded !== '') ? $decoded : $str;
    }
}
