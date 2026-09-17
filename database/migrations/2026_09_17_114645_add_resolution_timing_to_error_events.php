<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_events', function (Blueprint $table) {
            // opened_at = de când e deschisă (setat la creare + resetat la redeschidere)
            $table->timestamp('opened_at')->nullable()->after('status');
            // resolved_at = momentul rezolvării (pt calculul timpului de rezolvare)
            $table->timestamp('resolved_at')->nullable()->after('opened_at');
        });

        // Backfill retroactiv pentru rândurile existente
        DB::table('error_events')->whereNull('opened_at')->update([
            'opened_at' => DB::raw('first_seen_at'),
        ]);
        DB::table('error_events')->where('status', 'resolved')->whereNull('resolved_at')->update([
            'resolved_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('error_events', function (Blueprint $table) {
            $table->dropColumn(['opened_at', 'resolved_at']);
        });
    }
};
