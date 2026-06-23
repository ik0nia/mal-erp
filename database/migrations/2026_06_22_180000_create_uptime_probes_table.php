<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uptime_probes', function (Blueprint $table) {
            $table->id();
            $table->string('target', 32);            // 'app' | 'bridge'
            $table->boolean('is_up');
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('checked_at');
            $table->index(['target', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_probes');
    }
};
