<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_inventory_kpi_daily', function (Blueprint $table) {
            // PK: ziua — 1 rând/zi, calculat din daily_stock_metrics
            $table->date('day')->primary();

            // Număr produse
            $table->unsignedInteger('products_total')->default(0);
            $table->unsignedInteger('products_in_stock')->default(0);
            $table->unsignedInteger('products_out_of_stock')->default(0);

            // Cantitate totală stoc (closing)
            $table->decimal('inventory_qty_closing_total', 14, 3)->default(0);

            // Valoare stoc RON
            $table->decimal('inventory_value_opening_total', 14, 2)->default(0);
            $table->decimal('inventory_value_closing_total', 14, 2)->default(0);
            $table->decimal('inventory_value_variation_total', 14, 2)->default(0); // closing - opening

            // Metrici importuri
            $table->unsignedBigInteger('snapshots_total')->default(0);     // sum(snapshots_count)
            $table->unsignedInteger('imports_span_minutes')->nullable();   // max(last_snapshot_at) - min(first_snapshot_at)

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_inventory_kpi_daily');
    }
};
