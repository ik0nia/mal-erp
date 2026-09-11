<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GetStergeriProduse raportează ștergerile după codul INTERN WinMentor, dar
     * snapshot-ul ținea doar codul extern → o ștergere nu putea fi legată de nimic.
     * Coloana se populează din listarea /api/articole (winmentor:check-articole-sterse).
     */
    public function up(): void
    {
        Schema::table('winmentor_articles_snapshot', function (Blueprint $table) {
            $table->string('cod_intern', 32)->nullable()->after('cod_extern')->index();
        });
    }

    public function down(): void
    {
        Schema::table('winmentor_articles_snapshot', function (Blueprint $table) {
            $table->dropColumn('cod_intern');
        });
    }
};
