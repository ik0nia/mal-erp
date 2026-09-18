<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indici de sezonalitate lunari PER CATEGORIE, din vânzările istorice multi-anuale.
 * category_id = 0 → curba GLOBALĂ (fallback pentru produse fără categorie / categorii
 * fără date). Indicele e median între ani, renormalizat la medie 1.0, clamp 0.5–2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_seasonality_category', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woo_category_id')->index(); // 0 = global
            $table->unsignedTinyInteger('month'); // 1..12
            $table->decimal('seasonal_index', 5, 3)->default(1.000);
            $table->unsignedTinyInteger('sample_years')->default(0); // ani compleți folosiți
            $table->unsignedInteger('sample_lines')->default(0);
            $table->string('source', 12)->default('own'); // own | parent | global
            $table->date('computed_for')->nullable();
            $table->timestamps();

            $table->unique(['woo_category_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_seasonality_category');
    }
};
