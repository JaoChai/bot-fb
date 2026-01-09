<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds foreign key constraint on bots.default_flow_id referencing flows.id.
     * Uses nullOnDelete() so when a flow is deleted, the bot's default_flow_id is set to null.
     */
    public function up(): void
    {
        // Check if foreign key already exists to avoid duplicate constraint error
        if (! $this->foreignKeyExists('bots', 'bots_default_flow_id_foreign')) {
            Schema::table('bots', function (Blueprint $table) {
                $table->foreign('default_flow_id')
                    ->references('id')
                    ->on('flows')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropForeign(['default_flow_id']);
        });
    }

    /**
     * Check if a foreign key constraint exists.
     */
    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $result = DB::select("
                SELECT 1
                FROM information_schema.table_constraints
                WHERE table_name = ?
                AND constraint_name = ?
                AND constraint_type = 'FOREIGN KEY'
            ", [$table, $constraintName]);

            return count($result) > 0;
        }

        if ($driver === 'sqlite') {
            // SQLite: Check using PRAGMA foreign_key_list
            $foreignKeys = DB::select("PRAGMA foreign_key_list({$table})");

            foreach ($foreignKeys as $fk) {
                if ($fk->from === 'default_flow_id' && $fk->table === 'flows') {
                    return true;
                }
            }

            return false;
        }

        // MySQL: Check information_schema
        if ($driver === 'mysql') {
            $result = DB::select("
                SELECT 1
                FROM information_schema.table_constraints
                WHERE table_schema = DATABASE()
                AND table_name = ?
                AND constraint_name = ?
                AND constraint_type = 'FOREIGN KEY'
            ", [$table, $constraintName]);

            return count($result) > 0;
        }

        // Default: assume it doesn't exist
        return false;
    }
};
