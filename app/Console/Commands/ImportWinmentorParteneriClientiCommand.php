<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importă partenerii REALI din WinMentor (clase *SC, *PF, SITE, SRL, AS) ca
 * clienți în modulul ERP, cu legătură stabilă pe winmentor_partner_id (wm_id).
 * Rulat idempotent: leagă întâi clienții existenți (după CUI sau nume exact),
 * apoi creează ce lipsește. Clasele interne/tehnice (M, ZZZ, Special...) NU
 * devin clienți.
 */
class ImportWinmentorParteneriClientiCommand extends Command
{
    protected $signature = 'winmentor:import-parteneri-clienti {--dry-run : Doar raportează, fără scriere}';

    protected $description = 'Importă partenerii reali WinMentor ca clienți ERP (legați prin winmentor_partner_id)';

    private const CLASE_REALE = ['*SC', '*PF', 'SITE', 'SRL', 'AS', 'PF INACTIVI'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $parteneri = DB::table('winmentor_parteneri')
            ->whereIn(DB::raw('TRIM(clasa)'), self::CLASE_REALE)
            ->get(['wm_id', 'denumire', 'cod_fiscal', 'localitate', 'adresa', 'telefon', 'clasa']);

        $this->info('Parteneri reali în WinMentor: '.$parteneri->count());

        // Indexuri pentru legare: după partner_id existent, CUI normalizat, nume exact
        $byPartnerId = Customer::whereNotNull('winmentor_partner_id')->pluck('id', 'winmentor_partner_id');
        $byCui       = Customer::whereNotNull('cui')->get(['id', 'cui'])
            ->keyBy(fn ($c) => preg_replace('/[^0-9]/', '', (string) $c->cui))
            ->filter(fn ($c, $k) => $k !== '');
        // Nume normalizat: doar litere/cifre, uppercase — prinde „IONUT-RAZVAN" vs „IONUT RAZVAN"
        $normalize = fn (string $n) => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($n));
        $byName    = Customer::pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [$normalize($name) => $id]);

        $legati = $creati = $existenti = 0;

        foreach ($parteneri as $p) {
            $wmId = trim((string) $p->wm_id);
            if ($wmId === '' || $byPartnerId->has($wmId)) {
                $existenti++;
                continue;
            }

            $cui = preg_replace('/[^0-9]/', '', (string) $p->cod_fiscal);
            // garda: telefoane băgate la cod fiscal în Mentor (07xxxxxxxx) nu sunt CUI
            $cui = ($cui !== '' && $cui !== '0' && strlen($cui) > 3 && ! preg_match('/^07\\d{8}$/', $cui)) ? $cui : null;
            $nume   = trim((string) $p->denumire);
            $target = null;

            if ($cui && $byCui->has($cui)) {
                $target = $byCui->get($cui)->id;
            } elseif ($nume !== '' && $byName->has($normalize($nume))) {
                $target = $byName->get($normalize($nume));
            }

            if ($target) {
                if (! $dry) {
                    Customer::where('id', $target)->whereNull('winmentor_partner_id')
                        ->update(['winmentor_partner_id' => $wmId]);
                }
                $legati++;
                continue;
            }

            if (! $dry) {
                $customer = Customer::create([
                    'location_id'          => 1, // Malinco Sântandrei — firma WinMentor
                    'type'                 => str_starts_with(trim((string) $p->clasa), '*PF') || trim((string) $p->clasa) === 'PF INACTIVI'
                        ? 'individual' : 'company',
                    'name'                 => $nume ?: ('Partener '.$wmId),
                    'cui'                  => $cui,
                    'winmentor_id'         => $cui, // convenția existentă (CUI) — păstrată pentru lookup-urile vechi
                    'winmentor_partner_id' => $wmId,
                    'phone'                => mb_substr(trim((string) $p->telefon, " ~\t"), 0, 50) ?: null,
                    'address'              => mb_substr(trim((string) $p->adresa, " ~\t"), 0, 255) ?: null,
                    'city'                 => mb_substr(trim((string) $p->localitate), 0, 100) ?: null,
                    'is_active'            => trim((string) $p->clasa) !== 'PF INACTIVI',
                ]);
                $byPartnerId->put($wmId, $customer->id);
            }
            $creati++;
        }

        $this->info(($dry ? '[DRY-RUN] ' : '')."Legați de clienți existenți: {$legati} | Creați: {$creati} | Aveau deja legătura: {$existenti}");

        return self::SUCCESS;
    }
}
