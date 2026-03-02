<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_entities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('email_message_id')
                ->constrained('email_messages')
                ->cascadeOnDelete();

            // Tipul entității extrase
            // product_price, delivery_date, invoice_number, payment_terms,
            // discount, quantity, out_of_stock, price_increase, general_mention
            $table->string('entity_type', 50)->index();

            // Textul brut din email (ce a spus exact)
            $table->text('raw_text')->nullable();

            // Produsul asociat (dacă s-a putut potrivi cu catalogul)
            $table->foreignId('woo_product_id')
                ->nullable()
                ->constrained('woo_products')
                ->nullOnDelete();

            // Textul produsului menționat (înainte de potrivire cu catalogul)
            $table->string('product_name_raw', 500)->nullable();

            // Valoare numerică (preț, cantitate, zile, etc.)
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('unit', 50)->nullable(); // buc, kg, m, etc.

            // Dată menționată (ex: "livrare pe 15 ian")
            $table->date('date_value')->nullable();

            // Scor de încredere 0-100
            $table->unsignedTinyInteger('confidence')->default(80);

            // Date suplimentare specifice tipului
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['email_message_id', 'entity_type']);
            $table->index(['woo_product_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_entities');
    }
};
