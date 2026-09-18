<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\CommerceSafetyShadowObservation;
use Illuminate\Support\Facades\Log;

/**
 * Records redacted, aggregate-only observations of commerce-safety decisions
 * while a bot is in `shadow` mode, for App\Console\Commands\Bot26ShadowReport
 * to aggregate later. A no-op outside `shadow` mode. Only fixed category/
 * outcome/reason codes are ever recorded — never customer text, contact
 * details, slip images, account-delivery payloads or credentials — and this
 * never creates authoritative commerce state or fires effects.
 */
final class ShadowObservationRecorder
{
    public function __construct(private readonly SafetyScope $scope) {}

    public function record(Bot $bot, string $category, string $outcome, ?string $reason = null): void
    {
        if ($this->scope->mode($bot) !== 'shadow') {
            return;
        }

        // Observation-only by construction: shadow mode exists to watch without
        // changing anything, so a failed write here must never reach the caller
        // and take the customer's reply down with it. Missed rows undercount the
        // report; a thrown exception would break the reply path in AIService.
        try {
            CommerceSafetyShadowObservation::forceCreate([
                'bot_id' => $bot->getKey(),
                'category' => $category,
                'outcome' => $outcome,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('commerce_safety.shadow_observation.write_failed', [
                'bot_id' => $bot->getKey(),
                'category' => $category,
                'outcome' => $outcome,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * One row per reason when reasons are given (e.g. every cart validation
     * error code), otherwise a single reasonless row.
     *
     * @param  list<string>  $reasons
     */
    public function recordEach(Bot $bot, string $category, string $outcome, array $reasons): void
    {
        if ($reasons === []) {
            $this->record($bot, $category, $outcome);

            return;
        }

        foreach ($reasons as $reason) {
            $this->record($bot, $category, $outcome, $reason);
        }
    }
}
