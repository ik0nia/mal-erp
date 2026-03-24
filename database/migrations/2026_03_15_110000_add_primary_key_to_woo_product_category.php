<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('woo_product_category')) {
            return;
        }

        // Check if a PRIMARY KEY already exists
        $hasPrimary = DB::select(
            "SHOW INDEX FROM `woo_product_category` WHERE Key_name = 'PRIMARY'"
        );

        if (! empty($hasPrimary)) {
            return; // Already has a primary key, nothing to do
        }

        // The table has a UNIQUE KEY on (woo_product_id, woo_category_id).
        // We promote it to a PRIMARY KEY. MySQL will not allow dropping that
        // unique index in a separate statement while FK constraints reference it,
        // but a single ALTER TABLE with both ADD PRIMARY KEY and DROP INDEX
        // succeeds because MySQL resolves them together.
        DB::statement(
            'ALTER TABLE `woo_product_category`'
            . ' ADD PRIMARY KEY (`woo_product_id`, `woo_category_id`),'
            . ' DROP INDEX `woo_product_category_woo_product_id_woo_category_id_unique`'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('woo_product_category')) {
            return;
        }

        $hasPrimary = DB::select(
            "SHOW INDEX FROM `woo_product_category` WHERE Key_name = 'PRIMARY'"
        );

        if (empty($hasPrimary)) {
            return;
        }

        // Re-add the unique index and drop the primary key in one statement.
        DB::statement(
            'ALTER TABLE `woo_product_category`'
            . ' DROP PRIMARY KEY,'
            . ' ADD UNIQUE KEY `woo_product_category_woo_product_id_woo_category_id_unique` (`woo_product_id`, `woo_category_id`)'
        );
    }
};
