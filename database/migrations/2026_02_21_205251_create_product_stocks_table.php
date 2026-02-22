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
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('woo_product_id')->constrained('woo_products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('price', 15, 4)->nullable();
            $table->string('source')->default('winmentor_csv');
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['woo_product_id', 'location_id']);
            $table->index(['location_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_stocks');
    }
};
