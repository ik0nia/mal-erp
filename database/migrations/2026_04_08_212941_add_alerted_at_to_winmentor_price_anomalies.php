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
        Schema::table('winmentor_price_anomalies', function (Blueprint $table) {
            // Când a fost trimis alertul pentru această anomalie (NULL = nealertat încă)
            $table->timestamp('alerted_at')->nullable()->after('reviewed');
        });
    }

    public function down(): void
    {
        Schema::table('winmentor_price_anomalies', function (Blueprint $table) {
            $table->dropColumn('alerted_at');
        });
    }
};
