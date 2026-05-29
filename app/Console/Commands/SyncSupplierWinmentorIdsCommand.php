<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Sincronizează winmentor_id la furnizori — descarcă toți partenerii din WinMentor
 * și match-uiește după CUI normalizat. Corectează ID-urile greșite.
 */
class SyncSupplierWinmentorIdsCommand extends Command
{
    protected $signature = 'supplier:sync-winmentor-ids
        {--dry-run : Doar afișează diferențele, fără a modifica}';

    protected $description = 'Sincronizează winmentor_id la furnizori din Bridge (match pe CUI)';

    public function handle(): int
    {
        $conn = IntegrationConnection::find(5);
        if (! $conn) {
            $this->error('IntegrationConnection #5 (WinMentor Bridge) nu există.');
            return 1;
        }

        $baseUrl = rtrim($conn->bridgeUrl(), '/');
        $apiKey  = $conn->bridgeApiKey();

        // 1. Selectăm firma + setăm IdPartField pe CodIntern
        $this->info('Selectez firma...');
        Http::timeout(30)
            ->withHeaders(['X-API-Key' => $apiKey])
            ->post($baseUrl . '/api/firme/select', [
                'firma' => $conn->bridgeFirma(),
                'an'    => $conn->bridgeAn(),
                'luna'  => $conn->bridgeLuna(),
            ]);

        Http::timeout(10)->withoutVerifying()
            ->withHeaders(['X-API-Key' => $apiKey])
            ->post($baseUrl . '/api/config/id-part-field', [
                'fieldName' => 'CodIntern',
            ]);

        // 2. Descarcăm toți partenerii
        $this->info('Descarc parteneri din WinMentor...');
        $wmParteneri = [];
        $page = 1;

        do {
            $response = Http::timeout(60)->withoutVerifying()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->get($baseUrl . '/api/parteneri', ['pageSize' => 500, 'page' => $page]);

            $data = $response->json();
            if (! ($data['success'] ?? false)) {
                $this->error("Eroare la pagina {$page}: " . json_encode($data));
                return 1;
            }

            $items      = $data['data']['items'] ?? [];
            $totalPages = (int) ($data['data']['totalPages'] ?? 1);

            foreach ($items as $item) {
                $wmParteneri[] = $item;
            }

            if ($page === 1) {
                $this->info("Total parteneri WinMentor: " . ($data['data']['totalItems'] ?? '?') . " ({$totalPages} pagini)");
            }

            if ($page % 5 === 0) {
                $this->output->write("\r  Pagina {$page}/{$totalPages}...");
            }

            $page++;
        } while ($page <= $totalPages);

        $this->newLine();
        $this->info('Descărcați ' . count($wmParteneri) . ' parteneri.');

        // 3. Construim index pe CUI normalizat → partener WinMentor
        $cuiIndex = [];    // cuiNormalized => partener
        $idIndex  = [];    // idPartener => partener

        foreach ($wmParteneri as $p) {
            $id = $p['idPartener'] ?? '';
            if ($id) {
                $idIndex[$id] = $p;
            }

            $cui = preg_replace('/[^0-9]/', '', $p['codFiscal'] ?? '');
            if ($cui && strlen($cui) >= 3) {
                // Poate exista duplicate — păstrăm ultimul (sau primul)
                if (! isset($cuiIndex[$cui])) {
                    $cuiIndex[$cui] = $p;
                }
            }
        }

        $this->info('Index CUI: ' . count($cuiIndex) . ' intrări unice.');

        // 4. Verificăm fiecare furnizor din ERP
        $suppliers = Supplier::all();
        $dryRun    = $this->option('dry-run');

        $updated   = 0;
        $matched   = 0;
        $notFound  = 0;
        $correct   = 0;
        $newMatch  = 0;

        $rows = [];

        foreach ($suppliers as $supplier) {
            $currentWmId = $supplier->winmentor_id;
            $vatNormalized = preg_replace('/[^0-9]/', '', $supplier->vat_number ?? '');

            // Verificăm dacă ID-ul curent există în WinMentor
            $currentExists = $currentWmId && isset($idIndex[$currentWmId]);

            // Căutăm match pe CUI
            $cuiMatch = ($vatNormalized && strlen($vatNormalized) >= 3) ? ($cuiIndex[$vatNormalized] ?? null) : null;
            $correctId = $cuiMatch ? ($cuiMatch['idPartener'] ?? null) : null;

            if ($currentExists && (! $cuiMatch || $correctId === $currentWmId)) {
                // ID-ul curent e corect
                $correct++;
                continue;
            }

            if (! $currentWmId && ! $cuiMatch) {
                // Nu are nici ID, nici CUI match — skip
                $notFound++;
                continue;
            }

            if ($cuiMatch && $correctId !== $currentWmId) {
                // ID incorect sau lipsă — trebuie actualizat
                $wmName = $cuiMatch['denumire'] ?? '?';
                $rows[] = [
                    $supplier->id,
                    $supplier->name,
                    $supplier->vat_number ?? '',
                    $currentWmId ?: '(gol)',
                    $correctId,
                    $wmName,
                    $currentExists ? 'ID greșit' : ($currentWmId ? 'ID inexistent' : 'Lipsă'),
                ];

                if (! $dryRun) {
                    $supplier->updateQuietly(['winmentor_id' => $correctId]);
                    $updated++;
                } else {
                    $matched++;
                }
                continue;
            }

            if ($currentWmId && ! $currentExists && ! $cuiMatch) {
                // Are ID setat dar nu există în WM și nu avem CUI match
                $rows[] = [
                    $supplier->id,
                    $supplier->name,
                    $supplier->vat_number ?? '',
                    $currentWmId,
                    '?',
                    '-',
                    'ID inexistent, fără CUI match',
                ];
                $notFound++;
            }
        }

        if (count($rows)) {
            $this->newLine();
            $this->table(
                ['ID', 'Furnizor', 'VAT', 'WM ID vechi', 'WM ID corect', 'Denumire WM', 'Problema'],
                $rows
            );
        }

        $this->newLine();
        $this->info("Rezultate:");
        $this->line("  Corecte: {$correct}");
        if ($dryRun) {
            $this->line("  De actualizat: {$matched}");
        } else {
            $this->line("  Actualizate: {$updated}");
        }
        $this->line("  Fără match CUI: {$notFound}");

        if ($dryRun && $matched > 0) {
            $this->warn("Rulează fără --dry-run pentru a aplica modificările.");
        }

        return 0;
    }
}
