<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('woo_product_id')->nullable();
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->string('supplier_sku')->nullable();
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_price', 10, 4)->nullable();
            $table->decimal('line_total', 12, 4)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('purchase_request_item_id')->nullable();
            $table->timestamps();

            $table->foreign('woo_product_id')->references('id')->on('woo_products')->nullOnDelete();
            $table->foreign('purchase_request_item_id')->references('id')->on('purchase_request_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
