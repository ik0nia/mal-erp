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
        Schema::create('winmentor_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);                    // GET, POST, PUT
            $table->string('endpoint');                      // /api/import/comenzi-furnizori
            $table->text('request_body')->nullable();        // JSON
            $table->text('response_body')->nullable();       // JSON (primii 10k chars)
            $table->smallInteger('status_code')->nullable(); // HTTP status
            $table->unsignedSmallInteger('duration_ms')->nullable();
            $table->boolean('success')->default(false);
            $table->string('context', 100)->nullable();      // 'PushComenziFurnizori', 'AddProduct' etc.
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->timestamps();

            $table->index('created_at');
            $table->index('context');
            $table->index('success');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('winmentor_api_logs');
    }
};
