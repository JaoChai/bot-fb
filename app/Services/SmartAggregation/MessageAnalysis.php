<?php

namespace App\Services\SmartAggregation;

/**
 * Data Transfer Object for message analysis results.
 * Contains classification and completeness scoring of a message.
 */
class MessageAnalysis
{
    public function __construct(
        public bool $isComplete,
        public bool $isGreeting,
        public bool $isQuestion,
        public bool $hasContinuationHint,
        public float $completenessScore,
        public ?string $detectedLanguage = null,
        public ?string $endMarker = null,
    ) {}
}
