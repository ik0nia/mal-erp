<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sameday_awbs', function (Blueprint $table) {
            // ultimul status cunoscut de la curier (tracking), actualizat la cerere/cron
            $table->string('courier_status')->nullable()->after('status');
            $table->timestamp('courier_status_at')->nullable()->after('courier_status');
        });
    }

    public function down(): void
    {
        Schema::table('sameday_awbs', function (Blueprint $table) {
            $table->dropColumn(['courier_status', 'courier_status_at']);
        });
    }
};
