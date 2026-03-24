<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('provider');          // toya_api | csv_url | xlsx_upload | manual
            $table->string('label')->nullable(); // nume afișat (ex: "Feed prețuri RON")
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // api_key, url, discount, markup, vat etc.
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status')->nullable(); // ok | error | running
            $table->text('last_sync_summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_feeds');
    }
};
