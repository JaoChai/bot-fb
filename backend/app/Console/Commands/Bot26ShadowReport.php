<?php

namespace App\Console\Commands;

use App\Models\CommerceSafetyShadowObservation;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

/**
 * Aggregates the redacted, additive commerce_safety_shadow_observations table
 * (see App\Services\CommerceSafety\ShadowObservationRecorder) into a measured,
 * aggregate, redacted report for a bot's `shadow`-mode window. Read-only: never
 * mutates commerce state and never outputs customer text, contact details,
 * slip images, account-delivery payloads or credentials — only counts.
 */
class Bot26ShadowReport extends Command
{
    protected $signature = 'bot26:shadow-report
        {--bot=26 : Bot ID to report on}
        {--since= : Window start, inclusive (any format Carbon::parse accepts)}
        {--until= : Window end, inclusive (any format Carbon::parse accepts)}
        {--json= : Optional path to also write the report as JSON}';

    protected $description = 'Produce a measured, aggregate, redacted shadow-mode commerce-safety report';

    /** Reason codes from CanonicalCartValidator that represent a price mismatch. */
    private const PRICE_REASONS = ['PRICE_UNKNOWN', 'PRICE_MISMATCH', 'TOTAL_MISMATCH', 'INVALID_TOTAL', 'ARITHMETIC_OVERFLOW'];

    /** Reason codes from CanonicalCartValidator that represent a stock mismatch. */
    private const STOCK_REASONS = ['OUT_OF_STOCK', 'STOCK_UNKNOWN', 'INSUFFICIENT_STOCK'];

    public function handle(): int
    {
        if (! ctype_digit((string) $this->option('bot'))) {
            $this->error('bot_option_invalid');

            return self::FAILURE;
        }
        $botId = (int) $this->option('bot');

        $sinceOption = trim((string) $this->option('since'));
        $untilOption = trim((string) $this->option('until'));
        if ($sinceOption === '' || $untilOption === '') {
            $this->error('since_and_until_are_required');

            return self::FAILURE;
        }

        try {
            $since = Carbon::parse($sinceOption);
            $until = Carbon::parse($untilOption);
        } catch (InvalidFormatException) {
            $this->error('since_until_must_be_parseable_dates');

            return self::FAILURE;
        }
        if ($since->greaterThan($until)) {
            $this->error('since_must_not_be_after_until');

            return self::FAILURE;
        }

        $rows = CommerceSafetyShadowObservation::query()
            ->where('bot_id', $botId)
            ->whereBetween('created_at', [$since, $until])
            ->get(['category', 'outcome', 'reason']);

        $decisions = $rows
            ->groupBy(fn (CommerceSafetyShadowObservation $row): string => implode('|', [
                $row->category, $row->outcome, $row->reason ?? '',
            ]))
            ->map(fn ($group) => [
                'category' => $group->first()->category,
                'outcome' => $group->first()->outcome,
                'reason' => $group->first()->reason,
                'count' => $group->count(),
            ])
            ->values()
            ->all();

        $cartRows = $rows->where('category', 'cart_proposal');
        $priceMismatchCount = $cartRows->whereIn('reason', self::PRICE_REASONS)->count();
        $stockMismatchCount = $cartRows->whereIn('reason', self::STOCK_REASONS)->count();

        $report = [
            'bot_id' => $botId,
            'window' => ['since' => $since->toIso8601String(), 'until' => $until->toIso8601String()],
            'data_source' => 'commerce_safety_shadow_observations (rows written only while commerce_safety.bots.'.$botId.'.mode was shadow at record time; see App\Services\CommerceSafety\ShadowObservationRecorder)',
            'generated_at' => now()->toIso8601String(),
            'total_observations' => $rows->count(),
            'decisions_by_outcome_reason' => $decisions,
            'cart_proposal_summary' => [
                'valid' => $cartRows->where('outcome', 'valid')->count(),
                'invalid' => $cartRows->where('outcome', 'invalid')->count(),
            ],
            'price_mismatch_count' => $priceMismatchCount,
            'stock_mismatch_count' => $stockMismatchCount,
            'consent_mismatch_count' => 0,
            'would_be_checkout_states' => [],
            'paid_hold_candidates' => 0,
            'effect_intents' => 0,
            'notes' => [
                'consent_mismatch_count, would_be_checkout_states, paid_hold_candidates and effect_intents are '
                    .'structurally always 0/empty: CheckoutAuthority, PaymentEffectDispatcher and the payment/'
                    .'settlement pipeline only run for enforce/hold mode (see '
                    .'LineWebhookResponseService::commerceGuardsOutput(), '
                    .'ProcessAggregatedMessages::checkoutProposal(), and the mode gates in '
                    .'PaymentEffectDispatcher and SlipVerificationService), so shadow mode never produces those '
                    .'signals under current instrumentation.',
            ],
        ];

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->line($json);

        $path = $this->option('json');
        if ($path !== null) {
            file_put_contents($path, $json.PHP_EOL);
        }

        return self::SUCCESS;
    }
}
