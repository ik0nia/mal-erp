<?php

namespace App\Console\Commands;

use App\Models\CategoryReviewProposal;
use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCategoryProposalsCommand extends Command
{
    protected $signature   = 'categories:import-proposals {files* : JSON result files to import}';
    protected $description = 'Import AI category review proposals from JSON result files';

    public function handle(): int
    {
        $files = $this->argument('files');
        $imported = 0;
        $skipped  = 0;

        foreach ($files as $file) {
            if (! file_exists($file)) {
                $this->warn("File not found: $file");
                continue;
            }

            $proposals = json_decode(file_get_contents($file), true);
            if (! is_array($proposals)) {
                $this->warn("Invalid JSON in: $file");
                continue;
            }

            $this->info("Processing $file: " . count($proposals) . " entries");

            foreach ($proposals as $p) {
                if (($p['action'] ?? '') !== 'move') {
                    continue;
                }
                if (empty($p['id']) || empty($p['suggested_cat_id'])) {
                    continue;
                }

                // Skip if already exists
                if (CategoryReviewProposal::where('woo_product_id', $p['id'])
                    ->where('suggested_cat_id', $p['suggested_cat_id'])
                    ->exists()) {
                    $skipped++;
                    continue;
                }

                // Look up product name from DB if not in JSON
                $name = $p['name'] ?? WooProduct::find($p['id'])?->name ?? 'Unknown';

                CategoryReviewProposal::create([
                    'woo_product_id'    => $p['id'],
                    'product_name'      => $name,
                    'current_cats'      => $p['current_cats'] ?? '',
                    'suggested_cat_id'  => $p['suggested_cat_id'],
                    'suggested_cat_name' => $p['suggested_cat_name'] ?? '',
                    'reason'            => $p['reason'] ?? '',
                    'status'            => CategoryReviewProposal::STATUS_PENDING,
                ]);
                $imported++;
            }
        }

        $this->info("Done. Imported: $imported, Skipped (duplicates): $skipped");

        return self::SUCCESS;
    }
}
