<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table): void {
            $table->decimal('ordered_quantity', 10, 3)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table): void {
            $table->dropColumn('ordered_quantity');
        });
    }
};
