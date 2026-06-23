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
            // Cine a trimis comanda spre WinMentor (istoric pe pagina comenzii).
            $table->unsignedBigInteger('winmentor_synced_by')->nullable()->after('winmentor_client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('woo_orders', function (Blueprint $table) {
            $table->dropColumn('winmentor_synced_by');
        });
    }
};
