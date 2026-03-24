<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->unsignedBigInteger('substituted_by_id')->nullable()->after('id')
                ->comment('ID-ul produsului care înlocuiește acest produs la achiziții viitoare');
            $table->foreign('substituted_by_id')->references('id')->on('woo_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropForeign(['substituted_by_id']);
            $table->dropColumn('substituted_by_id');
        });
    }
};
