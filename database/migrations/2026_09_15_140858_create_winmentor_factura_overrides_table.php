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
        Schema::create('winmentor_factura_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('part_id', 40);                     // wm_id furnizor
            $table->string('nr_factura', 60);
            $table->string('directie', 12)->default('furnizor');
            $table->string('action', 16)->default('settled');  // settled | phantom
            $table->decimal('settled_amount', 15, 2)->nullable(); // sumă plătită găsită (auto)
            $table->string('source', 16)->default('manual');   // manual | auto_bank
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->unique(['part_id', 'nr_factura', 'directie']);
            $table->index('part_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('winmentor_factura_overrides');
    }
};
