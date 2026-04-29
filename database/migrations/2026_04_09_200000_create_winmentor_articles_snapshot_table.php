<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_articles_snapshot', function (Blueprint $table) {
            $table->id();
            $table->string('cod_extern', 100)->unique();   // SKU din WinMentor
            $table->string('denumire', 500);               // Denumire curentă
            $table->timestamps();

            $table->index('denumire');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_articles_snapshot');
    }
};
