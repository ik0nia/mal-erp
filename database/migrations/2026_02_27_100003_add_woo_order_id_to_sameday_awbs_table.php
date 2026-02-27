<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sameday_awbs', function (Blueprint $table) {
            $table->foreignId('woo_order_id')
                ->nullable()
                ->after('integration_connection_id')
                ->constrained('woo_orders')
                ->nullOnDelete();

            $table->index('woo_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('sameday_awbs', function (Blueprint $table) {
            $table->dropForeign(['woo_order_id']);
            $table->dropIndex(['woo_order_id']);
            $table->dropColumn('woo_order_id');
        });
    }
};
