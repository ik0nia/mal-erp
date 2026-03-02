<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_price_quotes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->cascadeOnDelete();

            // Emailul sursă
            $table->foreignId('email_message_id')
                ->constrained('email_messages')
                ->cascadeOnDelete();

            // Produsul (dacă s-a putut potrivi cu catalogul)
            $table->foreignId('woo_product_id')
                ->nullable()
                ->constrained('woo_products')
                ->nullOnDelete();

            // Textul brut al produsului menționat în email
            $table->string('product_name_raw', 500);

            // Prețul oferit
            $table->decimal('unit_price', 12, 2);
            $table->string('currency', 10)->default('RON');

            // Cantitate minimă pentru prețul ăsta (dacă e menționată)
            $table->decimal('min_qty', 10, 2)->nullable();

            // Perioadă de valabilitate (dacă e menționată în email)
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            // Data la care a fost trimis emailul cu oferta
            $table->timestamp('quoted_at')->nullable()->index();

            // Note extra
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['supplier_id', 'woo_product_id', 'quoted_at']);
            $table->index(['woo_product_id', 'quoted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_price_quotes');
    }
};
