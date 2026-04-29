<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_price_anomalies', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 100);
            $table->unsignedBigInteger('woo_product_id')->nullable();
            $table->string('firma', 50);

            // Tipul anomaliei
            $table->string('anomaly_type', 50); // price_spike, price_drop, supplier_change, possible_sku_reuse

            // Prețuri
            $table->decimal('previous_price', 12, 4)->nullable();
            $table->decimal('new_price', 12, 4)->nullable();
            $table->decimal('price_change_pct', 8, 2)->nullable(); // % schimbare

            // Furnizori
            $table->string('previous_part_id', 50)->nullable();
            $table->string('new_part_id', 50)->nullable();
            $table->unsignedBigInteger('previous_supplier_id')->nullable();
            $table->unsignedBigInteger('new_supplier_id')->nullable();

            // Date
            $table->date('previous_date')->nullable();
            $table->date('new_date')->nullable();

            // Referință
            $table->unsignedBigInteger('intrare_raw_id')->nullable();

            // Review
            $table->boolean('reviewed')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['sku', 'anomaly_type']);
            $table->index(['woo_product_id', 'anomaly_type']);
            $table->index('reviewed');
            $table->index('firma');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_price_anomalies');
    }
};
