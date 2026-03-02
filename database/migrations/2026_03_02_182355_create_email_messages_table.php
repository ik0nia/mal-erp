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
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();

            // Identificare pe server IMAP (pentru deduplicare la sync)
            $table->string('imap_uid')->index();
            $table->string('imap_folder', 100)->default('INBOX');

            // Câmpuri standard email
            $table->string('from_email', 255)->index();
            $table->string('from_name', 255)->nullable();
            $table->string('subject', 500)->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->json('to_recipients')->nullable();
            $table->json('cc_recipients')->nullable();
            $table->json('attachments')->nullable(); // [{name, size, mime_type}]
            $table->timestamp('sent_at')->nullable()->index();

            // Status local (nu afectează serverul IMAP)
            $table->boolean('is_read')->default(false)->index();
            $table->boolean('is_flagged')->default(false);

            // Asocieri ERP
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();

            // Procesare agent AI
            $table->timestamp('agent_processed_at')->nullable();
            $table->json('agent_actions')->nullable();

            // Note interne
            $table->text('internal_notes')->nullable();

            $table->timestamps();

            $table->unique(['imap_uid', 'imap_folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
