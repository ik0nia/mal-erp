<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('operator')->after('password');
            $table->foreignId('location_id')
                ->nullable()
                ->after('role')
                ->constrained('locations')
                ->nullOnDelete();

            $table->index('role');
            $table->index('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['location_id']);
            $table->dropForeign(['location_id']);
            $table->dropColumn(['role', 'location_id']);
        });
    }
};
