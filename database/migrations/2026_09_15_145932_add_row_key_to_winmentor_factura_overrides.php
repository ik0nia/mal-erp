<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Marcajele vechi (cheie pe nr_factura) erau eronate — avansurile au nr_factura duplicat
        // („EX 1" apare de multe ori) → cascadă. Golim și trecem pe cheie de RÂND unic.
        DB::table('winmentor_factura_overrides')->truncate();

        Schema::table('winmentor_factura_overrides', function (Blueprint $table) {
            $table->dropUnique(['part_id', 'nr_factura', 'directie']);
            $table->string('row_key', 80)->after('nr_factura'); // md5(nr|data|rest) — unic per rând scadențar
            $table->string('data_factura', 20)->nullable()->after('row_key');
            $table->unique(['part_id', 'row_key', 'directie']);
        });
    }

    public function down(): void
    {
        Schema::table('winmentor_factura_overrides', function (Blueprint $table) {
            $table->dropUnique(['part_id', 'row_key', 'directie']);
            $table->dropColumn(['row_key', 'data_factura']);
            $table->unique(['part_id', 'nr_factura', 'directie']);
        });
    }
};
