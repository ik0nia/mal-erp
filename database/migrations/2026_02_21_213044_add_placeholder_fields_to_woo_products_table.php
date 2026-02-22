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
            $table->string('source')->default('woocommerce')->after('data');
            $table->boolean('is_placeholder')->default(false)->after('source');

            $table->index(['connection_id', 'source'], 'woo_products_connection_source_idx');
            $table->index(['connection_id', 'is_placeholder'], 'woo_products_connection_placeholder_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropIndex('woo_products_connection_source_idx');
            $table->dropIndex('woo_products_connection_placeholder_idx');
            $table->dropColumn(['source', 'is_placeholder']);
        });
    }
};
