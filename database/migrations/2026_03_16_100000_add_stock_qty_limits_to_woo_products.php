<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table): void {
            $table->decimal('min_stock_qty', 10, 2)->nullable()->after('is_discontinued');
            $table->decimal('max_stock_qty', 10, 2)->nullable()->after('min_stock_qty');
        });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table): void {
            $table->dropColumn(['min_stock_qty', 'max_stock_qty']);
        });
    }
};
