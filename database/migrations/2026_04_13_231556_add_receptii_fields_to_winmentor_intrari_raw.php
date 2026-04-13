<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winmentor_intrari_raw', function (Blueprint $table) {
            $table->string('nr_receptie', 50)->nullable()->after('nr_doc');
            $table->string('den_furnizor', 150)->nullable()->after('part_id');
            $table->string('den_articol', 255)->nullable()->after('sku');
            $table->decimal('pret_vanzare', 12, 4)->nullable()->after('pret');
            $table->string('id_comanda_wm', 50)->nullable()->after('den_gestiune');
        });
    }

    public function down(): void
    {
        Schema::table('winmentor_intrari_raw', function (Blueprint $table) {
            $table->dropColumn(['nr_receptie', 'den_furnizor', 'den_articol', 'pret_vanzare', 'id_comanda_wm']);
        });
    }
};
