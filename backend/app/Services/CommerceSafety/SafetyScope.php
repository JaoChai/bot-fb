<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\FlowPlugin;

class SafetyScope
{
    private const MODES = ['off', 'shadow', 'enforce', 'hold'];

    public function __construct(private readonly HoldOverride $holdOverride) {}

    public function mode(Bot $bot): string
    {
        $scope = config("commerce_safety.bots.{$bot->getKey()}");

        if (! is_array($scope)) {
            return 'off';
        }

        $mode = $scope['mode'] ?? null;

        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            return 'hold';
        }

        // Shared runtime kill switch wins over (possibly boot-frozen, config-cached)
        // config immediately, with no restart. See HoldOverride for why this exists.
        if ($this->holdEngaged((int) $bot->getKey(), $mode)) {
            return 'hold';
        }

        // Only enforcement can wrongly trust a plugin that is not this bot's own, and
        // this check costs a flow_plugins query on every mode() call. Shadow enforces
        // nothing, so paying that per-call price there would buy no protection — and
        // would turn a stale plugin id into a silent `hold` during an observation phase.
        if ($mode === 'enforce' && $this->paymentPluginIds($bot) === null) {
            return 'hold';
        }

        return $mode;
    }

    /**
     * An engaged override holds in every mode. An unreadable cache store is only read as
     * a hold where money is at stake: a bot configured `off` or `shadow` holds no
     * authoritative commerce state, and `hold` suppresses its payment plugin and blocks
     * its orders, so escalating it over an unrelated cache outage costs availability and
     * protects nothing.
     */
    private function holdEngaged(int $botId, string $configuredMode): bool
    {
        $state = $this->holdOverride->state($botId);

        return $state['readable']
            ? $state['engaged']
            : in_array($configuredMode, ['enforce', 'hold'], true);
    }

    public function paymentPluginsAreTrusted(Bot $bot): bool
    {
        return $this->paymentPluginIds($bot) !== null;
    }

    /** @return list<int>|null */
    public function paymentPluginIds(Bot $bot): ?array
    {
        $scope = config("commerce_safety.bots.{$bot->getKey()}");

        if (! is_array($scope)) {
            return null;
        }

        return $this->trustedPaymentPluginIds($bot, $scope['payment_plugin_ids'] ?? null);
    }

    /**
     * Return normalized IDs for enforced plugin suppression, while preserving
     * explicit legacy modes that never suppressed plugin execution.
     *
     * @return list<int>|null
     */
    public function paymentPluginIdsForExecution(Bot $bot): ?array
    {
        $scope = config("commerce_safety.bots.{$bot->getKey()}");

        if (! is_array($scope)) {
            return null;
        }

        if (in_array($scope['mode'] ?? null, ['off', 'shadow'], true)) {
            return [];
        }

        return $this->trustedPaymentPluginIds($bot, $scope['payment_plugin_ids'] ?? null);
    }

    /** @return list<int>|null */
    private function trustedPaymentPluginIds(Bot $bot, mixed $pluginIds): ?array
    {
        if (! $bot->exists || ! is_array($pluginIds) || $pluginIds === []) {
            return null;
        }

        $ids = array_values(array_unique(array_filter(
            $pluginIds,
            fn (mixed $id): bool => is_int($id) && $id > 0,
        )));

        if (count($ids) !== count($pluginIds)) {
            return null;
        }

        $allBelongToBot = FlowPlugin::query()
            ->whereKey($ids)
            ->whereHas('flow', fn ($query) => $query->where('bot_id', $bot->getKey()))
            ->count() === count($ids);

        return $allBelongToBot ? $ids : null;
    }
}
