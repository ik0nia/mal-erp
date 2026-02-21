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
        Schema::create('woo_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->unsignedBigInteger('woo_id');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->longText('description')->nullable();
            $table->unsignedBigInteger('parent_woo_id')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('woo_categories')->nullOnDelete();
            $table->text('image_url')->nullable();
            $table->integer('menu_order')->nullable();
            $table->integer('count')->nullable();
            $table->json('data');
            $table->timestamps();

            $table->unique(['connection_id', 'woo_id']);
            $table->index(['connection_id', 'parent_woo_id']);
        });

        Schema::create('woo_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->unsignedBigInteger('woo_id');
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->string('sku')->nullable();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->longText('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('regular_price')->nullable();
            $table->string('sale_price')->nullable();
            $table->string('price')->nullable();
            $table->string('stock_status')->nullable();
            $table->boolean('manage_stock')->nullable();
            $table->unsignedBigInteger('woo_parent_id')->nullable();
            $table->text('main_image_url')->nullable();
            $table->json('data');
            $table->timestamps();

            $table->unique(['connection_id', 'woo_id']);
            $table->index(['connection_id', 'sku']);
        });

        Schema::create('woo_product_category', function (Blueprint $table) {
            $table->foreignId('woo_product_id')->constrained('woo_products')->cascadeOnDelete();
            $table->foreignId('woo_category_id')->constrained('woo_categories')->cascadeOnDelete();

            $table->unique(['woo_product_id', 'woo_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('woo_product_category');
        Schema::dropIfExists('woo_products');
        Schema::dropIfExists('woo_categories');
    }
};
