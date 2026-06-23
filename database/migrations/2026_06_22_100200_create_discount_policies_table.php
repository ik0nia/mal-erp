<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_policies', function (Blueprint $table): void {
            $table->id();

            // Subiectul politicii: un rol întreg sau un user anume (excepție).
            $table->string('subject_type', 10); // 'role' | 'user'
            $table->string('role', 50)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            // Scopul: toate produsele, o categorie sau un furnizor.
            $table->string('scope_type', 12)->default('all'); // 'all' | 'category' | 'supplier'
            $table->foreignId('woo_category_id')->nullable()->constrained('woo_categories')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->cascadeOnDelete();

            // Praguri: până la max_discount_percent fără aprobare; între acesta și approval_discount_percent cu aprobare.
            $table->decimal('max_discount_percent', 5, 2)->default(0);
            $table->decimal('approval_discount_percent', 5, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'role', 'is_active'], 'dp_role_idx');
            $table->index(['subject_type', 'user_id', 'is_active'], 'dp_user_idx');
            $table->index(['scope_type', 'woo_category_id'], 'dp_cat_idx');
            $table->index(['scope_type', 'supplier_id'], 'dp_sup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_policies');
    }
};
