<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sediile/punctele de livrare ale partenerilor WinMentor — extrase din
        // câmpurile paralele "~" ale nomenclatorului (denumiriSedii etc.).
        // Rezolvă local: adresele de livrare pe fișa clientului + maparea
        // sediu→partener din dispecerizări (fără scanări API repetate).
        Schema::create('winmentor_sedii', function (Blueprint $t) {
            $t->id();
            $t->string('partener_wm_id', 20)->index();
            $t->unsignedSmallInteger('pozitie')->default(0);
            $t->string('denumire')->index();
            $t->string('localitate')->nullable();
            $t->string('cod_postal', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('tip', 50)->nullable();
            $t->timestamps();
            $t->unique(['partener_wm_id', 'pozitie']);
        });

        Schema::table('winmentor_parteneri', function (Blueprint $t) {
            $t->index('cod_extern'); // fallback legare: ID-uri legacy pe documentele 2025+
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_sedii');
        Schema::table('winmentor_parteneri', function (Blueprint $t) {
            $t->dropIndex(['cod_extern']);
        });
    }
};
