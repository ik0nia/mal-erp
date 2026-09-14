<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Istoricul versiunilor WinMentor / DocImpServer / MentorAPI observate pe server.
 * O linie nouă la fiecare schimbare de versiune = un update aplicat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_version_history', function (Blueprint $table) {
            $table->id();
            $table->string('component', 32);              // mentor | docimpserver | mentorapi
            $table->string('version', 64);                // versiunea nouă
            $table->string('previous_version', 64)->nullable(); // versiunea de dinainte (null = baseline)
            $table->timestamp('detected_at');             // când am observat schimbarea
            $table->timestamps();

            $table->index(['component', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_version_history');
    }
};
