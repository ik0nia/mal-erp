<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            // Câte bucăți sunt într-o cutie interioară (Inner Box)
            // Ex: 10 burghie preambalate împreună într-o cutie mică
            $table->unsignedSmallInteger('qty_per_inner_box')->nullable()->after('dim_height')
                ->comment('Buc/cutie interioară (Inner Box)');

            // Câte bucăți sunt într-un carton master de transport (Master Carton)
            $table->unsignedSmallInteger('qty_per_carton')->nullable()->after('qty_per_inner_box')
                ->comment('Buc/carton master (Master Carton)');

            // Câte cartoane master intră pe un palet
            $table->unsignedSmallInteger('cartons_per_pallet')->nullable()->after('qty_per_carton')
                ->comment('Cartoane/palet');

            // EAN-ul cartonului master (pentru recepție marfă cu scanner)
            $table->string('ean_carton', 30)->nullable()->after('cartons_per_pallet')
                ->comment('EAN carton master (pentru recepție cu scanner)');
        });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropColumn(['qty_per_inner_box', 'qty_per_carton', 'cartons_per_pallet', 'ean_carton']);
        });
    }
};
