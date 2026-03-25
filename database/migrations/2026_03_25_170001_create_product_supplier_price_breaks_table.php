<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_supplier_price_breaks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_supplier_id');
            $table->decimal('min_qty', 10, 3);
            $table->decimal('max_qty', 10, 3)->nullable();
            $table->decimal('unit_price', 10, 4);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->foreign('product_supplier_id')
                ->references('id')
                ->on('product_suppliers')
                ->cascadeOnDelete();

            $table->index('product_supplier_id');
            $table->index(['product_supplier_id', 'min_qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier_price_breaks');
    }
};
