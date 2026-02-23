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
        Schema::create('daily_stock_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->foreignId('woo_product_id')->constrained('woo_products')->cascadeOnDelete();
            $table->decimal('opening_total_qty', 15, 3)->default(0);
            $table->decimal('closing_total_qty', 15, 3)->default(0);
            $table->decimal('opening_available_qty', 15, 3)->default(0);
            $table->decimal('closing_available_qty', 15, 3)->default(0);
            $table->decimal('opening_sell_price', 15, 4)->nullable();
            $table->decimal('closing_sell_price', 15, 4)->nullable();
            $table->decimal('daily_total_variation', 15, 3)->default(0);
            $table->decimal('daily_available_variation', 15, 3)->default(0);
            $table->decimal('closing_sales_value', 20, 4)->default(0);
            $table->decimal('daily_sales_value_variation', 20, 4)->default(0);
            $table->decimal('min_available_qty', 15, 3)->default(0);
            $table->decimal('max_available_qty', 15, 3)->default(0);
            $table->unsignedInteger('snapshots_count')->default(0);
            $table->dateTime('first_snapshot_at')->nullable();
            $table->dateTime('last_snapshot_at')->nullable();
            $table->timestamps();

            $table->unique(['day', 'woo_product_id']);
            $table->index(['woo_product_id', 'day'], 'daily_stock_metrics_product_day_idx');
            $table->index('day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_stock_metrics');
    }
};
