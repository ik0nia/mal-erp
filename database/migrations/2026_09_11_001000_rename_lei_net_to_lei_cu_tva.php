<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sumele curățate se afișează CU TVA (decizia utilizatorului 2026-09-11):
 * avize/facturi au prețul fără TVA → se adaugă cota per linie;
 * bonurile de casă au deja prețul cu TVA → rămân ca atare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winmentor_vanzari_raw', function (Blueprint $table) {
            $table->renameColumn('lei_net', 'lei_cu_tva');
        });
    }

    public function down(): void
    {
        Schema::table('winmentor_vanzari_raw', function (Blueprint $table) {
            $table->renameColumn('lei_cu_tva', 'lei_net');
        });
    }
};
