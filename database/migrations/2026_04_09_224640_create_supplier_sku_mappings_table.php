<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_sku_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('sku_furnizor');           // GTIN/cod din documentul furnizorului
            $table->string('denumire_furnizor')->nullable();
            $table->string('sku_erp');                // SKU din WinMentor/ERP
            $table->string('denumire_erp')->nullable();
            $table->enum('source', ['ai', 'manual', 'exact'])->default('ai');
            $table->float('confidence')->default(1.0); // 0-1, scorul AI
            $table->boolean('confirmed')->default(false); // confirmat manual
            $table->timestamps();

            $table->unique(['supplier_id', 'sku_furnizor']);
            $table->index(['supplier_id', 'sku_erp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_sku_mappings');
    }
};
