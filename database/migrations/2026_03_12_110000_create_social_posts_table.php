<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('woo_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('status')->default('draft'); // draft, generating, ready, scheduled, publishing, published, failed
            $table->string('brief_type');               // product, brand, promo, general
            $table->text('brief_direction');            // instrucțiunile utilizatorului

            $table->text('caption')->nullable();        // caption generat de Claude
            $table->text('hashtags')->nullable();       // hashtag-uri generate
            $table->text('image_path')->nullable();     // cale locală storage/public/social/
            $table->text('image_prompt')->nullable();   // promptul trimis la Gemini

            $table->string('platform_post_id')->nullable(); // ID-ul postării după publicare
            $table->string('platform_url')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_at');
            $table->index('social_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
