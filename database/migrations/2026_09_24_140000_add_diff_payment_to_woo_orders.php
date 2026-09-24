<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link de plată pentru DIFERENȚA de încasat suplimentar (comenzi plătite card,
 * modificate în plus). Se generează o comandă-diferență în WooCommerce (fee =
 * diferența) și i se reține payment_url-ul (order-pay) ca să fie dat clientului.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_orders', function (Blueprint $table): void {
            $table->string('diff_payment_url', 1024)->nullable()->after('paid_total');
            $table->unsignedBigInteger('diff_payment_woo_id')->nullable()->after('diff_payment_url');
            $table->decimal('diff_payment_amount', 12, 2)->nullable()->after('diff_payment_woo_id');
            $table->timestamp('diff_payment_at')->nullable()->after('diff_payment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('woo_orders', function (Blueprint $table): void {
            $table->dropColumn(['diff_payment_url', 'diff_payment_woo_id', 'diff_payment_amount', 'diff_payment_at']);
        });
    }
};
