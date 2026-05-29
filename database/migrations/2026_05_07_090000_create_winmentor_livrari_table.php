<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_livrari', function (Blueprint $table) {
            $table->id();

            // Identificatori WinMentor
            $table->string('id_doc')->comment('ID document WinMentor');
            $table->string('pozitie')->default('')->comment('Poziție linie în document');
            $table->string('nr_comanda')->index();
            $table->string('tip_doc')->default('CM');

            // Date comandă
            $table->string('data_comanda')->nullable()->comment('Data din WinMentor (format original)');
            $table->string('part_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('art_id')->nullable()->comment('SKU produs');
            $table->string('produs_name')->nullable();
            $table->decimal('cantitate', 12, 4)->default(0);
            $table->decimal('pret', 12, 4)->default(0);
            $table->decimal('cant_facturata', 12, 4)->default(0);
            $table->string('gestiune')->nullable();
            $table->text('observatii')->nullable();

            // Tracking temporal
            $table->timestamp('first_seen_at')->nullable()->comment('Prima detectare în API');
            $table->timestamp('last_seen_at')->nullable()->comment('Ultima confirmare în API');
            $table->timestamp('disappeared_at')->nullable()->comment('Când nu a mai fost găsit în API (facturat/anulat)');

            $table->timestamps();

            // Unicitate per linie document
            $table->unique(['id_doc', 'pozitie'], 'wm_livrari_doc_poz_unique');
            $table->index('disappeared_at');
            $table->index('part_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_livrari');
    }
};
