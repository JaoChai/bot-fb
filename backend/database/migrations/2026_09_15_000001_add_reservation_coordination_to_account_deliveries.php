<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_deliveries', function (Blueprint $table) {
            $table->json('reservation_plan')->nullable()->after('amount');
            $table->timestamp('anchors_initialized_at')->nullable()->after('reservation_plan');
            $table->uuid('reservation_token')->nullable()->after('anchors_initialized_at');
            $table->timestamp('reservation_claimed_at')->nullable()->after('reservation_token');
            $table->timestamp('card_dispatched_at')->nullable()->after('reservation_claimed_at');
        });

        Schema::table('account_delivery_items', function (Blueprint $table) {
            $table->string('anchor_key', 64)->nullable()->after('account_delivery_id');
            $table->unique(
                ['account_delivery_id', 'anchor_key'],
                'account_delivery_items_anchor_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('account_delivery_items', function (Blueprint $table) {
            $table->dropUnique('account_delivery_items_anchor_unique');
            $table->dropColumn('anchor_key');
        });

        Schema::table('account_deliveries', function (Blueprint $table) {
            $table->dropColumn([
                'reservation_plan',
                'anchors_initialized_at',
                'reservation_token',
                'reservation_claimed_at',
                'card_dispatched_at',
            ]);
        });
    }
};
