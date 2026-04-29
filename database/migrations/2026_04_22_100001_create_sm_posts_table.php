<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_posts', function (Blueprint $table) {
            $table->id();

            // Tipul postării și sursa (morphic)
            $table->enum('type', ['product', 'category', 'brand']);
            $table->nullableMorphs('sourceable'); // sourceable_type + sourceable_id

            // Status
            $table->enum('status', [
                'draft',
                'generating',
                'ready',
                'approved',
                'scheduled',
                'publishing',
                'published',
                'failed',
            ])->default('draft')->index();

            // Conținut generat
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('image_path')->nullable();
            $table->text('image_prompt')->nullable();

            // Texte grafică (titlu, subtitlu, CTA, avantaje)
            $table->json('graphic_texts')->nullable();

            // Platforme țintă
            $table->json('platforms')->nullable(); // ['facebook','instagram']

            // Publicare
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->string('fb_post_id')->nullable();
            $table->string('ig_post_id')->nullable();
            $table->text('error_message')->nullable();

            // Aprobare
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Creat de
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_posts');
    }
};
