<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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

                    $unit   = null;
                    $weight = null;
                    $dimLength = null;
                    $dimWidth  = null;
                    $dimHeight = null;

                    // Unit of measure din meta_data (tema WoodMart)
                    foreach ($data['meta_data'] ?? [] as $meta) {
                        if (($meta['key'] ?? '') === 'woodmart_price_unit_of_measure') {
                            $val = trim((string) ($meta['value'] ?? ''));
                            if ($val !== '') {
                                $unit = $val;
                            }
                            break;
                        }
                    }

                    // Greutate
                    $rawWeight = trim((string) ($data['weight'] ?? ''));
                    if ($rawWeight !== '' && $rawWeight !== '0') {
                        $weight = $rawWeight;
                    }

                    // Dimensiuni
                    $dims = $data['dimensions'] ?? [];
                    $rawLength = trim((string) ($dims['length'] ?? ''));
                    $rawWidth  = trim((string) ($dims['width'] ?? ''));
                    $rawHeight = trim((string) ($dims['height'] ?? ''));

                    if ($rawLength !== '') {
                        $dimLength = $rawLength;
                    }
                    if ($rawWidth !== '') {
                        $dimWidth = $rawWidth;
                    }
                    if ($rawHeight !== '') {
                        $dimHeight = $rawHeight;
                    }

                    if ($unit === null && $weight === null && $dimLength === null && $dimWidth === null && $dimHeight === null) {
                        continue;
                    }

                    DB::table('woo_products')
                        ->where('id', $product->id)
                        ->update(array_filter([
                            'unit'       => $unit,
                            'weight'     => $weight,
                            'dim_length' => $dimLength,
                            'dim_width'  => $dimWidth,
                            'dim_height' => $dimHeight,
                        ], fn ($v) => $v !== null));
                }
            });
    }

    public function down(): void
    {
        DB::table('woo_products')->update([
            'unit'       => null,
            'weight'     => null,
            'dim_length' => null,
            'dim_width'  => null,
            'dim_height' => null,
        ]);
    }
};
