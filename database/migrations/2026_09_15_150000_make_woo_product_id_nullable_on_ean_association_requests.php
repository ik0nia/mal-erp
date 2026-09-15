<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O cerere de asociere EAN poate exista FĂRĂ produs (se scanează un EAN
     * necunoscut, tocmai ca să se ceară asocierea) → woo_product_id trebuie
     * să accepte NULL. Coloana era NOT NULL și pica insertul cu
     * "Column 'woo_product_id' cannot be null".
     */
    public function up(): void
    {
        Schema::table('ean_association_requests', function (Blueprint $table) {
            $table->dropForeign(['woo_product_id']);
        });

        Schema::table('ean_association_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('woo_product_id')->nullable()->change();
            $table->foreign('woo_product_id')->references('id')->on('woo_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ean_association_requests', function (Blueprint $table) {
            $table->dropForeign(['woo_product_id']);
        });

        Schema::table('ean_association_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('woo_product_id')->nullable(false)->change();
            $table->foreign('woo_product_id')->references('id')->on('woo_products')->cascadeOnDelete();
        });
    }
};
