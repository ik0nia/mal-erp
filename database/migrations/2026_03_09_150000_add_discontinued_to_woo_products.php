<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->boolean('is_discontinued')->default(false)->after('procurement_type')->index();
            $table->text('discontinued_reason')->nullable()->after('is_discontinued');
        });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropIndex(['is_discontinued']);
            $table->dropColumn(['is_discontinued', 'discontinued_reason']);
        });
    }
};
