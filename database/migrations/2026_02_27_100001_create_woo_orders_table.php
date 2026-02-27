<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woo_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedBigInteger('woo_id');
            $table->string('number')->default('');
            $table->string('status')->default('pending');
            $table->string('currency')->default('RON');
            $table->text('customer_note')->nullable();
            $table->json('billing')->nullable();
            $table->json('shipping')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_method_title')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('fee_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->datetime('date_paid')->nullable();
            $table->datetime('date_completed')->nullable();
            $table->datetime('order_date');
            $table->json('data')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'woo_id']);
            $table->index(['location_id', 'status']);
            $table->index(['connection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_orders');
    }
};
