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
        Schema::create('sameday_awbs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('integration_connection_id')->nullable()->constrained('integration_connections')->nullOnDelete();
            $table->string('provider')->default('sameday');
            $table->string('status')->default('created');
            $table->string('awb_number')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('pickup_point_id')->nullable();
            $table->string('recipient_name');
            $table->string('recipient_phone');
            $table->string('recipient_email')->nullable();
            $table->string('recipient_county');
            $table->string('recipient_city');
            $table->string('recipient_address');
            $table->string('recipient_postal_code')->nullable();
            $table->unsignedInteger('package_count')->default(1);
            $table->decimal('package_weight_kg', 8, 3);
            $table->decimal('cod_amount', 12, 2)->nullable();
            $table->decimal('insured_value', 12, 2)->nullable();
            $table->decimal('shipping_cost', 12, 2)->nullable();
            $table->string('reference')->nullable();
            $table->text('observation')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->index(['provider', 'awb_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sameday_awbs');
    }
};
