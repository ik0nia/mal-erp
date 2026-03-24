<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            // 'stock' = comportament normal; 'on_demand' = la comandă, fără stoc fizic
            $table->enum('procurement_type', ['stock', 'on_demand'])->default('stock')->after('erp_notes')->index();
            // Mesaj custom afișat pe site (ex: "Disponibil în 3-5 zile")
            $table->string('on_demand_label', 100)->nullable()->after('procurement_type');
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            // Legătură cu comanda WooCommerce care a declanșat auto-PNR
            $table->unsignedBigInteger('woo_order_id')->nullable()->after('notes');
            $table->enum('source_type', ['manual', 'woo_order'])->default('manual')->after('woo_order_id');
            $table->foreign('woo_order_id')->references('id')->on('woo_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropForeign(['woo_order_id']);
            $table->dropColumn(['woo_order_id', 'source_type']);
        });
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropIndex(['procurement_type']);
            $table->dropColumn(['procurement_type', 'on_demand_label']);
        });
    }
};
