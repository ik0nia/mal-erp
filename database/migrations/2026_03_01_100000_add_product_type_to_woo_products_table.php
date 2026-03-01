<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->enum('product_type', ['shop', 'production', 'pallet_fee'])
                ->default('shop')
                ->after('erp_notes')
                ->comment('Clasificare produs: shop=comercializare, production=materie primă producție, pallet_fee=garanție palet');
        });

        // Auto-populare din denumire
        DB::statement("
            UPDATE woo_products
            SET product_type = CASE
                WHEN LOWER(name) LIKE '%maxcl%' THEN 'production'
                WHEN LOWER(name) LIKE '%palet%' THEN 'pallet_fee'
                ELSE 'shop'
            END
        ");
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropColumn('product_type');
        });
    }
};
