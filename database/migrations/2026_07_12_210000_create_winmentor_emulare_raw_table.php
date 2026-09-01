<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_emulare_raw', function (Blueprint $table) {
            $table->id();
            $table->string('firma', 50);
            $table->smallInteger('an');
            $table->tinyInteger('luna');
            $table->tinyInteger('zi')->nullable();
            $table->string('id_bon', 20)->nullable();
            $table->string('pozitie', 10)->nullable();
            $table->date('data_bon')->nullable();
            // valoare = totalul bonului (repetat pe fiecare linie a bonului)
            $table->decimal('valoare_bon', 14, 4)->nullable();
            $table->string('nume_client')->nullable();
            // cantitate = câmpul cantVanduta din emulare (cantitatea reală vândută)
            $table->decimal('cantitate', 12, 3)->nullable();
            $table->decimal('pret', 12, 4)->nullable();
            $table->string('den_articol')->nullable();
            $table->string('cod_articol', 100)->nullable();
            $table->string('cod_extern', 100)->nullable();
            $table->string('nr_casa', 10)->nullable();
            $table->string('den_gestiune', 50)->nullable();
            $table->string('simbol_gestiune', 20)->nullable();
            $table->json('raw_row');
            $table->timestamps();

            $table->index(['firma', 'an', 'luna']);
            $table->index('id_bon');
            $table->index('cod_articol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_emulare_raw');
    }
};
