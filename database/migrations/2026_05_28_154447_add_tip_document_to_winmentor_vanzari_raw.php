<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winmentor_vanzari_raw', function (Blueprint $table) {
            $table->string('tip_document', 10)->nullable()->after('clasa_articol');
            $table->string('den_articol', 255)->nullable()->after('tip_document');
            $table->string('discount', 20)->nullable()->after('den_articol');
            $table->string('serie_document', 50)->nullable()->after('discount');
            $table->string('observatii_factura', 500)->nullable()->after('serie_document');
            $table->string('localitate_client', 255)->nullable()->after('observatii_factura');

            $table->index('tip_document');
        });

        // Backfill tip_document din nr_factura pattern (date istorice)
        DB::statement("
            UPDATE winmentor_vanzari_raw SET tip_document = CASE
                WHEN nr_factura REGEXP '^2[0-9]{5}$' THEN 'S'
                ELSE '='
            END
            WHERE tip_document IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('winmentor_vanzari_raw', function (Blueprint $table) {
            $table->dropIndex(['tip_document']);
            $table->dropColumn(['tip_document', 'den_articol', 'discount', 'serie_document', 'observatii_factura', 'localitate_client']);
        });
    }
};
