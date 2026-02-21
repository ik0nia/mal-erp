<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->index(['connection_id', 'name'], 'woo_products_connection_name_idx');
            $table->index(['connection_id', 'slug'], 'woo_products_connection_slug_idx');
        });

        Schema::table('woo_categories', function (Blueprint $table) {
            $table->index(['connection_id', 'name'], 'woo_categories_connection_name_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('woo_categories', function (Blueprint $table) {
            $table->dropIndex('woo_categories_connection_name_idx');
        });

        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropIndex('woo_products_connection_name_idx');
            $table->dropIndex('woo_products_connection_slug_idx');
        });
    }
};
