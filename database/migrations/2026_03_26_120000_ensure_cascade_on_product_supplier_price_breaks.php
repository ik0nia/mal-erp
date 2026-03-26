<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_supplier_price_breaks', function (Blueprint $table) {
            $table->dropForeign(['product_supplier_id']);

            $table->foreign('product_supplier_id')
                ->references('id')
                ->on('product_suppliers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_supplier_price_breaks', function (Blueprint $table) {
            $table->dropForeign(['product_supplier_id']);

            $table->foreign('product_supplier_id')
                ->references('id')
                ->on('product_suppliers');
        });
    }
};
