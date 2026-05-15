<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop 5 unused indexes (idx_scan = 0 in production pg_stat_user_indexes, verified
 * post-ANALYZE 2026-05-15). Estimated recovery: ~80 kB.
 *
 * - product_name index: never used because lookups are ILIKE (requires GIN trigram, not b-tree)
 * - others: target tables empty or unqueried by indexed column
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS semantic_routes_bot_id_intent_is_active_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS notifications_notifiable_type_notifiable_id_read_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idempotency_keys_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS order_items_product_name_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS order_items_category_index');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS semantic_routes_bot_id_intent_is_active_index ON semantic_routes (bot_id, intent, is_active)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS notifications_notifiable_type_notifiable_id_read_at_index ON notifications (notifiable_type, notifiable_id, read_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idempotency_keys_created_at_index ON idempotency_keys (created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS order_items_product_name_index ON order_items (product_name)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS order_items_category_index ON order_items (category)');
    }
};
