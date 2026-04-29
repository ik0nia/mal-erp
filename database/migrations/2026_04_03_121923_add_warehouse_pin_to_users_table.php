<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('warehouse_pin', 255)->nullable()->after('password');
        });

        // Set default PIN '0000' (hashed) for all existing users
        DB::table('users')->update(['warehouse_pin' => Hash::make('0000')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('warehouse_pin');
        });
    }
};
