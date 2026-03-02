<?php

namespace App\Console\Commands;

use App\Jobs\PopulateWinmentorNamesBatchJob;
use App\Models\IntegrationConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class PopulateWinmentorNamesCommand extends Command
{
    protected $signature = 'woo:populate-winmentor-names
                            {--connection= : ID conexiune WinMentor (implicit: prima activă)}
                            {--chunk=300   : Produse per job}
                            {--workers=5   : Workeri paraleli}';

    protected $description = 'Populează câmpul winmentor_name pentru toate produsele din CSV-ul WinMentor';

    public function handle(): int
    {
        // 1. Găsește conexiunea WinMentor
        $connectionId = $this->option('connection');

        $connection = $connectionId
            ? IntegrationConnection::query()->findOrFail((int) $connectionId)
            : IntegrationConnection::query()
                ->where('provider', IntegrationConnection::PROVIDER_WINMENTOR_CSV)
                ->where('is_active', true)
                ->firstOrFail();

        $this->info("Conexiune: [{$connection->id}] {$connection->name}");
        $this->info("URL CSV: {$connection->csvUrl()}");

        // 2. Descarcă CSV-ul
        $this->info('Descărcând CSV...');

        $response = Http::timeout($connection->resolveTimeoutSeconds())
            ->withOptions(['verify' => $connection->verify_ssl ?? false])
            ->get($connection->csvUrl());

        $response->throw();

        // 3. Parsează CSV → [sku => name]
        $this->info('Parsând CSV...');
        $skuToName = $this->parseCsvMapping($response->body(), $connection);

        $total = count($skuToName);
        $this->info("Rânduri valide: {$total}");

        if ($total === 0) {
            $this->warn('CSV fără rânduri valide. Ieșire.');

            return self::FAILURE;
        }

        // 4. Găsește WooCommerce connection IDs pentru locația WinMentor
        $wooConnectionIds = IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
            ->where(function ($q) use ($connection): void {
                $q->where('location_id', $connection->location_id)
                  ->orWhereNull('location_id');
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($wooConnectionIds)) {
            $this->error('Nu există conexiuni WooCommerce pentru această locație.');

            return self::FAILURE;
        }

        // 5. Dispatch jobs în chunks
        $chunkSize = max(1, (int) $this->option('chunk'));
        $chunks    = array_chunk($skuToName, $chunkSize, true);
        $jobCount  = count($chunks);

        $this->info("Dispatch {$jobCount} joburi (chunk={$chunkSize})...");

        foreach ($chunks as $chunk) {
            dispatch(new PopulateWinmentorNamesBatchJob($chunk, $wooConnectionIds));
        }

        $this->info("Toate joburile au fost puse în coadă.");

        // 6. Pornește workerii și așteaptă finalizarea
        $workers = (int) $this->option('workers');
        $this->info("Pornind {$workers} workeri...");

        $phpBin     = PHP_BINARY;
        $artisan    = base_path('artisan');
        $processes  = [];

        for ($i = 0; $i < $workers; $i++) {
            $process = new Process([
                $phpBin, $artisan, 'queue:work',
                '--stop-when-empty',
                '--tries=3',
                '--timeout=120',
                '--quiet',
            ]);
            $process->setTimeout(600);
            $process->start();
            $processes[] = $process;
        }

        $bar = $this->output->createProgressBar($jobCount);
        $bar->start();

        $done = 0;

        while (! empty($processes)) {
            foreach ($processes as $key => $process) {
                if (! $process->isRunning()) {
                    unset($processes[$key]);
                }
            }

            // Estimăm progres prin ce mai e în coadă
            $remaining = \Illuminate\Support\Facades\DB::table('jobs')->count();
            $current   = max(0, $jobCount - $remaining);

            if ($current > $done) {
                $bar->advance($current - $done);
                $done = $current;
            }

            if (! empty($processes)) {
                usleep(500_000); // 0.5s
            }
        }

        $bar->finish();
        $this->newLine();

        // 7. Raport final
        $updated = \App\Models\WooProduct::whereNotNull('winmentor_name')->count();
        $this->info("Finalizat. Produse cu winmentor_name: {$updated}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>  [sku => name]
     */
    private function parseCsvMapping(string $csv, IntegrationConnection $connection): array
    {
        $delimiter = (string) data_get($connection->settings, 'delimiter', ',');
        if ($delimiter === '') {
            $delimiter = ',';
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($csv));

        if (! is_array($lines) || $lines === []) {
            return [];
        }

        $header    = str_getcsv((string) array_shift($lines), $delimiter);
        $headerMap = [];

        foreach ($header as $index => $col) {
            $key = $this->normalizeKey((string) $col);
            $headerMap[$key] = $index;
        }

        $skuColumn  = $this->normalizeKey((string) data_get($connection->settings, 'sku_column', 'codextern'));
        $nameColumn = $this->normalizeKey((string) data_get($connection->settings, 'name_column', 'denumire'));

        if (! isset($headerMap[$skuColumn]) || ! isset($headerMap[$nameColumn])) {
            $this->warn("Coloanele '{$skuColumn}' sau '{$nameColumn}' nu există în CSV.");

            return [];
        }

        $mapping = [];

        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }

            $cols = str_getcsv((string) $line, $delimiter);
            $sku  = trim((string) ($cols[$headerMap[$skuColumn]] ?? ''));
            $name = $this->sanitizeName((string) ($cols[$headerMap[$nameColumn]] ?? ''));

            if ($sku !== '' && $name !== '') {
                $mapping[$sku] = $name;
            }
        }

        return $mapping;
    }

    private function normalizeKey(string $key): string
    {
        $key = ltrim($key, "\xEF\xBB\xBF");
        $key = mb_strtolower(trim($key));

        return preg_replace('/\s+/', '', $key) ?? '';
    }

    private function sanitizeName(string $value): string
    {
        $name = trim($value);

        if ($name === '') {
            return '';
        }

        if (! preg_match('/^\p{L}/u', $name)) {
            $name = ltrim((string) mb_substr($name, 1));
        }

        return mb_substr(trim($name), 0, 255);
    }
}
