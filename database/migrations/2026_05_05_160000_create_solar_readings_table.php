<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('solar_readings', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->unique(); // YYYY-MM
            $table->decimal('produced_kwh',      8, 2)->default(0); // total produs panouri
            $table->decimal('self_consumed_kwh', 8, 2)->default(0); // consum direct din panouri
            $table->decimal('battery_in_kwh',    8, 2)->default(0); // încărcat în baterie
            $table->decimal('battery_out_kwh',   8, 2)->default(0); // descărcat din baterie
            $table->decimal('exported_kwh',      8, 2)->default(0); // exportat la rețea
            $table->decimal('imported_kwh',      8, 2)->default(0); // importat din rețea
            $table->decimal('tariff_import',     6, 4)->default(0); // RON/kWh plătit
            $table->decimal('tariff_export',     6, 4)->default(0); // RON/kWh primit pt export
            $table->decimal('invoice_amount',    8, 2)->nullable(); // factură efectivă RON
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solar_readings');
    }
};
