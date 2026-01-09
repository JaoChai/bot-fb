<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add missing columns to bot_settings
        Schema::table('bot_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('bot_settings', 'auto_assignment_enabled')) {
                $table->boolean('auto_assignment_enabled')->default(false)->after('response_hours_timezone');
            }
            if (!Schema::hasColumn('bot_settings', 'auto_assignment_mode')) {
                $table->string('auto_assignment_mode', 255)->default('round_robin')->after('auto_assignment_enabled');
            }
            if (!Schema::hasColumn('bot_settings', 'auto_assignment_max_per_admin')) {
                $table->integer('auto_assignment_max_per_admin')->nullable()->after('auto_assignment_mode');
            }
            if (!Schema::hasColumn('bot_settings', 'reply_sticker_enabled')) {
                $table->boolean('reply_sticker_enabled')->default(false)->after('auto_assignment_max_per_admin');
            }
            if (!Schema::hasColumn('bot_settings', 'reply_sticker_message')) {
                $table->string('reply_sticker_message', 500)->nullable()->after('reply_sticker_enabled');
            }
            if (!Schema::hasColumn('bot_settings', 'smart_aggregation_enabled')) {
                $table->boolean('smart_aggregation_enabled')->default(false)->after('reply_sticker_message');
            }
            if (!Schema::hasColumn('bot_settings', 'smart_min_wait_ms')) {
                $table->smallInteger('smart_min_wait_ms')->default(500)->after('smart_aggregation_enabled');
            }
            if (!Schema::hasColumn('bot_settings', 'smart_max_wait_ms')) {
                $table->smallInteger('smart_max_wait_ms')->default(3000)->after('smart_min_wait_ms');
            }
            if (!Schema::hasColumn('bot_settings', 'smart_early_trigger_enabled')) {
                $table->boolean('smart_early_trigger_enabled')->default(true)->after('smart_max_wait_ms');
            }
            if (!Schema::hasColumn('bot_settings', 'smart_per_user_learning_enabled')) {
                $table->boolean('smart_per_user_learning_enabled')->default(false)->after('smart_early_trigger_enabled');
            }
        });

        // Add missing columns to conversations
        Schema::table('conversations', function (Blueprint $table) {
            if (!Schema::hasColumn('conversations', 'assignment_method')) {
                $table->string('assignment_method', 255)->nullable()->after('telegram_chat_title');
            }
            if (!Schema::hasColumn('conversations', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('assignment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $columns = [
                'auto_assignment_enabled',
                'auto_assignment_mode',
                'auto_assignment_max_per_admin',
                'reply_sticker_enabled',
                'reply_sticker_message',
                'smart_aggregation_enabled',
                'smart_min_wait_ms',
                'smart_max_wait_ms',
                'smart_early_trigger_enabled',
                'smart_per_user_learning_enabled',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('bot_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasColumn('conversations', 'assignment_method')) {
                $table->dropColumn('assignment_method');
            }
            if (Schema::hasColumn('conversations', 'assigned_at')) {
                $table->dropColumn('assigned_at');
            }
        });
    }
};
