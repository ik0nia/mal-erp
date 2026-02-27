<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cheie: reference_product_id (SKU/EAN) — ales în detrimentul woo_product_id deoarece:
//   1. daily_stock_metrics folosește același identificator ca cheie de grupare
//   2. woo_product_id poate fi reatribuit la reimport; SKU/EAN este stabil ca business key
//   3. Permite join direct pe daily_stock_metrics fără tabel pivot
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_product_velocity_current', function (Blueprint $table) {
            // PK = SKU/EAN; un singur rând per produs, suprascris la fiecare rulare
            $table->string('reference_product_id', 191)->primary();

            // Ziua pentru care au fost calculate rolling windows-urile (de obicei "yesterday")
            $table->date('calculated_for_day');

            // Ieșiri totale per fereastră (max(0, -daily_total_variation) sumat)
            $table->decimal('out_qty_7d',  15, 3)->default(0);
            $table->decimal('out_qty_30d', 15, 3)->default(0);
            $table->decimal('out_qty_90d', 15, 3)->default(0);

            // Rată zilnică medie (out_qty / zile_fereastră) — folosit la estimarea days_left
            $table->decimal('avg_out_qty_7d',  15, 4)->default(0);
            $table->decimal('avg_out_qty_30d', 15, 4)->default(0);
            $table->decimal('avg_out_qty_90d', 15, 4)->default(0);

            // Ultima zi cu mișcare negativă (ieșire de stoc)
            $table->date('last_movement_day')->nullable();
            $table->unsignedSmallInteger('days_since_last_movement')->nullable();

            // Fără created_at — tabelul are întotdeauna starea curentă, nu istoric
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_product_velocity_current');
    }
};
