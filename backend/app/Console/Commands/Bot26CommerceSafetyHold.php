<?php

namespace App\Console\Commands;

use App\Models\Bot;
use App\Services\CommerceSafety\HoldOverride;
use App\Services\CommerceSafety\SafetyScope;
use Illuminate\Console\Command;

/**
 * Operates App\Services\CommerceSafety\HoldOverride — the shared runtime kill
 * switch that forces `hold` for a bot across every process immediately,
 * without a restart, redeploy or config-cache purge. See HoldOverride's
 * docblock for why this is needed on top of BOT26_COMMERCE_SAFETY_MODE.
 */
class Bot26CommerceSafetyHold extends Command
{
    protected $signature = 'bot26:commerce-safety-hold
        {--bot=26 : Bot ID}
        {--engage} {--release} {--status}';

    protected $description = 'Engage, release or read back the shared commerce-safety hold override for a bot';

    public function handle(HoldOverride $override, SafetyScope $scope): int
    {
        if (! ctype_digit((string) $this->option('bot'))) {
            $this->error('bot_option_invalid');

            return self::FAILURE;
        }
        $botId = (int) $this->option('bot');

        $actions = array_filter(['engage', 'release', 'status'], fn ($action) => $this->option($action) !== false);
        if (count($actions) !== 1) {
            $this->error('exactly_one_action_required');

            return self::FAILURE;
        }

        $action = reset($actions);
        if ($action === 'engage') {
            $override->engage($botId);
        } elseif ($action === 'release') {
            $override->release($botId);
        }

        $bot = Bot::find($botId) ?? (new Bot)->forceFill(['id' => $botId]);
        // readable=false means the cache store could not be read at all: do not report
        // hold_override_active as true (nobody engaged it) — resolved_mode still shows
        // the fail-closed `hold` that SafetyScope::mode() actually enforces in that case.
        $readable = $override->readable($botId);
        $this->line(json_encode([
            'bot_id' => $botId,
            'hold_override_readable' => $readable,
            'hold_override_active' => $readable ? $override->active($botId) : null,
            'resolved_mode' => $scope->mode($bot),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
