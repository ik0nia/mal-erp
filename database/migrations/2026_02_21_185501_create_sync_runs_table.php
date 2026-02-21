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
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->string('type');
            $table->string('status');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
            $table->index(['connection_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
