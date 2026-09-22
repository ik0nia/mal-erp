<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jurnal de navigare per-utilizator: ce pagini a deschis (doar accesări reale de pagini,
 * nu asset-uri / polling Livewire). Alimentat de middleware-ul UpdateLastActivity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_page_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 8)->default('GET');
            $table->string('path', 512);
            $table->string('route_name')->nullable();
            $table->string('title')->nullable();
            $table->timestamp('visited_at')->index();

            $table->index(['user_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_page_visits');
    }
};
