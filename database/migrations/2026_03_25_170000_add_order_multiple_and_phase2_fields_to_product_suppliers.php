<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->decimal('order_multiple', 10, 3)->nullable()->after('min_order_qty');
            $table->string('purchase_uom', 50)->nullable()->after('currency');
            $table->decimal('conversion_factor', 10, 4)->nullable()->default(1)->after('purchase_uom');
            $table->string('supplier_product_name', 255)->nullable()->after('supplier_sku');
            $table->string('supplier_package_sku', 100)->nullable()->after('supplier_product_name');
            $table->string('supplier_package_ean', 30)->nullable()->after('supplier_package_sku');
            $table->string('incoterms', 10)->nullable();
            $table->boolean('price_includes_transport')->default(false);
            $table->date('date_start')->nullable();
            $table->date('date_end')->nullable();
            $table->decimal('over_delivery_tolerance', 5, 2)->nullable()->default(0);
            $table->decimal('under_delivery_tolerance', 5, 2)->nullable()->default(0);
            $table->date('last_purchase_date')->nullable();
            $table->decimal('last_purchase_price', 10, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'order_multiple',
                'purchase_uom',
                'conversion_factor',
                'supplier_product_name',
                'supplier_package_sku',
                'supplier_package_ean',
                'incoterms',
                'price_includes_transport',
                'date_start',
                'date_end',
                'over_delivery_tolerance',
                'under_delivery_tolerance',
                'last_purchase_date',
                'last_purchase_price',
            ]);
        });
    }
};
