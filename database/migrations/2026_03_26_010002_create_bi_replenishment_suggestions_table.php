<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_replenishment_suggestions', function (Blueprint $table) {
            $table->id();
            $table->date('calculated_for_day');
            $table->unsignedInteger('woo_product_id');
            $table->string('reference_product_id', 191)->nullable();
            $table->string('product_name', 500)->nullable();
            $table->decimal('current_stock', 15, 3)->default(0);
            $table->decimal('avg_daily_consumption', 10, 4)->default(0);
            $table->decimal('days_of_stock', 10, 1)->default(0);
            $table->integer('lead_days')->default(7);
            $table->decimal('safety_stock', 10, 2)->default(0);
            $table->decimal('reorder_point', 15, 3)->default(0);
            $table->decimal('suggested_qty', 15, 3)->default(0);
            $table->decimal('estimated_cost', 20, 4)->default(0);
            $table->decimal('margin_pct', 6, 2)->nullable();
            $table->string('abc_class', 1)->nullable();
            $table->enum('priority', ['urgent', 'soon', 'planned']);
            $table->unsignedInteger('supplier_id')->nullable();
            $table->string('supplier_name', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['calculated_for_day', 'woo_product_id'], 'bi_repl_day_product_unique');
            $table->index(['calculated_for_day', 'priority'], 'bi_repl_day_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_replenishment_suggestions');
    }
};
