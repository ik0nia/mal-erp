<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('location_id');
            $table->boolean('is_super_admin')->default(false)->after('is_admin');
            $table->index('is_admin');
            $table->index('is_super_admin');
        });

        // Preserve existing behavior where admin users had no assigned location.
        DB::table('users')
            ->whereNull('location_id')
            ->update(['is_admin' => true]);

        // Keep requested account as super-admin and manager.
        DB::table('users')
            ->whereRaw('LOWER(email) = ?', ['codrut@ikonia.ro'])
            ->update([
                'is_admin' => true,
                'is_super_admin' => true,
                'role' => 'manager',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_admin']);
            $table->dropIndex(['is_super_admin']);
            $table->dropColumn(['is_admin', 'is_super_admin']);
        });
    }
};
