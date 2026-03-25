<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_product_margin_current', function (Blueprint $table) {
            $table->string('reference_product_id', 191)->primary();
            $table->date('calculated_for_day');
            $table->decimal('selling_price', 15, 4);
            $table->decimal('purchase_price', 15, 4)->nullable();
            $table->string('purchase_price_source', 50); // 'purchase_log', 'product_supplier', 'none'
            $table->decimal('margin_amount', 15, 4);
            $table->decimal('margin_pct', 6, 2);
            $table->decimal('stock_qty', 15, 3);
            $table->decimal('stock_value_retail', 20, 4);
            $table->decimal('stock_value_cost', 20, 4)->nullable();
            $table->decimal('stock_margin_total', 20, 4)->nullable();
            $table->unsignedInteger('supplier_id')->nullable();
            $table->string('supplier_name', 255)->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('margin_pct');
            $table->index('stock_margin_total');
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_product_margin_current');
    }
};
