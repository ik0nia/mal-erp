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
        Schema::table('winmentor_vanzari_raw', function (Blueprint $table) {
            $table->decimal('valoare_factura', 14, 2)->nullable()->after('localitate_client');
            $table->string('data_scadenta', 20)->nullable()->after('valoare_factura');
            $table->string('data_emitere', 20)->nullable()->after('data_scadenta');
            $table->string('cota_tva', 5)->nullable()->after('data_emitere');

            $table->index('data_scadenta');
        });
    }

    public function down(): void
    {
        Schema::table('winmentor_vanzari_raw', function (Blueprint $table) {
            $table->dropIndex(['data_scadenta']);
            $table->dropColumn(['valoare_factura', 'data_scadenta', 'data_emitere', 'cota_tva']);
        });
    }
};
