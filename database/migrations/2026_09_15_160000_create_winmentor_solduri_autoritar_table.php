<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot al soldului AUTORITAR per partener (getSoldPartener din WinMentor).
 * Scadențarul (winmentor_solduri_raw) conține facturi închise/duplicate care apar
 * ca deschise → totalul „de plătit furnizori" iese umflat. Aici stocăm soldul net
 * real per partener, reîmprospătat nocturn, pentru totaluri corecte pe pagină.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_solduri_autoritar', function (Blueprint $table) {
            $table->id();
            $table->string('part_id')->index();
            $table->string('directie', 16); // 'furnizor' | 'client'
            $table->decimal('sold', 14, 2)->default(0); // semnat, cum îl întoarce Mentor
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['part_id', 'directie']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_solduri_autoritar');
    }
};
