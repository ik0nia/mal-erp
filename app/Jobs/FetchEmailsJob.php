<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\EmailMessage;
use App\Models\Supplier;
use App\Models\SupplierContact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

class FetchEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 1; // Nu re-încearcă — dacă IMAP e down, așteptăm 5 min

    private const SKIP_SUFFIXES  = ['trash', 'spam', 'junk', 'drafts'];
    private const IMAP_DATE_FMT  = 'd-M-Y';

    /**
     * Căutăm emailuri SINCE N zile în urmă.
     * 3 zile = toleranță la scheduler down până la 3 zile fără pierderi.
     * Eficient: UID SEARCH SINCE <date> — nu face FETCH ALL.
     */
    private const SINCE_DAYS = 3;

    public function handle(): void
    {
        $host       = AppSetting::get(AppSetting::KEY_IMAP_HOST);
        $port       = AppSetting::get(AppSetting::KEY_IMAP_PORT, '993');
        $encryption = AppSetting::get(AppSetting::KEY_IMAP_ENCRYPTION, 'ssl');
        $username   = AppSetting::get(AppSetting::KEY_IMAP_USERNAME);
        $password   = AppSetting::getEncrypted(AppSetting::KEY_IMAP_PASSWORD);

        if (blank($host) || blank($username) || blank($password)) {
            Log::info('FetchEmailsJob: credențiale IMAP lipsă, skip.');
            return;
        }

        // Index dublu pentru auto-asociere furnizor:
        //  1. suppliers.email → supplier_id (match exact)
        //  2. supplier_contacts.email → supplier_id (contact cunoscut)
        $supplierByEmail = Supplier::whereNotNull('email')
            ->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [strtolower($email) => $id]);

        $supplierByContact = SupplierContact::whereNotNull('email')
            ->whereNotNull('supplier_id')
            ->pluck('supplier_id', 'email')
            ->mapWithKeys(fn ($id, $email) => [strtolower($email) => $id]);

        // Index contact: email → contact_id
        $contactByEmail = SupplierContact::whereNotNull('email')
            ->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [strtolower($email) => $id]);

        $since = Carbon::now()->subDays(self::SINCE_DAYS)->format(self::IMAP_DATE_FMT);

        $client     = $this->makeClient($host, (int) $port, $encryption, $username, $password);
        $folders    = $this->getAllFolders($client);
        $totalSaved = 0;

        foreach ($folders as $folderPath) {
            // Reconnect per folder — eliberăm starea internă IMAP
            try { $client->disconnect(); } catch (\Throwable) {}
            unset($client);
            gc_collect_cycles();

            $client = $this->makeClient($host, (int) $port, $encryption, $username, $password);

            $saved = $this->processFolder(
                $client, $folderPath, $since,
                $supplierByEmail, $supplierByContact, $contactByEmail
            );

            $totalSaved += $saved;
        }

        try { $client->disconnect(); } catch (\Throwable) {}

        if ($totalSaved > 0) {
            Log::info("FetchEmailsJob: {$totalSaved} emailuri noi importate.");
        }
    }

    private function makeClient(string $host, int $port, string $encryption, string $username, string $password): Client
    {
        $client = (new ClientManager())->make([
            'host'          => $host,
            'port'          => $port,
            'encryption'    => $encryption,
            'validate_cert' => false,
            'username'      => $username,
            'password'      => $password,
            'protocol'      => 'imap',
        ]);
        $client->connect();
        return $client;
    }

    private function getAllFolders(Client $client): array
    {
        $result = [];
        $this->collectFolders($client->getFolders(false), $result);
        return $result;
    }

    private function collectFolders($folders, array &$result): void
    {
        foreach ($folders as $folder) {
            $pathLower = strtolower($folder->path);
            $skip = false;

            foreach (self::SKIP_SUFFIXES as $suffix) {
                if (str_ends_with($pathLower, $suffix)) {
                    $skip = true;
                    break;
                }
            }

            if (! $skip) {
                $result[] = $folder->path;
            }

            if ($folder->children->count() > 0) {
                $this->collectFolders($folder->children, $result);
            }
        }
    }

    private function processFolder(
        Client $client,
        string $folderPath,
        string $since,
        $supplierByEmail,
        $supplierByContact,
        $contactByEmail
    ): int {
        // ── PASS 1: UID SEARCH SINCE <data> — returnează doar UID-uri ──────────
        // Folosim raw connection.search() — același pattern ca ImportEmailHistoryCommand.
        // Evităm ->all() care face UID SEARCH fără parametri și crăpă pe unele servere.
        try {
            $connection = $client->getConnection();
            $connection->selectFolder($folderPath);

            $searchResult = $connection->search(
                ['SINCE', $since],
                IMAP::ST_UID
            )->validatedData();

        } catch (\Throwable $e) {
            Log::warning("FetchEmailsJob: folder '{$folderPath}' SEARCH eroare: " . $e->getMessage());
            return 0;
        }

        $remoteUids = collect($searchResult)
            ->map(fn ($v) => (string) $v)
            ->filter()
            ->values();

        if ($remoteUids->isEmpty()) {
            return 0;
        }

        // ── Filtrăm UID-urile deja în DB ────────────────────────────────────────
        $existingUids = EmailMessage::where('imap_folder', $folderPath)
            ->whereIn('imap_uid', $remoteUids->all())
            ->pluck('imap_uid')
            ->flip();

        $newUids = $remoteUids->filter(fn ($uid) => ! $existingUids->has($uid))->values();

        if ($newUids->isEmpty()) {
            return 0;
        }

        // ── PASS 2: Fetch individual per UID ────────────────────────────────────
        $folder = $client->getFolder($folderPath);
        if (! $folder) {
            Log::warning("FetchEmailsJob: folder '{$folderPath}' nu s-a găsit.");
            return 0;
        }

        $saved = 0;

        foreach ($newUids as $uid) {
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
                $fromEmail = strtolower(trim($from?->mail ?? ''));
                $fromName  = $from?->personal ?? null;
                $subject   = $this->decodeMimeStr((string) ($msg->getSubject()->first() ?? ''));
                $sentAt    = $msg->getDate()->first();

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

                // Rezolvare furnizor: suppliers.email → supplier_contacts.email
                $supplierId = $supplierByEmail->get($fromEmail)
                    ?? $supplierByContact->get($fromEmail);

                $contactId = $contactByEmail->get($fromEmail);

                $emailRecord = EmailMessage::create([
                    'imap_uid'            => $uid,
                    'imap_folder'         => $folderPath,
                    'from_email'          => $fromEmail,
                    'from_name'           => $fromName,
                    'subject'             => $subject,
                    'body_html'           => (string) ($msg->getHTMLBody() ?? ''),
                    'body_text'           => (string) ($msg->getTextBody() ?? ''),
                    'to_recipients'       => $toList,
                    'cc_recipients'       => $ccList ?: null,
                    'attachments'         => $attList ?: null,
                    'sent_at'             => $sentAt,
                    'supplier_id'         => $supplierId,
                    'supplier_contact_id' => $contactId,
                ]);

                // Dispatch AI pentru emailuri noi din INBOX/Sent cu furnizor cunoscut
                if (in_array($folderPath, ['INBOX', 'INBOX.Sent']) && $supplierId) {
                    ProcessEmailAIJob::dispatch($emailRecord->id)->delay(now()->addSeconds(15));
                }

                $saved++;

            } catch (\Throwable $e) {
                if (! str_contains($e->getMessage(), 'Duplicate entry')) {
                    Log::warning("FetchEmailsJob: UID {$uid} folder '{$folderPath}': " . $e->getMessage());
                }
            } finally {
                unset($msg);
            }
        }

        if ($saved > 0) {
            Log::info("FetchEmailsJob: '{$folderPath}' → {$saved} noi.");
        }

        return $saved;
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
