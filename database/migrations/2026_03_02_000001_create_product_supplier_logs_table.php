<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_supplier_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('woo_product_id')->constrained('woo_products')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action')->default('associated')->comment('associated | created_and_associated');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier_logs');
    }
};
