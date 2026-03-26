<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Align supplier_id column type to match suppliers.id (unsignedBigInteger)
        // and add foreign key constraints with ON DELETE SET NULL.

        Schema::table('bi_product_margin_current', function (Blueprint $table) {
            // Change from unsignedInteger to unsignedBigInteger to match suppliers.id
            $table->unsignedBigInteger('supplier_id')->nullable()->change();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('set null');
        });

        Schema::table('bi_replenishment_suggestions', function (Blueprint $table) {
            // Change from unsignedInteger to unsignedBigInteger to match suppliers.id
            $table->unsignedBigInteger('supplier_id')->nullable()->change();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('bi_product_margin_current', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->unsignedInteger('supplier_id')->nullable()->change();
        });

        Schema::table('bi_replenishment_suggestions', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->unsignedInteger('supplier_id')->nullable()->change();
        });
    }
};
