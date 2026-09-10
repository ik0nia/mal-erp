<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Încasările bancare per client, aduse cu GetIncasariClienti (apel per partener
 * + interval) — COMPLET, spre deosebire de GetIncasariLuna (winmentor_incasari_raw)
 * care omite majoritatea încasărilor (constatat 2026-09-11: Mivinia 3/25).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_incasari_clienti', function (Blueprint $table) {
            $table->id();
            $table->string('firma', 20)->default('MAL2019');
            $table->string('part_id', 30)->index();
            $table->date('data')->nullable();
            $table->string('document_ref', 60)->nullable();
            $table->decimal('suma', 12, 2)->nullable();
            $table->text('detalii_facturi')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_incasari_clienti');
    }
};
