<?php

namespace App\Console\Commands;

use App\Models\CategoryReviewProposal;
use App\Models\IntegrationConnection;
use App\Models\WooCategory;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReprocessRejectedProposalsCommand extends Command
{
    protected $signature = 'categories:reprocess-rejected';
    protected $description = 'Creează categorii noi și reintroduce propunerile respinse cu asocieri corecte';

    // Mapping: product_name (lowercase, fără diacritice) => local woo_categories.id
    // Setat după ce creăm categoriile noi
    private array $nameMap = [];

    public function handle(): int
    {
        // ── Step 1: Creează categoriile noi în WooCommerce ──────────────────────
        $this->info('Creez categorii noi în WooCommerce...');

        $connection = IntegrationConnection::where('provider', 'woocommerce')->first();
        if (! $connection) {
            $this->error('Nu există conexiune WooCommerce.');
            return 1;
        }
        $client = new WooClient($connection);

        $feronerie       = WooCategory::where('woo_id', 106)->first();  // Feronerie
        $materialeConst  = WooCategory::where('woo_id', 345)->first();  // Materiale de construcții
        $consumabileParent = WooCategory::where('woo_id', 109)->first(); // Consumabile (sub Scule)

        // Creează sau reutilizează categoriile noi
        $numereCat  = $this->ensureCategory($client, 'Numere de casă',                'numere-de-casa',            $feronerie);
        $cofrajCat  = $this->ensureCategory($client, 'Accesorii cofraj',              'accesorii-cofraj',          $materialeConst);
        $autoCat    = $this->ensureCategory($client, 'Auto și întreținere utilaje',   'auto-si-intretinere-utilaje', $consumabileParent);

        if (! $numereCat || ! $cofrajCat || ! $autoCat) {
            $this->error('Nu am putut crea categoriile noi.');
            return 1;
        }

        $this->info("  ✓ Numere de casă       (local id={$numereCat->id}, woo_id={$numereCat->woo_id})");
        $this->info("  ✓ Accesorii cofraj     (local id={$cofrajCat->id}, woo_id={$cofrajCat->woo_id})");
        $this->info("  ✓ Auto și întreținere  (local id={$autoCat->id}, woo_id={$autoCat->woo_id})");

        // ── Step 2: Construiește maparea produse → categorii ─────────────────────
        $this->buildNameMap($numereCat->id, $cofrajCat->id, $autoCat->id);

        // ── Step 3: Inserează propuneri pentru produsele respinse ────────────────
        $this->info('Procesez propunerile respinse...');
        $rejected = DB::table('category_review_proposals')
            ->where('status', 'rejected')
            ->get(['id', 'woo_product_id', 'product_name', 'current_cats']);

        $inserted = 0;
        $skipped  = 0;
        foreach ($rejected as $r) {
            $result = $this->insertProposal($r->woo_product_id, $r->product_name, $r->current_cats);
            $result ? $inserted++ : $skipped++;
        }
        $this->info("  Respinse reprocesate: {$inserted} inserate, {$skipped} ignorate (fără mapare sau duplicat).");

        // ── Step 4: Caută alte produse din catalog care se potrivesc ─────────────
        $this->info('Caut produse nepropuse care se potrivesc în categoriile noi...');
        $extra = $this->findExtraProducts($numereCat->id, $cofrajCat->id, $autoCat->id);
        $this->info("  Produse extra adăugate: {$extra}");

        $total = DB::table('category_review_proposals')->where('status', 'pending')->count();
        $this->info("Total propuneri pending acum: {$total}");

        return 0;
    }

    // ────────────────────────────────────────────────────────────────────────────
    private function ensureCategory(WooClient $client, string $name, string $slug, WooCategory $parent): ?WooCategory
    {
        // Verifică dacă există deja local
        $existing = WooCategory::where('slug', $slug)->first();
        if ($existing) {
            return $existing;
        }

        try {
            $data = $client->createCategory($name, $slug, $parent->woo_id);
            if (empty($data['id'])) {
                return null;
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Dacă există deja în WooCommerce, extrage id-ul din răspuns
            $body = $e->response?->json() ?? [];
            if (($body['code'] ?? '') === 'term_exists' && ! empty($body['data']['term_id'])) {
                $data = ['id' => $body['data']['term_id']];
            } else {
                $this->error("Eroare creare categorie '{$name}': " . $e->getMessage());
                return null;
            }
        } catch (\Throwable $e) {
            $this->error("Eroare creare categorie '{$name}': " . $e->getMessage());
            return null;
        }

        return WooCategory::create([
            'woo_id'        => $data['id'],
            'name'          => $name,
            'slug'          => $slug,
            'parent_id'     => $parent->id,
            'connection_id' => $parent->connection_id,
            'data'          => '{}',
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────────
    private function buildNameMap(int $numereCatId, int $cofrajCatId, int $autoCatId): void
    {
        // Categorii existente (local id)
        $catId = fn(int $wooId) => WooCategory::where('woo_id', $wooId)->value('id');

        $vopseleSpray    = $catId(215);  // Vopsele și emailuri
        $amorseGrunduri  = $catId(241);  // Amorse și grunduri
        $lacuriBaituri   = $catId(264);  // Lacuri și baițuri
        $sprayLubrif     = $catId(433);  // Spray-uri și lubrifianți
        $riflaje         = $catId(1146); // Riflaje de interior
        $burlane         = $catId(360);  // Burlane
        $surubTigla      = $catId(362);  // Șuruburi țiglă metalică
        $sindrilaId      = $catId(62);   // Șindrilă (Învelitoare)
        $aparataje       = $catId(88);   // Aparataje
        $copexPat        = $catId(416);  // Copexuri și pat cablu
        $broasteManere   = $catId(158);  // Broaște și mânere
        $console         = $catId(163);  // Console
        $carlige         = $catId(160);  // Cârlige, carabine și lanțuri
        $canalizare      = $catId(78);   // Canalizare
        $robineti        = $catId(82);   // Robineți
        $antiInghet      = $catId(435);  // Soluții antiîngheț
        $accInstTerm     = $catId(131);  // Accesorii instalații termice
        $garduri         = $catId(96);   // Garduri
        $antidaunatori   = $catId(434);  // Antidăunători
        $lambriuri       = $catId(227);  // Lambriuri și glafuri
        $profileDecor    = $catId(70);   // Profile decorative
        $foliiParchet    = $catId(421);  // Folii pentru parchet
        $parchetTrepte   = $catId(245);  // Sistem parchet trepte
        $uzCasnic        = $catId(436);  // Uz casnic
        $accGresie       = $catId(402);  // Accesorii pentru gresie
        $profileGC       = $catId(287);  // Profile și piese de îmbinare (GC)
        $polistiren      = $catId(71);   // Polistiren
        $adezParchet     = $catId(283);  // Adezivi pentru parchet
        $adezIzol        = $catId(266);  // Adezivi izolații termice
        $armatura        = $catId(348);  // Armătură
        $mortar          = $catId(354);  // Mortar
        $membraneEmulsii = $catId(57);   // Membrane și emulsii
        $hidroPer        = $catId(365);  // Hidroizolații pentru pereți
        $pigmenti        = $catId(268);  // Pigmenți pentru zugrăveli
        $siliconi        = $catId(255);  // Siliconi și adezivi
        $solLipire       = $catId(424);  // Soluții pentru lipire
        $sudura          = $catId(180);  // Sudură
        $imbracProt      = $catId(206);  // Îmbrăcăminte de protecție
        $sculeUtile      = $catId(107);  // Scule și unelte utile
        $benziMascare    = $catId(426);  // Benzi de mascare
        $zincateFit      = $catId(81);   // Zincate: Fittinguri
        $gleturi         = $catId(271);  // Gleturi

        $this->nameMap = [
            // ── SPRAY VOPSELE COLORATE → Vopsele și emailuri ──────────────────
            'spray auriu metalizat 400ml'                       => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea decorativă — categorie corectă pentru vopsele în format spray.'],
            'spray albastru cer ral 5015 400ml'                 => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea colorată RAL.'],
            'spray albastru ciel ral 5015 400 ml'               => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea colorată RAL.'],
            'spray albastru marin ral 5002 400ml'               => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea colorată RAL.'],
            'spray aramiu metalizat 400ml'                      => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea metalizată decorativă.'],
            'spray gri deruginol 400ml'                         => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray anticoroziv cu vopsea gri.'],
            'spray gri temperatura ridicata 400ml'              => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea termorezistentă spray.'],
            'spray gri-negru ral 7021 400ml'                    => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea colorată RAL.'],
            'spray inox 18/10 400ml'                            => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray imitație inox.'],
            'spray maro ciocolata lucios ral 8017 400ml'        => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea colorată RAL.'],
            'spray profi albastru gentiana ral 5010 400ml'      => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea profesională RAL.'],
            'spray rosu trafic ral 3020 400 ml'                 => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea colorată RAL.'],
            'spray rosu trafic ral 3020 400ml'                  => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea colorată RAL.'],
            'spray roz ral 3015 400ml'                          => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea colorată RAL.'],
            'spray zincat gri 400ml'                            => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray zincat anticoroziv.'],
            'spray termorezistent alb liqui moly 400ml'         => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea termorezistentă — aparține la Vopsele, nu la Electrice.'],
            'spray termorezistent argintiu liqui moly 400ml'    => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea termorezistentă.'],
            'spray termorezistent maro liqui moly 400ml'        => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea termorezistentă.'],
            'spray termorezistent negru liqui moly 400ml'       => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea termorezistentă.'],
            'spray termorezistent rosu liqui moly 400ml'        => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea termorezistentă.'],
            'spray vopsea acrilica alb liqui moly 400ml'        => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică — aparține la Vopsele, nu la Electrice.'],
            'spray vopsea acrilica albastru liqui moly 400'     => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica argintiu liqui moly 400'     => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica auriu liqui moly 400ml'      => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica bej liqui moly 400ml'        => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica bronz liqui moly 400ml'      => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica galben liqui moly 400ml'     => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica gri liqui moly 400ml'        => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica maro liqui moly 400ml'       => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica negru liqui moly 400ml'      => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica orange liqui moly 400ml'     => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica rosu liqui moly 400ml'       => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea acrilica verde liqui moly 400ml'      => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea acrilică.'],
            'spray vopsea termorezistenta alb 400ml'            => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea termorezistentă.'],
            'spray vopsea termorezistenta argintiu 400ml'       => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea termorezistentă.'],
            'spray vopsea termorezistenta brun 400ml'           => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea termorezistentă.'],
            'spray vopsea termorezistenta negru 400ml'          => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea termorezistentă.'],
            'spray vopsea termorezistenta rosu 400ml'           => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Spray vopsea termorezistentă.'],
            'vopsea spray alb 400ml'                            => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],
            'vopsea spray albastru 400ml'                       => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],
            'vopsea spray argintiu 400ml'                       => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],
            'vopsea spray auriu 400ml'                          => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],
            'vopsea spray galben 400ml'                         => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],
            'vopsea spray gri argintiu 400ml'                   => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],
            'vopsea spray maro 400ml'                           => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],
            'vopsea spray negru 400ml'                          => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],
            'vopsea spray portocaliu 400ml'                     => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],
            'vopsea spray rosu 400ml'                           => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],
            'vopsea spray verde 400ml'                          => [$vopseleSpray,   'Finisaje și amenajări > Vopsele și emailuri',  'Vopsea spray colorată.'],

            // ── SPRAY GRUNDURI → Amorse și grunduri ───────────────────────────
            'spray grund gri 400ml'                             => [$amorseGrunduri, 'Finisaje și amenajări > Amorse și grunduri',   'Spray grund — aparține la Amorse și grunduri, nu Electrice.'],
            'spray grund gri liqui moly 500ml'                  => [$amorseGrunduri, 'Finisaje și amenajări > Amorse și grunduri',   'Spray grund anticoroziv.'],
            'spray grund liqui moly 500ml'                      => [$amorseGrunduri, 'Finisaje și amenajări > Amorse și grunduri',   'Spray grund anticoroziv.'],
            'spray grund rosu 400ml'                            => [$amorseGrunduri, 'Finisaje și amenajări > Amorse și grunduri',   'Spray grund roșu.'],
            'spray grund rosu-brun liqui moly 500ml'            => [$amorseGrunduri, 'Finisaje și amenajări > Amorse și grunduri',   'Spray grund anticoroziv roșu-brun.'],

            // ── SPRAY LACURI → Lacuri și baițuri ──────────────────────────────
            'spray lac transparent lucios 400ml'                => [$lacuriBaituri,  'Finisaje > Finisaj pentru lemn > Lacuri și baițuri', 'Lac transparent spray — finisaj lemn.'],
            'spray lac transparent mat 400ml'                   => [$lacuriBaituri,  'Finisaje > Finisaj pentru lemn > Lacuri și baițuri', 'Lac transparent mat spray.'],
            'lac spray transparent 400ml'                       => [$lacuriBaituri,  'Finisaje > Finisaj pentru lemn > Lacuri și baițuri', 'Lac transparent spray.'],
            'lac spray transparent mat 400ml'                   => [$lacuriBaituri,  'Finisaje > Finisaj pentru lemn > Lacuri și baițuri', 'Lac transparent mat spray.'],

            // ── PRODUSE AUTO → Auto și întreținere utilaje (categorie nouă) ───
            'ceara auto liqui moly 300ml'                       => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Produs auto — categorie nouă dedicată produselor LIQUI MOLY auto.'],
            'spray anti rece liqui moly 400ml'                  => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray anti-îngheț auto.'],
            'spray anticoroziv zincat 400ml'                    => [$sprayLubrif,    'Consumabile > Spray-uri și lubrifianți',        'Spray anticoroziv zincat — lubrifiant/protecție, nu auto specific.'],
            'spray curatare aer'                                 => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare aer comprimat — întreținere.'],
            'spray curatare aer conditionat 500ml'              => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare aer condiționat auto.'],
            'spray curatare bord 500ml'                         => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare bord auto.'],
            'spray curatare carburator 500ml'                   => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare carburator auto.'],
            'spray curatare chedere 500ml'                      => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare chedere auto.'],
            'spray curatare contacte 400ml'                     => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare contacte electrice.'],
            'spray curatare contacte liqui moly 200ml'          => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare contacte.'],
            'spray curatare electrice liqui moly 200ml'         => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare componente electrice auto.'],
            'spray curatare frane 500ml'                        => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare frâne auto.'],
            'spray curatare garnituri liqui moly 300ml'         => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare garnituri auto.'],
            'spray curatare injectoare 500ml'                   => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare injectoare auto.'],
            'spray curatare jante 500ml'                        => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare jante auto.'],
            'spray curatare lant 500ml'                         => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare lanț utilaj/motocicletă.'],
            'spray curatare motor 500ml'                        => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare motor auto.'],
            'spray curatare motor liqui moly 400ml'             => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare motor auto.'],
            'spray curatare motor liqui moly 500ml'             => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare motor auto.'],
            'spray curatare parbriz 500ml'                      => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare parbriz auto.'],
            'spray curatare plastic interior 500ml'             => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare plastic interior auto.'],
            'spray curatare tapiterie 500ml'                    => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare tapițerie auto.'],
            'spray curatare textil 500ml'                       => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare textil auto.'],
            'spray curatare volan piele'                        => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray curățare volan piele auto.'],
            'spray degripant liqui moly 400ml'                  => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Spray degripant auto/utilaje.'],
            'vaselina pentru pvc 250gr'                         => [$sprayLubrif,    'Consumabile > Spray-uri și lubrifianți',        'Vaselină PVC — lubrifiant, nu specific auto.'],
            'ulei amestec e-oil red 0.5l'                       => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Ulei amestec pentru utilaje/drujbe.'],
            'ulei amestec e-oil red 1l'                         => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Ulei amestec pentru utilaje/drujbe.'],
            'ulei lant drujba e-oil 1l'                         => [$autoCatId,      'Consumabile > Auto și întreținere utilaje',    'Ulei lanț drujbă — întreținere utilaje.'],

            // ── ACCESORII COFRAJ (categorie nouă) ─────────────────────────────
            'distantier bara 20mmx2ml'                          => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțier pentru armătură/cofraj.'],
            'distantier bara 25mmx2ml'                          => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțier pentru armătură/cofraj.'],
            'distantier bara 50mmx2ml'                          => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțier pentru armătură/cofraj.'],
            'distantier metalic pt cofraj 10cm'                 => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțier metalic pentru cofraj.'],
            'distantier metalic pt cofraj 15cm'                 => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțier metalic pentru cofraj.'],
            'distantier metalic pt cofraj 20cm'                 => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțier metalic pentru cofraj.'],
            'distantier metalic pt cofraj 25cm'                 => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțier metalic pentru cofraj.'],
            'distantier metalic pt cofraj 30cm'                 => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțier metalic pentru cofraj.'],
            'distantier metalic pt cofraj 35cm'                 => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțier metalic pentru cofraj.'],
            'distantier metalic pt cofraj 40cm'                 => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțier metalic pentru cofraj.'],
            'distantiere armatura-20 mm'                        => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțiere plastic pentru armătură.'],
            'distantiere armatura-25 mm'                        => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Distanțiere plastic pentru armătură.'],
            'clema fixare cofraj'                               => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Clemă pentru fixare cofraj.'],
            'intinzator clema fixare cofraj'                    => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Întinzător/clemă fixare cofraj.'],
            'montant zincat cofraj 0.5 m'                       => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Montant zincat pentru cofraj.'],
            'montant zincat cofraj 1 m'                         => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Montant zincat pentru cofraj.'],
            'pana fixare montant cofraj'                        => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Pană de fixare pentru cofraj.'],
            'decofrol 20l'                                      => [$cofrajCatId,    'Materiale de construcții > Accesorii cofraj',  'Agent decofrant — produs specific pentru cofraje.'],

            // ── NUMERE CASĂ (categorie nouă) ───────────────────────────────────
            'numar casa 0'                                      => [$numereCatId,    'Feronerie > Numere de casă',                   'Număr de casă — subcategorie proprie, nu Cutii poștale.'],
            'numar casa 1'                                      => [$numereCatId,    'Feronerie > Numere de casă',                   'Număr de casă.'],
            'numar casa 2'                                      => [$numereCatId,    'Feronerie > Numere de casă',                   'Număr de casă.'],
            'numar casa 3'                                      => [$numereCatId,    'Feronerie > Numere de casă',                   'Număr de casă.'],
            'numar casa 4'                                      => [$numereCatId,    'Feronerie > Numere de casă',                   'Număr de casă.'],
            'numar casa 5'                                      => [$numereCatId,    'Feronerie > Numere de casă',                   'Număr de casă.'],
            'numar casa 6'                                      => [$numereCatId,    'Feronerie > Numere de casă',                   'Număr de casă.'],
            'numar casa 7'                                      => [$numereCatId,    'Feronerie > Numere de casă',                   'Număr de casă.'],
            'numar casa 8'                                      => [$numereCatId,    'Feronerie > Numere de casă',                   'Număr de casă.'],

            // ── RIFLAJE DE INTERIOR ────────────────────────────────────────────
            'colt exterior 606'                                 => [$riflaje,        'Amenajare > Riflaje de interior',              'Colț exterior pentru sistem riflaj — propunere anterioară corectă.'],
            'colt exterior 613'                                 => [$riflaje,        'Amenajare > Riflaje de interior',              'Colț exterior riflaj.'],
            'colt interior 606'                                 => [$riflaje,        'Amenajare > Riflaje de interior',              'Colț interior riflaj.'],
            'colt interior 613'                                 => [$riflaje,        'Amenajare > Riflaje de interior',              'Colț interior riflaj.'],
            'colt extensibil 604'                               => [$riflaje,        'Amenajare > Riflaje de interior',              'Colț extensibil pentru riflaj interior.'],
            'plinta 606 esquero 2.5ml'                          => [$riflaje,        'Amenajare > Riflaje de interior',              'Plintă decorativă Esquero — sistem riflaj interior.'],
            'plinta 610 esquero 2.5m'                           => [$riflaje,        'Amenajare > Riflaje de interior',              'Plintă decorativă Esquero — sistem riflaj interior.'],
            'capat profil soclu 602 st/dr'                      => [$riflaje,        'Amenajare > Riflaje de interior',              'Capăt profil pentru sistem de riflaj/soclu decorativ interior.'],
            'capat profil soclu 610 st/dr'                      => [$riflaje,        'Amenajare > Riflaje de interior',              'Capăt profil pentru sistem de riflaj/soclu decorativ interior.'],

            // ── ACOPERIȘ ──────────────────────────────────────────────────────
            'rozeta burlan cu capac alb'                        => [$burlane,        'Acoperișuri > Accesorii acoperiș > Burlane',   'Rozetă burlan — accesoriu pentru burlane, nu pentru sobe/hote.'],
            'cui sustinere sipca coama'                         => [$surubTigla,     'Acoperișuri > Accesorii acoperiș > Șuruburi țiglă metalică', 'Cui de susținere coamă — accesoriu metalic acoperiș.'],
            'onduband easy teracota 10cm x10m'                  => [$burlane,        'Acoperișuri > Accesorii acoperiș > Burlane',   'Bandă etanșare coamă/jgheab — accesoriu acoperiș.'],
            'coama standard onduvilla 3 tonuri rosu'            => [$sindrilaId,     'Acoperișuri > Învelitoare > Șindrilă',          'Coamă Onduvilla — accesoriu direct al sistemului Onduvilla (șindrilă bituminoasă).'],

            // ── FERONERIE ─────────────────────────────────────────────────────
            'opritor de usa bila gri'                           => [$broasteManere,  'Feronerie > Broaște și mânere',                'Opritor de ușă — accesoriu ușă, propunere anterioară corectă.'],
            'inchizator geam(foraibar)-10'                      => [$broasteManere,  'Feronerie > Broaște și mânere',                'Închizător geam — mecanism de blocare fereastră.'],
            'picior stalp 140x60x100 cu tija'                   => [$console,        'Feronerie > Console',                          'Picior stâlp cu tijă — consolă de prindere pentru stâlpi.'],
            'varf de gard mod 3'                                => [$garduri,        'Curte și grădină > Garduri',                   'Vârf de gard — accesoriu gard, nu cutii poștale.'],
            'cablu elastic 1m 2set'                             => [$carlige,        'Feronerie > Cârlige, carabine și lanțuri',     'Cablu elastic cu cârlige — accesoriu de fixare.'],

            // ── ELECTRICE ─────────────────────────────────────────────────────
            'sonerie sina 220 v'                                => [$aparataje,      'Electrice > Aparataje',                        'Sonerie montaj pe șină DIN — aparataj electric.'],
            'fir tras cablu 30m'                                => [$copexPat,       'Electrice > Copexuri și pat cablu',             'Fir de tras cablu — accesoriu electric pentru tragere cabluri.'],
            'lampa gaz cu aprinzator mica'                      => [$sudura,         'Consumabile > Sudură',                         'Lampă gaz cu aprinzător — sculă pentru lipire/sudare cu gaz.'],
            'lampa gaz kemper'                                  => [$sudura,         'Consumabile > Sudură',                         'Lampă gaz Kemper — utilizată pentru sudură/lipire.'],

            // ── SANITARE ──────────────────────────────────────────────────────
            'capac camin negru 800x800 2 usi'                   => [$canalizare,     'Sanitare > Canalizare',                        'Capac cămin — element canalizare exterioară.'],
            'capac plastic negru 30x30'                         => [$canalizare,     'Sanitare > Canalizare',                        'Capac plastic 30×30 pentru canalizare/cămine.'],
            'capac plastic rotund negru 6/4'                    => [$canalizare,     'Sanitare > Canalizare',                        'Capac rotund 6/4" — capac de canalizare.'],
            'teava zincata 1 1/2\'\' x 3.2 (48.3) 6ml'         => [$zincateFit,     'Sanitare > Zincate: Fittinguri',               'Țeavă zincată — aparține la Sanitare > Zincate, propunere anterioară corectă.'],
            'presostat hidrofor 1/4" interior'                  => [$accInstTerm,    'Sanitare > Instalații termice > Accesorii',    'Presostat hidrofor — accesoriu pentru instalații de apă.'],
            'regulator presiune 3/4" 1bar'                      => [$robineti,       'Sanitare > Robineți',                          'Regulator de presiune — accesoriu instalație apă/robinărie.'],
            'regulator gaz 3/4 cu filtru'                       => [$robineti,       'Sanitare > Robineți',                          'Regulator gaz cu filtru — robinet/reductor de presiune.'],
            'adaptor universal robinet'                          => [$robineti,       'Sanitare > Robineți',                          'Adaptor universal pentru robinet.'],
            'aditiv antiinghet frost 7 kg'                      => [$antiInghet,     'Sanitare > Soluții antiîngheț',                'Aditiv antiîngheț pentru instalații — propunere anterioară corectă.'],

            // ── CURTE ȘI GRĂDINĂ ──────────────────────────────────────────────
            'picatura gel'                                      => [$antidaunatori,  'Curte și grădină > Antidăunători',             'Picătură gel — produs anti-insecte/dăunători.'],
            'vermorel 5l'                                       => [$antidaunatori,  'Curte și grădină > Antidăunători',             'Vermorel — pompa de stropit pentru tratamente antidăunători.'],

            // ── AMENAJARE ─────────────────────────────────────────────────────
            'panou plastic'                                     => [$lambriuri,      'Amenajare > Lambriuri și glafuri',             'Panou plastic decorativ — lambriu de interior.'],
            'placa perforata 1020'                              => [$sculeUtile,     'Scule și accesorii > Scule și unelte utile',   'Placă perforată pegboard — accesoriu organizare scule.'],
            'sina ghidaj 10mm 3m'                               => [$profileDecor,   'Amenajare > Profile decorative',               'Șină de ghidaj — profil decorativ/funcțional.'],
            'sina ghidaj 6mm 3m'                                => [$profileGC,      'Construcții gips carton > Profile și piese de îmbinare', 'Șină ghidaj 6mm — profil pentru sisteme gips carton.'],
            'folie pardoseala aluminiu aer 1.2 m-50 ml'        => [$foliiParchet,   'Amenajare > Parchet > Folii pentru parchet',   'Folie aluminiu termoizolantă sub parchet.'],
            'kit egger extreme'                                 => [$parchetTrepte,  'Amenajare > Parchet > Sistem parchet trepte',  'Kit Egger pentru sisteme de trepte și rosturi parchet.'],
            'kit egger mix&match 1'                             => [$parchetTrepte,  'Amenajare > Parchet > Sistem parchet trepte',  'Kit Egger Mix&Match — sistem accesorii parchet.'],
            'kit egger mix&match 2'                             => [$parchetTrepte,  'Amenajare > Parchet > Sistem parchet trepte',  'Kit Egger Mix&Match — sistem accesorii parchet.'],
            'clema montaj parchet 28.5cm'                       => [$parchetTrepte,  'Amenajare > Parchet > Sistem parchet trepte',  'Clemă montaj parchet pe trepte.'],
            'rezerva airmax cafea 450g bison'                   => [$uzCasnic,       'Amenajare > Uz casnic',                        'Rezervă odorizant de cameră — uz casnic.'],
            'rezerva airmax flori citrus 450g bison'            => [$uzCasnic,       'Amenajare > Uz casnic',                        'Rezervă odorizant de cameră — uz casnic.'],
            'rezerva airmax lavanda 450g bison'                 => [$uzCasnic,       'Amenajare > Uz casnic',                        'Rezervă odorizant de cameră — uz casnic.'],
            'chit ceramic teracota 1kg'                         => [$accGresie,      'Amenajare > Gresie/Faianță > Accesorii pentru gresie', 'Chit ceramic pentru rosturi gresie/faianță.'],

            // ── MATERIALE DE CONSTRUCȚII ──────────────────────────────────────
            'mortar mpi 25 baumit 40 kg'                        => [$mortar,         'Materiale de construcții > Saci Praf > Mortar', 'Mortar sac Baumit — categoria corectă este Mortar.'],
            'tabla polistiren zima 2mp'                         => [$polistiren,      'Termoizolații > Polistiren',                   'Tablă de polistiren — termoizolație, nu gips carton.'],
            'perflix 25 kg'                                     => [$gleturi,         'Materiale de construcții > Saci Praf > Gleturi', 'Perflix = glet de interior în sac.'],
            'montagekit polistiren 1kg bison'                   => [$adezIzol,        'Adezivi > Adezivi izolații termice',           'Adeziv pentru polistiren — adeziv termoizolație.'],
            'profil aluminiu l 40x40x3mm 3.1ml dogav'          => [$profileGC,        'Construcții gips carton > Profile și piese de îmbinare', 'Profil structural aluminiu — îmbinare/structură.'],
            'rama eco alb 2m'                                   => [$profileDecor,    'Amenajare > Profile decorative',               'Ramă decorativă — profil decorativ, nu gips carton.'],

            // ── FINISAJE ──────────────────────────────────────────────────────
            'banda aluminiu 50mm x 50m bison'                   => [$benziMascare,   'Finisaje și amenajări > Benzi de mascare',     'Bandă aluminiu autoadezivă — mascare/etanșare.'],
            'oxid albastru 150gr'                               => [$pigmenti,        'Finisaje > Lavabile > Pigmenți pentru zugrăveli', 'Oxid colorant — pigment pentru zugrăveli, nu lubrifiant.'],
            'profil cant placa aluminiu 2.75m'                  => [$profileDecor,    'Amenajare > Profile decorative',               'Profil de cant pentru plăci — finisaj/decorativ.'],
            'profil pvc etansare chiuveta'                      => [$siliconi,        'Finisaje și amenajări > Siliconi și adezivi',  'Profil PVC de etanșare — accesoriu siliconi/etanșare.'],
            'adeziv pentru oglinzi auto 2ml bison'              => [$solLipire,       'Sisteme de fixare > Soluții pentru lipire',    'Adeziv epoxy pentru oglinzi — soluție de lipire.'],
            'aracet lemn d3 0.75kg'                             => [$adezParchet,     'Adezivi > Adezivi pentru parchet',             'Aracet D3 = adeziv pentru lemn/parchet.'],
            'epoxy metal 2x12ml bison'                          => [$solLipire,       'Sisteme de fixare > Soluții pentru lipire',    'Adeziv epoxy bicomponent pentru metal.'],
            'epoxy universal 2x12ml bison'                      => [$solLipire,       'Sisteme de fixare > Soluții pentru lipire',    'Adeziv epoxy bicomponent universal.'],
            'jowatherm reactant ambiental 0.34kg'               => [$adezParchet,     'Adezivi > Adezivi pentru parchet',             'Adeziv termofuzibil pentru parchet/lambriu.'],
            'banda dubla adeziva carpet bison 50mmx10m'         => [$solLipire,       'Sisteme de fixare > Soluții pentru lipire',    'Bandă dublă adezivă pentru covor/parchet — lipire.'],

            // ── HIDROIZOLAȚII ─────────────────────────────────────────────────
            'hidroizolatie baumacol proof 7 kg'                 => [$hidroPer,        'Acoperișuri > Hidroizolații pentru pereți',    'Hidroizolație lichidă Baumacol pentru pereți/fundații.'],
            'membrana lichida hidroizolanta 4,7 l membraan'     => [$hidroPer,        'Acoperișuri > Hidroizolații pentru pereți',    'Membrană lichidă hidroizolantă pentru pereți.'],
            'banda hidroizolatie sikaswell a2010 rosie 10m'     => [$membraneEmulsii, 'Acoperișuri > Hidroizolații > Membrane și emulsii', 'Bandă hidroizolantă activă Sikaswell pentru rosturi.'],
            'siliciu hidroizolant sikaswell s-2 oxydrot 300ml'  => [$membraneEmulsii, 'Acoperișuri > Hidroizolații > Membrane și emulsii', 'Silicon hidroizolant Sikaswell pentru rosturi umede.'],

            // ── ECHIPAMENTE PROTECȚIE ─────────────────────────────────────────
            'basca'                                             => [$imbracProt,      'Echipamente de protecție > Îmbrăcăminte de protecție', 'Bască/șapcă de lucru — echipament de protecție.'],
        ];
    }

    // ────────────────────────────────────────────────────────────────────────────
    private function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't',
            'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ț' => 't',
            'ş' => 's', 'ţ' => 't',
            '×' => 'x', '″' => '"', '′' => "'",
        ]);
        return trim($s);
    }

    private function insertProposal(int $wooProductId, string $productName, string $currentCats): bool
    {
        $key = $this->normalize($productName);

        // Căutare exactă
        $entry = $this->nameMap[$key] ?? null;

        // Căutare parțială (prefix)
        if (! $entry) {
            foreach ($this->nameMap as $pattern => [$catId, $catName, $reason]) {
                if (str_starts_with($key, $pattern) || str_starts_with($pattern, $key)) {
                    $entry = [$catId, $catName, $reason];
                    break;
                }
            }
        }

        if (! $entry) {
            return false;
        }

        [$catId, $catName, $reason] = $entry;

        if (! $catId) {
            return false;
        }

        $cat = WooCategory::find($catId);
        if (! $cat) {
            return false;
        }

        // Dedup: nu mai există aceeași propunere pending
        $exists = DB::table('category_review_proposals')
            ->where('woo_product_id', $wooProductId)
            ->where('suggested_cat_id', $catId)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($exists) {
            return false;
        }

        DB::table('category_review_proposals')->insert([
            'woo_product_id'   => $wooProductId,
            'product_name'     => $productName,
            'current_cats'     => $currentCats,
            'suggested_cat_id' => $catId,
            'suggested_cat_name' => $cat->name,
            'reason'           => $reason,
            'status'           => 'pending',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return true;
    }

    // ────────────────────────────────────────────────────────────────────────────
    private function findExtraProducts(int $numereCatId, int $cofrajCatId, int $autoCatId): int
    {
        $inserted = 0;

        // Caută produse din catalog care se potrivesc dar nu au propuneri pending
        $patterns = [
            // Numere casă: produse cu "Număr Casă" sau "Numar Casa" în nume care nu sunt deja propuse
            ['pattern' => '%umar%as%', 'catId' => $numereCatId, 'catName' => 'Numere de casă', 'reason' => 'Număr de casă — subcategorie dedicată.'],

            // Cofraj: produse cu "cofraj" sau "distantier" în nume
            ['pattern' => '%cofraj%', 'catId' => $cofrajCatId, 'catName' => 'Accesorii cofraj', 'reason' => 'Produs specific pentru cofrare — aparține la categoria Accesorii cofraj.'],
            ['pattern' => '%distantier%', 'catId' => $cofrajCatId, 'catName' => 'Accesorii cofraj', 'reason' => 'Distanțier pentru armătură/cofraj.'],
            ['pattern' => '%distantiere%', 'catId' => $cofrajCatId, 'catName' => 'Accesorii cofraj', 'reason' => 'Distanțiere pentru armătură/cofraj.'],

            // Auto LIQUI MOLY: produse cu "liqui moly" care nu sunt deja propuse
            ['pattern' => '%liqui moly%', 'catId' => $autoCatId, 'catName' => 'Auto și întreținere utilaje', 'reason' => 'Produs LIQUI MOLY — întreținere auto/utilaje.'],

            // Spray curățare auto
            ['pattern' => '%spray curata%', 'catId' => $autoCatId, 'catName' => 'Auto și întreținere utilaje', 'reason' => 'Spray de curățare pentru auto/utilaje.'],
            ['pattern' => '%ulei lant%', 'catId' => $autoCatId, 'catName' => 'Auto și întreținere utilaje', 'reason' => 'Ulei de lanț pentru drujbă/utilaje.'],
            ['pattern' => '%ulei amestec%', 'catId' => $autoCatId, 'catName' => 'Auto și întreținere utilaje', 'reason' => 'Ulei amestec pentru utilaje cu 2 timpi.'],
        ];

        $cat = fn(int $id) => WooCategory::find($id);

        foreach ($patterns as $p) {
            $products = DB::table('woo_products')
                ->where('status', 'publish')
                ->where('name', 'like', $p['pattern'])
                ->whereNotNull('woo_id')
                ->get(['id', 'woo_id', 'name']);

            foreach ($products as $prod) {
                // Verifică dacă nu există deja o propunere pending/approved (woo_product_id = local id)
                $exists = DB::table('category_review_proposals')
                    ->where('woo_product_id', $prod->id)
                    ->where('suggested_cat_id', $p['catId'])
                    ->whereIn('status', ['pending', 'approved'])
                    ->exists();

                if ($exists) continue;

                // Obține categoria curentă a produsului (pivot folosește local id)
                $currentCats = DB::table('woo_product_category')
                    ->join('woo_categories', 'woo_categories.id', '=', 'woo_product_category.woo_category_id')
                    ->where('woo_product_category.woo_product_id', $prod->id)
                    ->pluck('woo_categories.name')
                    ->join(' > ');

                if (empty($currentCats)) $currentCats = 'Necategorizit';

                $catObj = $cat($p['catId']);
                if (! $catObj) continue;

                DB::table('category_review_proposals')->insert([
                    'woo_product_id'    => $prod->id,   // local id (FK)
                    'product_name'      => $prod->name,
                    'current_cats'      => $currentCats,
                    'suggested_cat_id'  => $p['catId'],
                    'suggested_cat_name'=> $catObj->name,
                    'reason'            => $p['reason'],
                    'status'            => 'pending',
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
                $inserted++;
            }
        }

        return $inserted;
    }
}
