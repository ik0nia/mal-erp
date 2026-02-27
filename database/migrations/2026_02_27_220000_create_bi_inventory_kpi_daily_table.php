<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_inventory_kpi_daily', function (Blueprint $table) {
            // PK = ziua; un singur rând per zi
            $table->date('day')->primary();

            // Număr produse
            $table->unsignedInteger('products_total')->default(0);
            $table->unsignedInteger('products_in_stock')->default(0);
            $table->unsignedInteger('products_out_of_stock')->default(0);

            // Cantitate & valoare stoc
            $table->decimal('inventory_qty_closing_total', 18, 3)->default(0);
            $table->decimal('inventory_value_opening_total', 20, 4)->default(0);
            $table->decimal('inventory_value_closing_total', 20, 4)->default(0);
            $table->decimal('inventory_value_variation_total', 20, 4)->default(0);

            // Metrici import
            $table->unsignedBigInteger('snapshots_total')->default(0);
            // Diferența în minute între primul și ultimul snapshot din zi (span import WinMentor)
            $table->unsignedSmallInteger('imports_span_minutes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_inventory_kpi_daily');
    }
};
