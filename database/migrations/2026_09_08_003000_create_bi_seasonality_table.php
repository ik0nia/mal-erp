<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Indici de sezonalitate lunari per produs, din vânzările reale multi-anuale.
        // index = media vânzărilor lunii calendaristice / media lunară generală (1.0 = neutru).
        Schema::create('bi_seasonality', function (Blueprint $t) {
            $t->id();
            $t->string('reference_product_id', 100);
            $t->tinyInteger('luna');
            $t->decimal('idx', 6, 3);
            $t->unsignedSmallInteger('luni_cu_vanzari');
            $t->timestamp('computed_at')->nullable();
            $t->unique(['reference_product_id', 'luna']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_seasonality');
    }
};
