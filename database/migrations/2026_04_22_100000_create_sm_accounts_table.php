<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('platform', ['facebook', 'instagram']);
            $table->string('page_id')->nullable();
            $table->string('instagram_business_id')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_accounts');
    }
};
