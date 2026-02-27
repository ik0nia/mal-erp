<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1 rând per (zi, produs) — doar produsele "interesante" (P0/P1/P2).
        // Populate zilnic după bi_product_velocity_current.
        // Idempotent: DELETE ziua + re-INSERT la fiecare rulare.
        Schema::create('bi_inventory_alert_candidates_daily', function (Blueprint $table) {
            $table->date('day');
            $table->string('reference_product_id', 100);

            $table->string('product_name', 500)->nullable();   // din woo_products.name (cache local)

            // Date stoc la momentul zilei
            $table->decimal('closing_qty',   12, 3)->default(0);
            $table->decimal('closing_price', 10, 4)->nullable();
            $table->decimal('stock_value',   14, 2)->default(0); // closing_qty * closing_price

            // Velocity folosită pentru calcul
            $table->decimal('avg_out_30d', 12, 4)->default(0);

            // days_left_estimate = closing_qty / avg_out_30d (NULL dacă avg_out_30d = 0)
            $table->decimal('days_left_estimate', 8, 1)->nullable();

            // Nivel risc
            $table->enum('risk_level', ['P0', 'P1', 'P2']);

            // Array de string-uri: ['out_of_stock','critical_stock','low_stock','price_spike','dead_stock','no_consumption_30d']
            $table->json('reason_flags');

            $table->timestamps();

            // PK compus — upsert idempotent
            $table->primary(['day', 'reference_product_id']);

            // Interogări frecvente în dashboard/BI
            $table->index(['day', 'risk_level'], 'idx_day_risk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_inventory_alert_candidates_daily');
    }
};
