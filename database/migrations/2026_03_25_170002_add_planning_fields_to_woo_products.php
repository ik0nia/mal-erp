<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->decimal('safety_stock', 10, 2)->nullable()->after('max_stock_qty');
            $table->decimal('reorder_qty', 10, 2)->nullable()->after('safety_stock');
            $table->decimal('avg_daily_consumption', 10, 4)->nullable()->after('reorder_qty');
            $table->enum('abc_classification', ['A', 'B', 'C'])->nullable()->after('avg_daily_consumption');
            $table->enum('xyz_classification', ['X', 'Y', 'Z'])->nullable()->after('abc_classification');
            $table->enum('replenishment_method', ['manual', 'reorder_point', 'min_max'])->nullable()->default('manual')->after('xyz_classification');
        });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropColumn([
                'safety_stock',
                'reorder_qty',
                'avg_daily_consumption',
                'abc_classification',
                'xyz_classification',
                'replenishment_method',
            ]);
        });
    }
};
