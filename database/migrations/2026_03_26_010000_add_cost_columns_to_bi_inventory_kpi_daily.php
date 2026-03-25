<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bi_inventory_kpi_daily', function (Blueprint $table) {
            $table->decimal('inventory_value_cost_closing_total', 20, 4)
                ->nullable()
                ->after('inventory_value_variation_total');
            $table->decimal('gross_margin_total', 20, 4)->nullable();
            $table->decimal('gross_margin_pct', 6, 2)->nullable();
            $table->unsignedInteger('products_with_cost_data')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bi_inventory_kpi_daily', function (Blueprint $table) {
            $table->dropColumn([
                'inventory_value_cost_closing_total',
                'gross_margin_total',
                'gross_margin_pct',
                'products_with_cost_data',
            ]);
        });
    }
};
