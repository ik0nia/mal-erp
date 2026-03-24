<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->decimal('received_quantity', 10, 3)->nullable()->after('quantity')
                  ->comment('Cantitate efectiv recepționată — null = nerecepționat încă');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropColumn('received_quantity');
        });
    }
};
