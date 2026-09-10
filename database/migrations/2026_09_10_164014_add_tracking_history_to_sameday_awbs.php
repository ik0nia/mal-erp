<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sameday_awbs', function (Blueprint $table) {
            // istoricul complet de tracking (summary+history) salvat local —
            // AWB-urile încheiate nu mai generează apeluri către Sameday
            $table->json('tracking_history')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('sameday_awbs', function (Blueprint $table) {
            $table->dropColumn('tracking_history');
        });
    }
};
