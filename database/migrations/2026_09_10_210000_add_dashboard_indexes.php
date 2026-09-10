<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winmentor_vanzari_raw', function (Blueprint $table) {
            $table->index(['an', 'luna', 'zi'], 'vanzari_an_luna_zi_idx');
        });
        Schema::table('woo_products', function (Blueprint $table) {
            $table->index('sku', 'woo_products_sku_idx');
        });
    }

    public function down(): void
    {
        Schema::table('winmentor_vanzari_raw', fn (Blueprint $t) => $t->dropIndex('vanzari_an_luna_zi_idx'));
        Schema::table('woo_products', fn (Blueprint $t) => $t->dropIndex('woo_products_sku_idx'));
    }
};
