<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_comenzi', function (Blueprint $table) {
            $table->id();

            // Identificatori WinMentor
            $table->string('id_doc')->comment('ID document WinMentor (nrDocument)');
            $table->string('pozitie')->default('')->comment('Poziție linie în document');
            $table->string('nr_comanda')->index();
            $table->string('serie')->default('')->comment('Serie document (CC/MALOF/HPL... — non-CM)');

            // Date comandă
            $table->string('data_comanda')->nullable()->comment('Data din WinMentor (format original)');
            $table->string('data_livrare')->nullable();
            $table->string('part_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('art_id')->nullable()->comment('SKU produs');
            $table->string('produs_name')->nullable();
            $table->decimal('cantitate', 12, 4)->default(0);
            $table->decimal('pret', 12, 4)->default(0);
            $table->decimal('cant_comanda', 12, 4)->default(0)->comment('Cantitate comandată (cantComanda)');
            $table->string('gestiune')->nullable();
            $table->text('observatii')->nullable();

            // Tracking temporal
            $table->timestamp('first_seen_at')->nullable()->comment('Prima detectare în API');
            $table->timestamp('last_seen_at')->nullable()->comment('Ultima confirmare în API (= încă deschisă)');
            $table->timestamp('disappeared_at')->nullable()->comment('Când a dispărut din nefacturate (facturat/livrat/anulat)');

            // Rezolvare factură/aviz (euristică — WinMentor nu leagă factura de comandă)
            $table->string('factura_tip')->nullable()->comment('F=factură, AE/==aviz');
            $table->string('factura_nr')->nullable();
            $table->string('factura_serie')->nullable();
            $table->string('factura_data')->nullable();
            $table->boolean('factura_estimat')->default(false)->comment('Potrivire euristică, nu legătură directă');

            $table->timestamps();

            // Unicitate per linie document
            $table->unique(['id_doc', 'pozitie'], 'wm_comenzi_doc_poz_unique');
            $table->index('disappeared_at');
            $table->index('part_id');
            $table->index('serie');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_comenzi');
    }
};
