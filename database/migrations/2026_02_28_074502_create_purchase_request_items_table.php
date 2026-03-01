<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('woo_product_id')->nullable()->constrained('woo_products')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->decimal('quantity', 10, 3);
            $table->date('needed_by')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->boolean('is_reserved')->default(false);
            $table->string('client_reference')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'ordered', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('purchase_order_item_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
    }
};
