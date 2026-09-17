<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_hourly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedTinyInteger('hour'); // 0-23
            $table->unsignedInteger('active_seconds')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'day', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_hourly');
    }
};
