<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');                          // ex: "Malinco Facebook Page"
            $table->string('platform')->default('facebook'); // facebook (instagram în viitor)
            $table->string('account_id');                    // Facebook Page ID
            $table->text('access_token');                    // Page Access Token (criptat)
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('style_analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
