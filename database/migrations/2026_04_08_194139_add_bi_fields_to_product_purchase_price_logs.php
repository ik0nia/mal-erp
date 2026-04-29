<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_purchase_price_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('product_purchase_price_logs', 'firma')) {
                $table->string('firma', 50)->nullable()->after('source');
            }
            if (! Schema::hasColumn('product_purchase_price_logs', 'nr_doc')) {
                $table->string('nr_doc', 50)->nullable()->after('firma');
            }
            if (! Schema::hasColumn('product_purchase_price_logs', 'quantity')) {
                $table->decimal('quantity', 12, 3)->nullable()->after('nr_doc');
            }
            if (! Schema::hasColumn('product_purchase_price_logs', 'intrare_raw_id')) {
                $table->unsignedBigInteger('intrare_raw_id')->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('product_purchase_price_logs', 'has_anomaly')) {
                $table->boolean('has_anomaly')->default(false)->after('intrare_raw_id');
            }

            $table->index(['firma', 'acquired_at']);
            $table->index('has_anomaly');
        });
    }

    public function down(): void
    {
        Schema::table('product_purchase_price_logs', function (Blueprint $table) {
            $table->dropIndex(['firma', 'acquired_at']);
            $table->dropIndex(['has_anomaly']);
            $table->dropColumn(['firma', 'nr_doc', 'quantity', 'intrare_raw_id', 'has_anomaly']);
        });
    }
};
