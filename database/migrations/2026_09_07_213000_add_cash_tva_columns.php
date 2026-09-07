<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Facturi/avize încasate cash prin bon la casă — semnal din /vanzari/ext
        // (rând S pe numărul documentului); watch-ul îl calcula dar nu-l persista.
        Schema::create('winmentor_facturi_cash', function (Blueprint $t) {
            $t->id();
            $t->string('firma', 50);
            $t->smallInteger('an');
            $t->tinyInteger('luna');
            $t->string('nr_factura', 50);
            $t->timestamp('detected_at')->nullable();
            $t->unique(['firma', 'an', 'luna', 'nr_factura'], 'uq_facturi_cash');
        });

        Schema::table('winmentor_intrari_raw', function (Blueprint $t) {
            $t->decimal('cota_tva', 5, 2)->nullable()->after('pret_vanzare');
            $t->string('serie_factura', 50)->nullable()->after('nr_doc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_facturi_cash');
        Schema::table('winmentor_intrari_raw', function (Blueprint $t) {
            $t->dropColumn(['cota_tva', 'serie_factura']);
        });
    }
};
