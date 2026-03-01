<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete()->after('is_active');
            $table->decimal('po_approval_threshold', 10, 2)->nullable()->after('buyer_id');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropForeign(['buyer_id']);
            $table->dropColumn(['buyer_id', 'po_approval_threshold']);
        });
    }
};
