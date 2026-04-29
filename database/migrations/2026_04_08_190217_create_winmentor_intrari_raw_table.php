<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_intrari_raw', function (Blueprint $table) {
            $table->id();
            $table->string('firma', 50);
            $table->smallInteger('an');
            $table->tinyInteger('luna');
            $table->string('part_id', 50)->nullable();
            $table->date('data_intrare')->nullable();
            $table->string('nr_doc', 50)->nullable();
            $table->string('sku', 100)->nullable();
            $table->decimal('cantitate', 12, 3)->nullable();
            $table->string('uom', 20)->nullable();
            $table->decimal('pret', 12, 4)->nullable();
            $table->string('den_gestiune', 50)->nullable();
            $table->json('raw_row');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['firma', 'an', 'luna']);
            $table->index(['sku', 'processed_at']);
            $table->index('part_id');
        });

        Schema::create('winmentor_intrari_sync', function (Blueprint $table) {
            $table->id();
            $table->string('firma', 50);
            $table->smallInteger('an');
            $table->tinyInteger('luna');
            $table->integer('rows_fetched')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['firma', 'an', 'luna']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_intrari_sync');
        Schema::dropIfExists('winmentor_intrari_raw');
    }
};
