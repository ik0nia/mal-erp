<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_review_proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woo_product_id');
            $table->string('product_name');
            $table->string('current_cats');
            $table->unsignedInteger('suggested_cat_id');
            $table->string('suggested_cat_name');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('woo_product_id')->references('id')->on('woo_products')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['status', 'woo_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_review_proposals');
    }
};
