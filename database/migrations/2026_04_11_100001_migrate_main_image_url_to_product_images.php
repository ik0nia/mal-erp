<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('woo_products')
            ->whereNotNull('main_image_url')
            ->where('main_image_url', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($products) {
                $rows = [];
                $now  = now()->toDateTimeString();

                foreach ($products as $product) {
                    $exists = DB::table('product_images')
                        ->where('woo_product_id', $product->id)
                        ->where('is_primary', true)
                        ->exists();

                    if (! $exists) {
                        $rows[] = [
                            'woo_product_id' => $product->id,
                            'url'            => $product->main_image_url,
                            'sort_order'     => 0,
                            'is_primary'     => true,
                            'source'         => 'woocommerce',
                            'created_at'     => $now,
                            'updated_at'     => $now,
                        ];
                    }
                }

                if ($rows) {
                    DB::table('product_images')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('product_images')->where('source', 'woocommerce')->delete();
    }
};
