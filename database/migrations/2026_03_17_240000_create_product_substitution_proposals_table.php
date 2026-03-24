<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_substitution_proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_product_id');
            $table->foreign('source_product_id')->references('id')->on('woo_products')->cascadeOnDelete();
            $table->unsignedBigInteger('proposed_toya_id')->nullable();
            $table->foreign('proposed_toya_id')->references('id')->on('woo_products')->nullOnDelete();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->text('reasoning')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'no_match'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique('source_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_substitution_proposals');
    }
};
