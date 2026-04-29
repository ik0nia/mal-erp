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
        Schema::create('cemacon_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_message_id')->index();
            $table->string('numar_comanda')->unique();
            $table->date('data_comanda');
            $table->string('gestiune')->nullable();
            $table->json('produse');
            $table->decimal('total', 12, 2)->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('email_message_id')->references('id')->on('email_messages')->cascadeOnDelete();
        });

        Schema::create('cemacon_product_codes', function (Blueprint $table) {
            $table->id();
            $table->string('cod_cemacon')->unique();
            $table->string('denumire_cemacon');
            $table->unsignedBigInteger('woo_product_id')->nullable()->index();
            $table->unsignedBigInteger('product_supplier_id')->nullable()->index();
            $table->string('confidence')->default('none');
            $table->timestamps();

            $table->foreign('woo_product_id')->references('id')->on('woo_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cemacon_product_codes');
        Schema::dropIfExists('cemacon_orders');
    }
};
