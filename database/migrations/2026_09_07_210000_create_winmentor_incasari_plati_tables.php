<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['winmentor_incasari_raw', 'winmentor_plati_raw'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('firma', 50)->index();
                $t->smallInteger('an');
                $t->tinyInteger('luna');
                $t->date('data')->nullable();
                $t->string('pozitie', 20)->nullable();
                $t->string('document_ref', 100)->nullable()->index();
                $t->string('part_id', 50)->nullable()->index();
                $t->decimal('suma', 14, 2)->nullable();
                $t->json('raw_row');
                $t->timestamps();
                $t->index(['firma', 'an', 'luna']);
                $t->index('data');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_incasari_raw');
        Schema::dropIfExists('winmentor_plati_raw');
    }
};
