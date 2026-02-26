<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woo_product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('woo_product_id')->constrained('woo_products')->cascadeOnDelete();
            $table->string('name');           // ex: "Brand", "Material", "Putere (W)"
            $table->text('value');            // ex: "Knauf", "Cupru", "4.5"
            $table->unsignedInteger('woo_attribute_id')->nullable(); // ID global WC (pa_brand=2 etc.)
            $table->boolean('is_visible')->default(true);
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('source')->default('generated'); // 'generated' | 'synced'
            $table->timestamps();

            $table->index('woo_product_id');
            $table->index(['woo_product_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_product_attributes');
    }
};
