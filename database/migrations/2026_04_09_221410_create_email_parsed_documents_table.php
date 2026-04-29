<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_parsed_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained('email_messages')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('attachment_name')->nullable();
            $table->tinyInteger('attachment_index')->default(0);
            $table->enum('source_type', ['pdf', 'xlsx', 'body_text'])->default('pdf');
            $table->enum('doc_type', ['aviz', 'factura', 'comanda', 'lista_preturi', 'other', 'unknown'])->default('unknown');
            $table->string('doc_number')->nullable()->index();
            $table->date('doc_date')->nullable()->index();
            $table->json('parsed_data')->nullable();    // structura brută extrasă de Claude
            $table->json('products')->nullable();       // produse normalizate [{gtin, cod_furnizor, denumire_furnizor, cantitate, unitate, pret}]
            $table->string('winmentor_doc_nr')->nullable();
            $table->enum('match_status', ['matched', 'partial', 'unmatched', 'no_entry'])->default('no_entry')->index();
            $table->json('discrepancies')->nullable();  // [{sku_furnizor, den_furnizor, sku_nostru, den_nostru, pret_furnizor, pret_nostru, cant_furnizor, cant_nostru}]
            $table->enum('processing_status', ['pending', 'processing', 'done', 'error'])->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'doc_date']);
            $table->index(['doc_type', 'match_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_parsed_documents');
    }
};
