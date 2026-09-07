<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Căsuțele Easybox — sincronizate de pe site (wp_sameday_locker) pentru
        // selectorul din editorul de transport al comenzilor.
        Schema::create('sameday_lockers', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('locker_id')->unique();
            $t->string('name');
            $t->string('county', 100)->nullable();
            $t->string('city', 150)->nullable();
            $t->string('address')->nullable();
            $t->string('postal_code', 20)->nullable();
            $t->timestamps();
            $t->index(['county', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sameday_lockers');
    }
};
