<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observabilitate self-contained: fiecare excepție neprinsă e agregată aici,
 * grupată după fingerprint (contor + last_seen), vizibilă în pagina „Erori aplicație".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();   // hash clasă+locație
            $table->string('exception_class');
            $table->text('message');
            $table->string('file', 512)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->longText('trace')->nullable();
            $table->string('url', 512)->nullable();
            $table->string('method', 12)->nullable();
            $table->string('context', 32)->nullable();      // web | console | queue
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->string('status', 16)->default('open');  // open | resolved | ignored
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
