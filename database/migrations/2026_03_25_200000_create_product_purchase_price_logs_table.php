<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_purchase_price_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('woo_product_id')->constrained('woo_products')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_name_raw')->nullable();
            $table->decimal('unit_price', 10, 4);
            $table->string('currency', 3)->default('RON');
            $table->date('acquired_at')->nullable();
            $table->string('source', 50)->default('manual')->comment('winmentor_import | crm | manual');
            $table->string('uom', 20)->nullable()->comment('Unitate masura din sursa');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['woo_product_id', 'acquired_at']);
            $table->index(['supplier_id', 'acquired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_purchase_price_logs');
    }
};
