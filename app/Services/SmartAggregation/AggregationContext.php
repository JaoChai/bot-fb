<?php

namespace App\Services\SmartAggregation;

use App\Models\Bot;

/**
 * Data Transfer Object for aggregation context.
 * Contains all information needed to make smart aggregation decisions.
 */
class AggregationContext
{
    public function __construct(
        public int $conversationId,
        /** @var array<int, array{id: int, content: string, created_at: string}> */
        public array $recentMessages,
        public int $messageCount,
        public int $elapsedMs,
        public ?int $lastGapMs,
        public float $avgGapMs,
        public int $baseWaitMs,
        public ?Bot $bot = null,
        public ?string $customerId = null,
    ) {}
}
