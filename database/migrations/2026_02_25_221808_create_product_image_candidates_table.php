<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('woo_product_id')->constrained('woo_products')->cascadeOnDelete();
            $table->string('search_query');
            $table->text('image_url');
            $table->text('thumbnail_url')->nullable();
            $table->text('source_page_url')->nullable();
            $table->string('image_title')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();

            $table->index(['woo_product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_candidates');
    }
};
