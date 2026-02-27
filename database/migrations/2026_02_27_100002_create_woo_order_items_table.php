<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woo_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('woo_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('woo_item_id')->nullable();
            $table->unsignedBigInteger('woo_product_id')->nullable();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 10, 4)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_order_items');
    }
};
