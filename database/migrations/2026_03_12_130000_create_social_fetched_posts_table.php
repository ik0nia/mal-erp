<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_fetched_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform_post_id');
            $table->text('message')->nullable();
            $table->timestamp('created_time')->nullable();
            $table->integer('likes_count')->default(0);
            $table->integer('comments_count')->default(0);
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'platform_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_fetched_posts');
    }
};
