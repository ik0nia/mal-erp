<?php

namespace App\Console\Commands;

use App\Models\WinmentorArticleSnapshot;
use App\Models\WooProduct;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Detectează articolele ȘTERSE din WinMentor (GetStergeriProduse — READ-ONLY).
 *
 * Ștergerile vin doar cu codul INTERN WinMentor, de aceea comanda întreține și
 * coloana snapshot.cod_intern (backfill din listarea /api/articole) — fără ea, un
 * articol deja șters nu mai poate fi identificat (nu mai apare în nomenclator).
 *
 * Nu scrie NIMIC în WinMentor; scrie doar în ERP (snapshot + log + email alertă).
 */
class CheckWinmentorDeletedArticlesCommand extends Command
{
    protected $signature = 'winmentor:check-articole-sterse
                            {--since= : Suprascrie data de la care se caută (d.m.Y sau Y-m-d)}
                            {--no-backfill : Sare peste backfill-ul cod_intern}';

    protected $description = 'Detectează articolele șterse din WinMentor și le leagă de produsele ERP (read-only pe Mentor)';

    private const NOTIFY_EMAILS = ['codrut@ikonia.ro'];
    private const LAST_CHECK_CACHE_KEY = 'winmentor_stergeri_last_check';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        if (! $bridge->isReachable()) {
            $this->error('MentorAPI nu este accesibil.');
            return self::FAILURE;
        }

        $bridge->selectFirma();

        // ── 1. Backfill cod_intern pe snapshot (din listarea de articole) ────────
        if (! $this->option('no-backfill')) {
            $this->backfillCodIntern($bridge);
        }

        // ── 2. Ștergeri de la ultima verificare ──────────────────────────────────
        $since = $this->resolveSince();
        $this->info('Caut ștergeri din: ' . $since->format('d.m.Y H:i:s'));

        $deleted = $bridge->getStergeriProduse($since->format('d.m.Y H:i:s'));
        if ($deleted === null) {
            $this->error('GetStergeriProduse a eșuat — nu actualizez reperul de timp.');
            return self::FAILURE;
        }

        if (empty($deleted)) {
            $this->info('Nicio ștergere.');
            Cache::forever(self::LAST_CHECK_CACHE_KEY, now()->toDateTimeString());
            return self::SUCCESS;
        }

        // ── 3. Rezolvare cod intern → snapshot → produs ERP ─────────────────────
        $lines = [];
        foreach ($deleted as $d) {
            $ci   = trim((string) ($d['codInternWinMentor'] ?? ''));
            $when = trim((string) ($d['dataOraStergerii'] ?? ''));
            $snap = $ci !== '' ? WinmentorArticleSnapshot::where('cod_intern', $ci)->first() : null;

            $desc = "cod intern {$ci} (șters la {$when})";
            if ($snap) {
                $desc .= " = [{$snap->denumire}] cod extern {$snap->cod_extern}";
                $wp = WooProduct::where('sku', $snap->cod_extern)
                    ->orWhere('winmentor_name', $snap->denumire)
                    ->first(['id', 'sku', 'name', 'status', 'woo_id']);
                if ($wp) {
                    $desc .= " → produs ERP #{$wp->id} [{$wp->name}] status={$wp->status}"
                        . ($wp->woo_id ? ' PUBLICAT PE SITE (woo_id ' . $wp->woo_id . ')' : '');
                }
            } else {
                $desc .= ' — neidentificabil (șters înainte să reținem codul intern)';
            }

            $lines[] = $desc;
            $this->warn('ȘTERS: ' . $desc);
        }

        Log::channel('daily')->warning('[WinMentor] Articole șterse din nomenclator: ' . count($lines), ['articole' => $lines]);

        try {
            Mail::raw(
                "Articole șterse din nomenclatorul WinMentor (detectate " . now()->format('d.m.Y H:i') . "):\n\n- "
                . implode("\n- ", $lines)
                . "\n\nDacă un produs e publicat pe site, verificați dacă trebuie retras sau remapat.",
                function ($m) {
                    $m->to(self::NOTIFY_EMAILS)->subject('[ERP] Articole șterse din WinMentor: ' . now()->format('d.m.Y'));
                }
            );
        } catch (\Throwable $e) {
            $this->warn('Email-ul de alertă nu a putut fi trimis: ' . $e->getMessage());
        }

        Cache::forever(self::LAST_CHECK_CACHE_KEY, now()->toDateTimeString());

        return self::SUCCESS;
    }

    private function resolveSince(): \Carbon\Carbon
    {
        if ($raw = $this->option('since')) {
            foreach (['d.m.Y', 'Y-m-d', 'd.m.Y H:i:s'] as $fmt) {
                try {
                    return \Carbon\Carbon::createFromFormat($fmt, $raw)->startOfDay();
                } catch (\Throwable) {
                }
            }
            $this->warn("Format --since nerecunoscut [{$raw}] — folosesc reperul salvat.");
        }

        $saved = Cache::get(self::LAST_CHECK_CACHE_KEY);

        return $saved ? \Carbon\Carbon::parse($saved) : now()->subDays(90);
    }

    /** Completează snapshot.cod_intern din listarea /api/articole (doar rândurile fără el). */
    private function backfillCodIntern(WinmentorBridgeClient $bridge): void
    {
        $missing = WinmentorArticleSnapshot::whereNull('cod_intern')->count();
        if ($missing === 0) {
            return;
        }

        $map = $bridge->fetchArticoleCodInternMap(); // [cod_extern => cod_intern]
        if (empty($map)) {
            $this->warn('Backfill cod_intern: listarea de articole a venit goală — sar peste.');
            return;
        }

        $updated = 0;
        WinmentorArticleSnapshot::whereNull('cod_intern')
            ->chunkById(1000, function ($rows) use ($map, &$updated) {
                foreach ($rows as $row) {
                    $ci = $map[$row->cod_extern] ?? null;
                    if ($ci) {
                        DB::table('winmentor_articles_snapshot')->where('id', $row->id)->update(['cod_intern' => $ci]);
                        $updated++;
                    }
                }
            });

        $this->info("Backfill cod_intern: {$updated} completate (din {$missing} lipsă).");
    }
}
