<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_inventory_alert_candidates_daily', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('reference_product_id', 191);

            // Nume produs (denormalizat din woo_products pentru ușurință raportare)
            $table->string('product_name', 500)->nullable();

            // Date stoc din ziua respectivă
            $table->decimal('closing_qty',   15, 3)->default(0);
            $table->decimal('closing_price', 15, 4)->nullable();
            $table->decimal('stock_value',   20, 4)->default(0);

            // Viteză consum (din bi_product_velocity_current la momentul calculului)
            $table->decimal('avg_out_30d', 15, 4)->default(0);

            // Estimare zile rămase (NULL dacă avg_out_30d = 0)
            $table->decimal('days_left_estimate', 10, 1)->nullable();

            // Nivelul de risc
            $table->enum('risk_level', ['P0', 'P1', 'P2']);

            // Flags multiple per produs:
            // out_of_stock | days_left_le_7 | price_spike | days_left_7_to_14 | dead_stock | no_consumption_30d
            $table->json('reason_flags');

            // Fără updated_at: tabelul se șterge și se re-inserează la fiecare rulare (idempotent)
            $table->timestamp('created_at')->useCurrent();

            // Unique constraint — garantează idempotența (DELETE+INSERT per zi)
            $table->unique(['day', 'reference_product_id']);

            // Indexuri pentru query-uri rapide
            $table->index('day');
            $table->index(['risk_level', 'day']);
            $table->index('reference_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_inventory_alert_candidates_daily');
    }
};
