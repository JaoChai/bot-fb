<?php

namespace App\Services;

use App\Models\Message;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Per-reply-attempt phase stopwatch (Wave 2 · plan #6, timing half).
 *
 * One collector is created per reply attempt (job handle) and threaded down
 * the call chain as an optional ?ReplyPhaseTimingCollector — null means
 * "not measured", so every call site keeps its old behavior and overhead
 * stays at a few clock reads (bench: < 5 ms per reply).
 *
 * Clock is Carbon::now()->getPreciseTimestamp(3): precise in production and
 * deterministic under Carbon::setTestNow in tests (fake clock).
 */
class ReplyPhaseTimingCollector
{
    public const PHASES = [
        'router_ms',
        'intent_ms',
        'retrieval_ms',
        'primary_ms',
        'fallback_ms',
        'vip_retry_ms',
        'send_ms',
        'plugin_ms',
    ];

    /** @var array<string, int> accumulated milliseconds per phase */
    private array $phases = [];

    private int $startedAtMs;

    private int $rowWrittenAtMs = 0;

    private int $debounceMs = 0;

    private ?string $outcome = null;

    private ?string $model = null;

    private bool $attempted = false;

    private function __construct(
        private readonly ?int $queuedAtMs,
    ) {
        $this->startedAtMs = static::clock();
    }

    public static function start(?int $queuedAtMs = null): self
    {
        return new self($queuedAtMs);
    }

    /** Millisecond clock, fake-clock friendly. */
    public static function clock(): int
    {
        return Carbon::now()->getPreciseTimestamp(3);
    }

    /** Instance form for optional collectors: $timing?->nowMs(). */
    public function nowMs(): int
    {
        return static::clock();
    }

    /**
     * Run $fn, adding its wall time (ms) to $phase. Returns $fn's result.
     * Static so a nullable collector reads naturally: measure($timing, ...).
     */
    public static function measure(?self $collector, string $phase, callable $fn): mixed
    {
        if ($collector === null) {
            return $fn();
        }

        return $collector->measureFn($fn, $phase);
    }

    public function measureFn(callable $fn, string $phase): mixed
    {
        $start = static::clock();

        try {
            return $fn();
        } finally {
            $this->addMs($phase, static::clock() - $start);
        }
    }

    public function addMs(string $phase, int $ms): void
    {
        $this->phases[$phase] = ($this->phases[$phase] ?? 0) + max(0, $ms);
    }

    public function setDebounceMs(int $ms): void
    {
        $this->debounceMs = max(0, $ms);
    }

    public function setOutcome(string $outcome): void
    {
        $this->outcome = $outcome;
    }

    public function setModel(?string $model): void
    {
        $this->model = $model;
    }

    /** Generation was actually attempted (buffered/dedup skips never set this). */
    public function markAttempted(): void
    {
        $this->attempted = true;
    }

    public function attempted(): bool
    {
        return $this->attempted;
    }

    /**
     * One place decides outcome from the saved bot message:
     * model_used null = friendly error reply (ai_failed),
     * support_router = handover reply, anything else = success.
     */
    public function recordBotMessage(Message $botMessage): void
    {
        $this->markAttempted();
        $this->setModel($botMessage->model_used);
        $this->setOutcome(match ($botMessage->model_used) {
            null => 'ai_failed',
            'support_router' => 'router_handover',
            default => 'success',
        });
    }

    /** Actual queue wait beyond the intended debounce, clamped at 0. */
    public function queueWaitMs(): int
    {
        if ($this->queuedAtMs === null) {
            return 0;
        }

        return max(0, $this->startedAtMs - $this->queuedAtMs - $this->debounceMs);
    }

    /**
     * The row to insert. Every phase the attempt never ran stays null
     * (card rule: null = phase not run). No message text, ids only.
     */
    public function toRow(
        ?int $botId = null,
        ?int $conversationId = null,
        ?string $jobId = null,
        ?string $aggregationGroupId = null,
        ?CarbonInterface $now = null,
    ): array {
        $this->rowWrittenAtMs = static::clock();

        $row = [
            'bot_id' => $botId,
            'conversation_id' => $conversationId,
            'job_id' => $jobId,
            'aggregation_group_id' => $aggregationGroupId,
            'aggregation_wait_ms' => $this->debounceMs > 0 ? $this->debounceMs : null,
            'queue_wait_ms' => $this->queueWaitMs(),
            'total_ms' => max(0, $this->rowWrittenAtMs - $this->startedAtMs),
            'outcome' => $this->outcome ?? 'failed',
            'model' => $this->model,
            'created_at' => ($now ?? Carbon::now()),
        ];

        foreach (self::PHASES as $phase) {
            $row[$phase] = isset($this->phases[$phase]) ? (int) round($this->phases[$phase]) : null;
        }

        return $row;
    }
}
