<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->char('default_currency', 3)->default('RON')->after('name');
        });

        Schema::table('winmentor_intrari_raw', function (Blueprint $table) {
            $table->char('moneda', 3)->nullable()->after('pret');
            $table->decimal('curs_bnr', 10, 4)->nullable()->after('moneda');
        });

        Schema::table('product_purchase_price_logs', function (Blueprint $table) {
            $table->decimal('exchange_rate', 10, 4)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('default_currency'));
        Schema::table('winmentor_intrari_raw', fn (Blueprint $t) => $t->dropColumn(['moneda', 'curs_bnr']));
        Schema::table('product_purchase_price_logs', fn (Blueprint $t) => $t->dropColumn('exchange_rate'));
    }
};
