<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_sediu_partener_map', function (Blueprint $table) {
            $table->id();
            $table->string('sediu_id', 20)->unique();
            $table->string('partener_wm_id', 20)->index();
            $table->string('denumire_partener');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_sediu_partener_map');
    }
};
