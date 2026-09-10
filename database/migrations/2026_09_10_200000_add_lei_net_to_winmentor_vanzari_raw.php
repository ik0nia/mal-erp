<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valoare netă precalculată per linie de vânzare + motivul excluderii.
 *
 * Exportul WinMentor «vânzări» conține și documente care NU sunt vânzări nete:
 * refacturări de avize (aceeași marfă pe AE și pe F), facturi de avans (se
 * stornează ulterior), vânzări de imobilizări (terenuri), iar bonurile de casă
 * au prețul CU TVA spre deosebire de restul liniilor. Sumarea brută
 * cantitate*pret supraestima vânzările cu 20-45% față de cifra de afaceri.
 * Vezi App\Services\Winmentor\VanzariNetService pentru reguli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winmentor_vanzari_raw', function (Blueprint $table) {
            $table->decimal('lei_net', 14, 2)->nullable()->after('pret');
            $table->string('motiv_exclus', 30)->nullable()->after('lei_net');
        });
    }

    public function down(): void
    {
        Schema::table('winmentor_vanzari_raw', function (Blueprint $table) {
            $table->dropColumn(['lei_net', 'motiv_exclus']);
        });
    }
};
