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
        Schema::table('integration_connections', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropUnique(['location_id', 'provider', 'name']);
            $table->unsignedBigInteger('location_id')->nullable()->change();
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->unique(['location_id', 'provider', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropUnique(['location_id', 'provider', 'name']);
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->unique(['location_id', 'provider', 'name']);
        });
    }
};
