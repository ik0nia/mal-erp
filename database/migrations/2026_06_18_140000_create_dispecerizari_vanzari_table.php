<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Strict stratul de CONTROL nou — decizia de dispecerizare per linie de vânzare.
     * Datele vânzării (client, produs, cantitate, preț) NU se dublează aici — se iau
     * live din WinMentor și se împerechează după cheia (tip_doc, doc_id, pozitie).
     */
    public function up(): void
    {
        Schema::create('dispecerizari_vanzari', function (Blueprint $table) {
            $table->id();

            // Cheia care leagă decizia de linia de vânzare live
            $table->string('tip_doc')->comment('F=factură, AE=aviz, BON=bon casă');
            $table->string('doc_id')->comment('nrFactura sau idBon');
            $table->string('pozitie')->default('')->comment('poziție / cod articol');
            $table->date('zi')->nullable()->index()->comment('ziua vânzării — pentru curățare');

            // Decizia (singurul lucru nou pe care îl ținem)
            $table->string('sursa')->nullable()->comment('magazin | depozit | livrare');
            $table->string('status')->default('nou')->comment('nou | pregatit | predat');
            $table->foreignId('decis_de')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decis_at')->nullable();
            $table->text('observatii')->nullable();

            $table->timestamps();

            $table->unique(['tip_doc', 'doc_id', 'pozitie'], 'dispecerizari_doc_unique');
            $table->index(['zi', 'sursa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispecerizari_vanzari');
    }
};
