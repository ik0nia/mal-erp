<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winmentor_intrari_unmatched_sku', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 100);
            $table->string('firma', 50);
            $table->string('last_part_id', 50)->nullable();
            $table->string('last_supplier_name', 255)->nullable();
            $table->unsignedInteger('appearances_count')->default(1);
            $table->decimal('last_price', 12, 4)->nullable();
            $table->string('last_uom', 20)->nullable();
            $table->date('first_seen_at')->nullable();
            $table->date('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['sku', 'firma']);
            $table->index('firma');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winmentor_intrari_unmatched_sku');
    }
};
