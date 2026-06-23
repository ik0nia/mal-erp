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
        Schema::table('woo_orders', function (Blueprint $table) {
            // Tracking import comandă client în WinMentor (anti-duplicare).
            $table->string('winmentor_sync_status')->nullable()->after('total'); // synced | failed
            $table->timestamp('winmentor_synced_at')->nullable()->after('winmentor_sync_status');
            $table->string('winmentor_client_id')->nullable()->after('winmentor_synced_at');
            $table->text('winmentor_sync_error')->nullable()->after('winmentor_client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('woo_orders', function (Blueprint $table) {
            $table->dropColumn([
                'winmentor_sync_status',
                'winmentor_synced_at',
                'winmentor_client_id',
                'winmentor_sync_error',
            ]);
        });
    }
};
