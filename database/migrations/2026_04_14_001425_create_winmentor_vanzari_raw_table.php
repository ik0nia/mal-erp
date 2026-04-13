<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_vanzari_raw', function (Blueprint $table) {
            $table->id();
            $table->string('firma', 50);
            $table->smallInteger('an');
            $table->tinyInteger('luna');
            $table->tinyInteger('zi')->nullable();
            $table->string('part_id', 50)->nullable();
            $table->string('nr_factura', 50)->nullable();
            $table->string('sku', 100)->nullable();
            $table->decimal('cantitate', 12, 3)->nullable();
            $table->string('uom', 20)->nullable();
            $table->decimal('pret', 12, 4)->nullable();
            $table->string('den_gestiune', 20)->nullable();
            $table->string('cod_fiscal_client', 50)->nullable();
            $table->string('adresa_client', 255)->nullable();
            $table->string('marca_agent', 20)->nullable();
            $table->decimal('valoare_totala', 14, 4)->nullable();
            $table->string('clasa_articol', 50)->nullable();
            $table->json('raw_row');
            $table->timestamps();

            $table->index(['firma', 'an', 'luna']);
            $table->index(['sku', 'an', 'luna']);
            $table->index('part_id');
            $table->index('nr_factura');
        });

        Schema::create('winmentor_vanzari_sync', function (Blueprint $table) {
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
        Schema::dropIfExists('winmentor_vanzari_sync');
        Schema::dropIfExists('winmentor_vanzari_raw');
    }
};
