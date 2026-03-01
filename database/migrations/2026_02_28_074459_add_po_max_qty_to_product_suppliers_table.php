<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_suppliers', function (Blueprint $table): void {
            $table->decimal('po_max_qty', 10, 3)->nullable()->after('min_order_qty');
        });
    }

    public function down(): void
    {
        Schema::table('product_suppliers', function (Blueprint $table): void {
            $table->dropColumn('po_max_qty');
        });
    }
};
