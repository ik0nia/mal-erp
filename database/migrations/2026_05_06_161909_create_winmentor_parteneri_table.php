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
        Schema::create('winmentor_parteneri', function (Blueprint $table) {
            $table->id();
            $table->string('wm_id', 20)->unique()->index();
            $table->string('denumire', 255);
            $table->string('cod_fiscal', 50)->default('');
            $table->string('localitate', 255)->default('');
            $table->string('adresa', 500)->default('');
            $table->string('telefon', 100)->default('');
            $table->string('persoana_contact', 255)->default('');
            $table->string('clasa', 100)->default('');
            $table->string('categ_pret', 100)->default('');
            $table->string('agent', 255)->default('');
            $table->string('discount', 50)->default('');
            $table->string('cod_extern', 50)->default('');
            $table->boolean('blocat')->default(false);
            $table->string('moneda', 10)->default('Lei');
            $table->string('tara', 50)->default('');
            $table->text('observatii')->nullable();
            $table->string('pj_pf', 10)->default('');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('winmentor_parteneri');
    }
};
