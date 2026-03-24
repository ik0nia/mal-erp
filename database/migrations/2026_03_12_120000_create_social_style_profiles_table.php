<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_style_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->integer('posts_analyzed')->default(0);
            $table->text('tone')->nullable();
            $table->text('vocabulary')->nullable();
            $table->text('hashtag_patterns')->nullable();
            $table->text('caption_structure')->nullable();
            $table->longText('raw_analysis')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();

            $table->index(['social_account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_style_profiles');
    }
};
