<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft','pending_approval','approved','rejected','sent','received','cancelled') DEFAULT 'draft'");

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->timestamp('received_at')->nullable()->after('sent_at');
            $table->unsignedBigInteger('received_by')->nullable()->after('received_at');
            $table->text('received_notes')->nullable()->after('received_by');
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropForeign(['received_by']);
            $table->dropColumn(['received_at', 'received_by', 'received_notes']);
        });

        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft','pending_approval','approved','rejected','sent','cancelled') DEFAULT 'draft'");
    }
};
