<?php

namespace App\Console\Commands;

use App\Jobs\ProposeToyaCategoryMappingJob;
use App\Models\ToyaCategoryProposal;
use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProposeToyaCategoriesCommand extends Command
{
    protected $signature = 'toya:propose-categories
                            {--chunks=15  : Numărul de agenți AI (chunks) paraleli}
                            {--reset      : Șterge propunerile existente înainte de rulare}
                            {--sync       : Rulează sincron (fără queue), util pentru debug}';

    protected $description = 'Pornește agenții AI care propun mapping categorii Toya → WooCommerce';

    public function handle(): int
    {
        if ($this->option('reset')) {
            ToyaCategoryProposal::truncate();
            $this->warn('Propunerile existente au fost șterse.');
        }

        // 1. Extragem path-urile unice cu numărul de produse
        $this->info('Extrag path-urile unice din produsele Toya...');
        $paths = $this->extractUniquePaths();
        $this->line('  ' . count($paths) . ' path-uri unice găsite');

        if (empty($paths)) {
            $this->error('Niciun path găsit. Rulați mai întâi importul Toya.');
            return self::FAILURE;
        }

        // 2. Construim lista categoriilor WooCommerce cu ierarhie
        $this->info('Construiesc lista categoriilor WooCommerce...');
        $wooCategories = $this->buildWooCategoryList();
        $this->line('  ' . count($wooCategories) . ' categorii WooCommerce');

        // 3. Împărțim în chunks
        $chunkSize  = (int) $this->option('chunks');
        $pathChunks = array_chunk($paths, (int) ceil(count($paths) / $chunkSize), true);
        $this->line('  ' . count($pathChunks) . ' chunk-uri × ~' . count($pathChunks[0]) . ' path-uri');

        // 4. Dispatch jobs
        $this->info('Pornim ' . count($pathChunks) . ' agenți AI...');

        foreach ($pathChunks as $index => $chunk) {
            $job = new ProposeToyaCategoryMappingJob($chunk, $wooCategories, $index);

            if ($this->option('sync')) {
                dispatch_sync($job);
                $this->line("  Chunk {$index} procesat.");
            } else {
                dispatch($job);
            }
        }

        if (! $this->option('sync')) {
            $this->info('✓ ' . count($pathChunks) . ' job-uri trimise în queue.');
            $this->line('  Urmăriți progresul pe pagina "Categorii Toya" din ERP.');
        }

        return self::SUCCESS;
    }

    private function extractUniquePaths(): array
    {
        $products = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)->get(['data']);
        $paths    = [];

        foreach ($products as $product) {
            $data = $product->data;
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            if (! is_array($data)) {
                continue;
            }

            $categoryRo = $data['category_ro'] ?? '';
            if (empty($categoryRo)) {
                continue;
            }

            $segments = array_filter(array_map('trim', explode('/', $categoryRo)));
            $segments = array_values(array_filter(
                $segments,
                fn ($s) => ! in_array(mb_strtolower($s), ['produkty', 'products', 'produse'], true)
            ));

            if (empty($segments)) {
                continue;
            }

            $path          = implode(' / ', $segments);
            $paths[$path]  = ($paths[$path] ?? 0) + 1;
        }

        arsort($paths);

        return $paths;
    }

    private function buildWooCategoryList(): array
    {
        // Construim ierarhia: "Categorie Fiu > Categorie Parinte"
        $cats = DB::table('woo_categories')
            ->select('id', 'name', 'parent_id')
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($cats as $cat) {
            $hierarchy = [$cat->name];
            $parentId  = $cat->parent_id;

            while ($parentId && isset($cats[$parentId])) {
                $hierarchy[] = $cats[$parentId]->name;
                $parentId    = $cats[$parentId]->parent_id;
            }

            $result[$cat->id] = implode(' > ', $hierarchy);
        }

        asort($result);

        return $result;
    }
}
