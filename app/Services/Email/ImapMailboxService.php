<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

/**
 * Serviciu IMAP pur: conectare, listare foldere și fetch emailuri.
 *
 * Nu face nicio persistare în DB și nu cunoaște modelele aplicației.
 * Responsabilitatea sa se oprește la returnarea datelor brute din server.
 *
 * Utilizare:
 *   $service = new ImapMailboxService($host, $port, $encryption, $user, $pass);
 *   $service->connect();
 *   $folders = $service->getFolders();
 *   $messages = $service->fetchSince('INBOX', Carbon::now()->subDays(3));
 *   $service->disconnect();
 */
class ImapMailboxService
{
    /** @deprecated Use config('mail.imap.skip_folders') instead */
    private const SKIP_SUFFIXES = ['trash', 'spam', 'junk', 'drafts'];
    private const IMAP_DATE_FMT = 'd-M-Y';

    private ?Client $client = null;

    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly bool   $validateCert = true,
    ) {}

    // ─────────────────────────────────────────────────────────────
    // Conexiune
    // ─────────────────────────────────────────────────────────────

    /**
     * Conectează la serverul IMAP și inițializează clientul intern.
     * Returnează $this pentru method chaining.
     *
     * @throws \Throwable dacă conexiunea eșuează
     */
    public function connect(): static
    {
        $this->client = (new ClientManager())->make([
            'host'          => $this->host,
            'port'          => $this->port,
            'encryption'    => $this->encryption,
            'validate_cert' => $this->validateCert,
            'username'      => $this->username,
            'password'      => $this->password,
            'protocol'      => 'imap',
        ]);

        $this->client->connect();

        return $this;
    }

    /**
     * Deconectează clientul IMAP și eliberează resursele.
     */
    public function disconnect(): void
    {
        if ($this->client !== null) {
            try {
                $this->client->disconnect();
            } catch (\Throwable) {
                // Ignorăm erori la deconectare
            }
            $this->client = null;
        }
    }

    /**
     * Reconectează — util pentru reconnect per folder (eliberează starea IMAP internă).
     */
    public function reconnect(): static
    {
        $this->disconnect();
        gc_collect_cycles();

        return $this->connect();
    }

    // ─────────────────────────────────────────────────────────────
    // Foldere
    // ─────────────────────────────────────────────────────────────

    /**
     * Returnează lista path-urilor de foldere disponibile, excluzând
     * folderele de tip trash/spam/junk/drafts.
     *
     * @return string[]
     */
    public function getFolders(): array
    {
        $this->assertConnected();

        $result = [];
        $this->collectFolders($this->client->getFolders(false), $result);

        return $result;
    }

    private function collectFolders(mixed $folders, array &$result): void
    {
        foreach ($folders as $folder) {
            $pathLower = strtolower($folder->path);
            $skip      = false;

            foreach (config('mail.imap.skip_folders', self::SKIP_SUFFIXES) as $suffix) {
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

    // ─────────────────────────────────────────────────────────────
    // Fetch emailuri
    // ─────────────────────────────────────────────────────────────

    /**
     * Returnează lista de UID-uri din folderul dat care au SINCE data specificată.
     *
     * Folosim raw connection.search() cu SINCE — evităm ->all() care face
     * UID SEARCH fără parametri și crăpă pe unele servere IMAP.
     *
     * @return string[] — array de UID-uri ca string-uri
     */
    public function searchUidsSince(string $folder, \DateTimeInterface $since): array
    {
        $this->assertConnected();

        $sinceStr   = $since->format(self::IMAP_DATE_FMT);
        $connection = $this->client->getConnection();
        $connection->selectFolder($folder);

        $result = $connection->search(
            ['SINCE', $sinceStr],
            IMAP::ST_UID
        )->validatedData();

        return collect($result)
            ->map(fn ($v) => (string) $v)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Fetch un singur email după UID dintr-un folder dat.
     * Returnează datele parsate sau null dacă UID-ul nu există.
     *
     * Structura returnată:
     * [
     *   'uid'         => string,
     *   'folder'      => string,
     *   'from_email'  => string,
     *   'from_name'   => string|null,
     *   'subject'     => string,
     *   'body_html'   => string,
     *   'body_text'   => string,
     *   'sent_at'     => mixed,   // Webklex Carbon/DateTime
     *   'to'          => [['email'=>string, 'name'=>string|null], ...],
     *   'cc'          => [['email'=>string, 'name'=>string|null], ...],
     *   'attachments' => [['name'=>string, 'size'=>int, 'mime_type'=>string], ...],
     * ]
     *
     * @return array<string, mixed>|null
     */
    public function fetchMessage(string $folder, string $uid): ?array
    {
        $this->assertConnected();

        $folderObj = $this->client->getFolder($folder);
        if (! $folderObj) {
            Log::warning("ImapMailboxService: folder '{$folder}' nu s-a găsit.");
            return null;
        }

        $msg = $folderObj->messages()
            ->whereUid((int) $uid)
            ->leaveUnread()
            ->get()
            ->first();

        if (! $msg) {
            return null;
        }

        return $this->parseMessage($msg, $folder, $uid);
    }

    /**
     * Fetch și parsare a mai multor UID-uri dintr-un folder.
     * Returnează doar mesajele găsite; UID-urile lipsă sunt silențios omise.
     *
     * @param  string[] $uids
     * @return array<array<string, mixed>>
     */
    public function fetchMessages(string $folder, array $uids): array
    {
        $results = [];

        foreach ($uids as $uid) {
            try {
                $parsed = $this->fetchMessage($folder, $uid);
                if ($parsed !== null) {
                    $results[] = $parsed;
                }
            } catch (\Throwable $e) {
                if (! str_contains($e->getMessage(), 'Duplicate entry')) {
                    Log::warning("ImapMailboxService: UID {$uid} folder '{$folder}': " . $e->getMessage());
                }
            }
        }

        return $results;
    }

    /**
     * Fetch UID-uri noi dintr-un folder SINCE data dată, returnând datele parsate
     * pentru UID-urile care nu sunt în $existingUids.
     *
     * Combină searchUidsSince() + filtrare + fetchMessages() într-un singur apel.
     * Convenabil pentru FetchEmailsJob care trebuie să filtreze UID-urile deja în DB.
     *
     * @param  string[] $existingUids — UID-urile deja salvate în DB pentru acest folder
     * @return array{uids: string[], messages: array<array<string, mixed>>}
     */
    public function fetchSince(string $folder, \DateTimeInterface $since, array $existingUids = []): array
    {
        $allUids = $this->searchUidsSince($folder, $since);

        if (empty($allUids)) {
            return ['uids' => [], 'messages' => []];
        }

        $existingFlip = array_flip($existingUids);
        $newUids      = array_values(array_filter($allUids, fn ($uid) => ! isset($existingFlip[$uid])));

        if (empty($newUids)) {
            return ['uids' => $allUids, 'messages' => []];
        }

        $messages = $this->fetchMessages($folder, $newUids);

        return ['uids' => $allUids, 'messages' => $messages];
    }

    // ─────────────────────────────────────────────────────────────
    // Parsare mesaj
    // ─────────────────────────────────────────────────────────────

    /**
     * Parsează un mesaj Webklex într-un array normalizat.
     *
     * @return array<string, mixed>
     */
    private function parseMessage(mixed $msg, string $folder, string $uid): array
    {
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

        return [
            'uid'         => $uid,
            'folder'      => $folder,
            'from_email'  => $fromEmail,
            'from_name'   => $fromName,
            'subject'     => $subject,
            'body_html'   => (string) ($msg->getHTMLBody() ?? ''),
            'body_text'   => (string) ($msg->getTextBody() ?? ''),
            'sent_at'     => $sentAt,
            'to'          => $toList,
            'cc'          => $ccList,
            'attachments' => $attList,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Decodifică MIME encoded-word (=?UTF-8?Q?...?= sau =?ISO-8859-2?B?...?=)
     * folosit frecvent în subiectele emailurilor.
     */
    private function decodeMimeStr(string $str): string
    {
        if (! str_contains($str, '=?')) {
            return $str;
        }

        $decoded = mb_decode_mimeheader($str);

        return ($decoded !== false && $decoded !== '') ? $decoded : $str;
    }

    /**
     * Factory method pentru construire din AppSetting (convenabil în Job-uri).
     *
     * Exemplu:
     *   $service = ImapMailboxService::fromAppSettings();
     *   if ($service === null) { ... credențiale lipsă ... }
     */
    public static function fromAppSettings(): ?static
    {
        // Import tardiv pentru a evita cuplarea strânsă cu AppSetting în service
        $appSetting = app(\App\Models\AppSetting::class);

        $host       = \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_IMAP_HOST);
        $port       = (int) \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_IMAP_PORT, '993');
        $encryption = \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_IMAP_ENCRYPTION, 'ssl');
        $username   = \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_IMAP_USERNAME);
        $password   = \App\Models\AppSetting::getEncrypted(\App\Models\AppSetting::KEY_IMAP_PASSWORD);

        if (blank($host) || blank($username) || blank($password)) {
            return null;
        }

        return new static(
            host:         $host,
            port:         $port,
            encryption:   $encryption,
            username:     $username,
            password:     $password,
            validateCert: ! app()->isLocal(),
        );
    }

    /**
     * @throws \RuntimeException dacă clientul nu este conectat
     */
    private function assertConnected(): void
    {
        if ($this->client === null) {
            throw new \RuntimeException('ImapMailboxService: apelați connect() înainte de a folosi serviciul.');
        }
    }
}
