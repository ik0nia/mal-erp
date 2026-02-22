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
        Schema::create('product_price_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('woo_product_id')->constrained('woo_products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->decimal('old_price', 15, 4)->nullable();
            $table->decimal('new_price', 15, 4)->nullable();
            $table->string('source')->default('winmentor_csv');
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();

            $table->index(['woo_product_id', 'location_id', 'changed_at'], 'price_logs_product_location_changed_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_price_logs');
    }
};
