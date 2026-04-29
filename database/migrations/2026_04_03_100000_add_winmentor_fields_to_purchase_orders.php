<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('winmentor_sync_status', 20)->nullable()->after('received_notes')->comment('null=nesincronizat, pending=in asteptare, synced=sincronizat, failed=eroare');
            $table->text('winmentor_sync_error')->nullable()->after('winmentor_sync_status');
            $table->string('winmentor_order_nr')->nullable()->after('winmentor_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['winmentor_sync_status', 'winmentor_sync_error', 'winmentor_order_nr']);
        });
    }
};
