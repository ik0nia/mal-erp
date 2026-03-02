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
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 50);
            $table->string('resource', 150);
            $table->boolean('can_access')->default(true);
            $table->boolean('can_create')->default(true);
            $table->boolean('can_edit')->default(true);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_view')->default(true);
            $table->timestamps();

            $table->unique(['role', 'resource']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
