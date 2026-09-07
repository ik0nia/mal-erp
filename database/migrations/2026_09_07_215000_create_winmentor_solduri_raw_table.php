<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scadențarul OFICIAL WinMentor (GetSolduriExt clienți / GetSolduriFurn furnizori):
        // rest de plată per factură, direct din contabilitatea Mentor. Sursa de adevăr
        // pentru pagina Scadențar — sync greu (minute pe COM), rulat nocturn.
        Schema::create('winmentor_solduri_raw', function (Blueprint $t) {
            $t->id();
            $t->string('firma', 50);
            $t->enum('directie', ['client', 'furnizor'])->index();
            $t->string('part_id', 50)->nullable()->index();
            $t->string('tip_document', 20)->nullable();
            $t->string('nr_factura', 50)->nullable()->index();
            $t->date('data_factura')->nullable();
            $t->date('termen_plata')->nullable();
            $t->decimal('valoare_factura', 14, 2)->nullable();
            $t->decimal('rest_de_plata', 14, 2)->nullable();
            $t->string('moneda', 10)->nullable();
            $t->string('locatie', 100)->nullable();
            $t->string('marca_agent', 20)->nullable();
            $t->json('raw_row');
            $t->timestamp('fetched_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_solduri_raw');
    }
};
