<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Coloane noi pe woo_products
        Schema::table('woo_products', function (Blueprint $table): void {
            $table->string('unit')->nullable()->after('manage_stock');
            $table->string('weight')->nullable()->after('unit');
            $table->string('dim_length')->nullable()->after('weight');
            $table->string('dim_width')->nullable()->after('dim_length');
            $table->string('dim_height')->nullable()->after('dim_width');
            $table->text('erp_notes')->nullable()->after('dim_height');
        });

        // Tabela furnizori
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('vat_number')->nullable()->comment('CUI/CIF');
            $table->string('reg_number')->nullable()->comment('Nr. Reg. Com.');
            $table->string('bank_account')->nullable()->comment('IBAN');
            $table->string('bank_name')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Pivot produse <-> furnizori
        Schema::create('product_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('woo_product_id')->constrained('woo_products')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('supplier_sku')->nullable()->comment('Codul produsului la furnizor');
            $table->decimal('purchase_price', 10, 4)->nullable()->comment('Preț achiziție de la acest furnizor');
            $table->string('currency', 10)->default('RON');
            $table->unsignedSmallInteger('lead_days')->nullable()->comment('Zile de livrare');
            $table->decimal('min_order_qty', 10, 3)->nullable()->comment('Cantitate minimă comandă');
            $table->boolean('is_preferred')->default(false)->comment('Furnizor preferat pentru acest produs');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['woo_product_id', 'supplier_id']);
            $table->index(['supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_suppliers');
        Schema::dropIfExists('suppliers');

        Schema::table('woo_products', function (Blueprint $table): void {
            $table->dropColumn(['unit', 'weight', 'dim_length', 'dim_width', 'dim_height', 'erp_notes']);
        });
    }
};
