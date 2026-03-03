<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 64)->unique()->index();
            $table->string('email', 254)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('wants_specialist')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_contacts');
    }
};
