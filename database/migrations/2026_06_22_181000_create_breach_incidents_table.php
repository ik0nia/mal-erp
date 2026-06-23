<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breach_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity', 16)->default('medium');   // low|medium|high|critical
            $table->string('status', 24)->default('open');        // open|investigating|contained|notified|closed
            $table->timestamp('detected_at');
            $table->string('affected_scope')->nullable();         // ce date/sisteme
            $table->unsignedInteger('affected_count')->nullable(); // nr. persoane vizate estimat
            $table->timestamp('authority_notify_due_at')->nullable(); // detected_at + 72h
            $table->timestamp('authority_notified_at')->nullable();   // notificare ANSPDCP
            $table->timestamp('subjects_notified_at')->nullable();    // notificare persoane vizate
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breach_incidents');
    }
};
