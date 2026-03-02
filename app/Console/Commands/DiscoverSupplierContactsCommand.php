<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Supplier;
use App\Models\SupplierContact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Parcurge istoricul de emailuri și creează/actualizează automat
 * contactele furnizorilor descoperite din câmpul from_email/from_name.
 *
 * Logică:
 *  1. Emailuri cu supplier_id → contact sigur la acel furnizor
 *  2. Emailuri fără supplier_id, dar domeniu = domeniu furnizor → contact probabil
 *     (creat cu source='domain_match', necesită confirmare)
 *  3. Actualizează statistici (email_count, first_seen_at, last_seen_at) pentru toate
 */
class DiscoverSupplierContactsCommand extends Command
{
    protected $signature   = 'supplier:discover-contacts {--dry-run : Afișează ce s-ar crea fără a salva}';
    protected $description = 'Descoperă contacte furnizori din istoricul emailurilor';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Descoperire contacte din emailuri...');
        $this->newLine();

        // ── PASS 1: emailuri cu supplier_id explicit ─────────────────────────
        $this->line('<fg=cyan>Pass 1: emailuri cu furnizor asociat</>');

        $rows = EmailMessage::whereNotNull('supplier_id')
            ->whereNotNull('from_email')
            ->select('supplier_id', 'from_email', DB::raw('MAX(from_name) as from_name'),
                     DB::raw('COUNT(*) as cnt'),
                     DB::raw('MIN(sent_at) as first_seen'),
                     DB::raw('MAX(sent_at) as last_seen'))
            ->groupBy('supplier_id', 'from_email')
            ->orderBy('supplier_id')
            ->get();

        $created  = 0;
        $updated  = 0;

        foreach ($rows as $row) {
            $contact = SupplierContact::where('supplier_id', $row->supplier_id)
                ->where('email', $row->from_email)
                ->first();

            if (! $contact) {
                if (! $dryRun) {
                    $contact = SupplierContact::create([
                        'supplier_id'   => $row->supplier_id,
                        'email'         => $row->from_email,
                        'name'          => $row->from_name ?? $row->from_email,
                        'source'        => 'email_discovery',
                        'is_primary'    => false,
                        'email_count'   => $row->cnt,
                        'first_seen_at' => $row->first_seen,
                        'last_seen_at'  => $row->last_seen,
                    ]);
                }
                $this->line("  <fg=green>+</> {$row->from_email} → furnizor #{$row->supplier_id} ({$row->cnt} emailuri)");
                $created++;
            } else {
                if (! $dryRun) {
                    $contact->update([
                        'email_count'   => $row->cnt,
                        'first_seen_at' => $row->first_seen,
                        'last_seen_at'  => $row->last_seen,
                        'name'          => $contact->name ?: ($row->from_name ?? $row->from_email),
                    ]);
                }
                $updated++;
            }
        }

        $this->line("  Creat: <fg=green>{$created}</>, actualizat: {$updated}");
        $this->newLine();

        // ── PASS 2: domain matching pentru emailuri fără furnizor ─────────────
        $this->line('<fg=cyan>Pass 2: potrivire domeniu pentru emailuri neasociate</>');

        // Index: domeniu → supplier_id (din suppliers.email)
        $domainIndex = Supplier::whereNotNull('email')
            ->where('is_active', true)
            ->get(['id', 'email'])
            ->mapWithKeys(function ($s) {
                $domain = strtolower(substr(strrchr($s->email, '@'), 1));
                return $domain ? [$domain => $s->id] : [];
            });

        $unassociated = EmailMessage::whereNull('supplier_id')
            ->whereNotNull('from_email')
            ->where('from_email', 'not like', '%noreply%')
            ->where('from_email', 'not like', '%no-reply%')
            ->where('from_email', 'not like', '%donotreply%')
            ->select('from_email', DB::raw('MAX(from_name) as from_name'),
                     DB::raw('COUNT(*) as cnt'),
                     DB::raw('MIN(sent_at) as first_seen'),
                     DB::raw('MAX(sent_at) as last_seen'))
            ->groupBy('from_email')
            ->having('cnt', '>=', 2) // minim 2 emailuri ca să nu fie spam izolat
            ->get();

        $domainMatches = 0;

        foreach ($unassociated as $row) {
            $domain     = strtolower(substr(strrchr($row->from_email, '@'), 1));
            $supplierId = $domainIndex->get($domain);

            if (! $supplierId) {
                continue;
            }

            // Verificăm să nu existe deja
            $exists = SupplierContact::where('supplier_id', $supplierId)
                ->where('email', $row->from_email)
                ->exists();

            if (! $exists) {
                if (! $dryRun) {
                    SupplierContact::create([
                        'supplier_id'   => $supplierId,
                        'email'         => $row->from_email,
                        'name'          => $row->from_name ?? $row->from_email,
                        'source'        => 'domain_match',
                        'is_primary'    => false,
                        'email_count'   => $row->cnt,
                        'first_seen_at' => $row->first_seen,
                        'last_seen_at'  => $row->last_seen,
                    ]);
                }
                $this->line("  <fg=yellow>~</> {$row->from_email} → furnizor #{$supplierId} (domain match, {$row->cnt} emailuri)");
                $domainMatches++;
            }
        }

        $this->line("  Potriviri domeniu: <fg=yellow>{$domainMatches}</>");
        $this->newLine();

        // ── PASS 3: backfill supplier_contact_id pe email_messages ──────────
        if (! $dryRun) {
            $this->line('<fg=cyan>Pass 3: backfill supplier_contact_id pe emailuri existente</>');

            $contactIndex = SupplierContact::whereNotNull('email')
                ->pluck('id', 'email');

            $backfilled = 0;
            EmailMessage::whereNull('supplier_contact_id')
                ->whereNotNull('from_email')
                ->chunkById(500, function ($emails) use ($contactIndex, &$backfilled) {
                    foreach ($emails as $email) {
                        $contactId = $contactIndex->get($email->from_email);
                        if ($contactId) {
                            $email->update(['supplier_contact_id' => $contactId]);
                            $backfilled++;
                        }
                    }
                });

            $this->line("  Emailuri legate de contact: <fg=green>{$backfilled}</>");
            $this->newLine();
        }

        if ($dryRun) {
            $this->warn('DRY RUN — nimic nu a fost salvat.');
        } else {
            $total = SupplierContact::where('source', '!=', 'manual')->count();
            $this->info("Gata. Total contacte descoperite automat: {$total}");
        }

        return self::SUCCESS;
    }
}
