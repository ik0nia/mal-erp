<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->string('country_of_origin', 2)->nullable()->comment('ISO 3166-1 alpha-2');
            $table->string('customs_tariff_code', 20)->nullable()->comment('Cod HS/NC');
            $table->decimal('vat_rate', 5, 2)->nullable()->default(19.00)->comment('Cotă TVA %');
            $table->decimal('volume_m3', 10, 6)->nullable()->comment('Volum în m³');
            $table->unsignedSmallInteger('warranty_months')->nullable()->comment('Garanție în luni');
            $table->string('certification_codes', 255)->nullable()->comment('CE, ROHS, etc (comma separated)');
            $table->string('msds_link', 500)->nullable()->comment('Link fișă securitate');
            $table->string('storage_conditions', 255)->nullable()->comment('Condiții depozitare');
            $table->boolean('is_fragile')->default(false);
            $table->boolean('is_stackable')->default(true);
            $table->boolean('is_temperature_sensitive')->default(false);
            $table->unsignedInteger('shelf_life_days')->nullable()->comment('Termen valabilitate zile');
        });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropColumn([
                'country_of_origin',
                'customs_tariff_code',
                'vat_rate',
                'volume_m3',
                'warranty_months',
                'certification_codes',
                'msds_link',
                'storage_conditions',
                'is_fragile',
                'is_stackable',
                'is_temperature_sensitive',
                'shelf_life_days',
            ]);
        });
    }
};
