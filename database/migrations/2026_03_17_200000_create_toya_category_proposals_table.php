<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toya_category_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('toya_path', 500)->unique()->comment('Path-ul normalizat din feed Toya (ex: Unelte de grădină / Foarfece)');
            $table->unsignedInteger('product_count')->default(0)->comment('Număr produse cu acest path');
            $table->foreignId('proposed_woo_category_id')->nullable()->constrained('woo_categories')->nullOnDelete();
            $table->json('alternative_category_ids')->nullable()->comment('ID-uri categorii alternative sugerate de AI');
            $table->decimal('confidence', 3, 2)->nullable()->comment('Scor încredere AI 0-1');
            $table->text('reasoning')->nullable()->comment('Explicația AI');
            $table->enum('status', ['pending', 'approved', 'rejected', 'no_match'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('proposed_woo_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toya_category_proposals');
    }
};
