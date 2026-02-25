<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table): void {
            $table->string('brand')->nullable()->after('unit');
        });

        // Populare din JSON existent
        DB::table('woo_products')
            ->where('source', 'woocommerce')
            ->whereNotNull('data')
            ->orderBy('id')
            ->chunk(500, function ($products): void {
                foreach ($products as $product) {
                    $data = json_decode((string) $product->data, true);

                    if (! is_array($data)) {
                        continue;
                    }

                    $brand = null;

                    // 1. Din atributele produsului (câmpul "Brand")
                    foreach ($data['attributes'] ?? [] as $attr) {
                        if (strcasecmp(trim((string) ($attr['name'] ?? '')), 'Brand') === 0) {
                            $opts = $attr['options'] ?? [];
                            if (! empty($opts[0])) {
                                $brand = trim((string) $opts[0]);
                            }
                            break;
                        }
                    }

                    // 2. Fallback: fb_brand din meta_data
                    if ($brand === null) {
                        foreach ($data['meta_data'] ?? [] as $meta) {
                            if (($meta['key'] ?? '') === 'fb_brand') {
                                $val = trim((string) ($meta['value'] ?? ''));
                                if ($val !== '') {
                                    $brand = $val;
                                }
                                break;
                            }
                        }
                    }

                    if ($brand === null) {
                        continue;
                    }

                    DB::table('woo_products')
                        ->where('id', $product->id)
                        ->update(['brand' => $brand]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table): void {
            $table->dropColumn('brand');
        });
    }
};
