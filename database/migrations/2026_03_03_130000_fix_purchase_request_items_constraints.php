<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Curățăm referințele orfane înainte de a adăuga FK-ul
        DB::statement("
            UPDATE purchase_request_items
            SET purchase_order_item_id = NULL
            WHERE purchase_order_item_id IS NOT NULL
              AND purchase_order_item_id NOT IN (SELECT id FROM purchase_order_items)
        ");

        Schema::table('purchase_request_items', function (Blueprint $table): void {
            // FK cu nullOnDelete — dacă un PO item e șters, referința devine null (nu eroare)
            $table->foreign('purchase_order_item_id')
                ->references('id')
                ->on('purchase_order_items')
                ->nullOnDelete();

            // Index compound pentru query-urile din BuyerDashboard și UnassignedItemsPage
            $table->index(['supplier_id', 'status'], 'pri_supplier_status');

            // Index pentru filtrare rapidă status + cantitate rămasă
            $table->index(['status', 'ordered_quantity'], 'pri_status_ordered');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table): void {
            $table->dropForeign(['purchase_order_item_id']);
            $table->dropIndex('pri_supplier_status');
            $table->dropIndex('pri_status_ordered');
        });
    }
};
