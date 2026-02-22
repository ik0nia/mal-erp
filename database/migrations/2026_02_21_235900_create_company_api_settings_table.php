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
        Schema::create('company_api_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64)->default('openapi');
            $table->string('name')->default('OpenAPI.ro');
            $table->string('base_url', 2048)->default('https://api.openapi.ro');
            $table->text('api_key')->nullable();
            $table->unsignedInteger('timeout')->default(30);
            $table->boolean('verify_ssl')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('provider');
            $table->index(['provider', 'is_active']);
        });

        DB::table('company_api_settings')->insert([
            'provider' => 'openapi',
            'name' => 'OpenAPI.ro',
            'base_url' => 'https://api.openapi.ro',
            'timeout' => 30,
            'verify_ssl' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_api_settings');
    }
};
