<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table): void {
            $table->foreignId('offer_id')->nullable()->after('customer_id')
                  ->constrained('offers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table): void {
            $table->dropForeignIdFor(\App\Models\Offer::class);
            $table->dropColumn('offer_id');
        });
    }
};
