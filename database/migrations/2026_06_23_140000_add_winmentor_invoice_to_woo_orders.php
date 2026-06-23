<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_orders', function (Blueprint $table) {
            $table->string('winmentor_invoice_nr')->nullable()->after('winmentor_sync_error');
            $table->string('winmentor_invoice_serie')->nullable()->after('winmentor_invoice_nr');
            $table->unsignedSmallInteger('winmentor_invoice_an')->nullable()->after('winmentor_invoice_serie');
            $table->unsignedTinyInteger('winmentor_invoice_luna')->nullable()->after('winmentor_invoice_an');
            $table->string('winmentor_invoice_data')->nullable()->after('winmentor_invoice_luna');
            $table->decimal('winmentor_invoice_total', 12, 2)->nullable()->after('winmentor_invoice_data');
            $table->timestamp('winmentor_invoice_matched_at')->nullable()->after('winmentor_invoice_total');
            $table->index('winmentor_invoice_nr');
        });
    }

    public function down(): void
    {
        Schema::table('woo_orders', function (Blueprint $table) {
            $table->dropIndex(['winmentor_invoice_nr']);
            $table->dropColumn([
                'winmentor_invoice_nr',
                'winmentor_invoice_serie',
                'winmentor_invoice_an',
                'winmentor_invoice_luna',
                'winmentor_invoice_data',
                'winmentor_invoice_total',
                'winmentor_invoice_matched_at',
            ]);
        });
    }
};
