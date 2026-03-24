<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Returns true if the named index already exists on $table.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        );

        return ! empty($rows);
    }

    public function up(): void
    {
        // Compound index on email_messages for queries that filter by supplier
        // and check whether AI processing has been done (agent_processed_at IS NULL).
        if (Schema::hasTable('email_messages')) {
            if (! $this->indexExists('email_messages', 'idx_em_supplier_processed')) {
                Schema::table('email_messages', function (Blueprint $table) {
                    $table->index(
                        ['supplier_id', 'agent_processed_at'],
                        'idx_em_supplier_processed'
                    );
                });
            }
        }

        // Compound index on social_posts for the scheduler query
        // (WHERE status = ? ORDER BY scheduled_at).
        // Individual indexes on status and scheduled_at already exist,
        // but a compound index lets MySQL satisfy the query with a single range scan.
        if (Schema::hasTable('social_posts')) {
            if (! $this->indexExists('social_posts', 'idx_sp_status_scheduled')) {
                Schema::table('social_posts', function (Blueprint $table) {
                    $table->index(
                        ['status', 'scheduled_at'],
                        'idx_sp_status_scheduled'
                    );
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_messages')) {
            if ($this->indexExists('email_messages', 'idx_em_supplier_processed')) {
                Schema::table('email_messages', function (Blueprint $table) {
                    $table->dropIndex('idx_em_supplier_processed');
                });
            }
        }

        if (Schema::hasTable('social_posts')) {
            if ($this->indexExists('social_posts', 'idx_sp_status_scheduled')) {
                Schema::table('social_posts', function (Blueprint $table) {
                    $table->dropIndex('idx_sp_status_scheduled');
                });
            }
        }
    }
};
