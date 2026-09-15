<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\FlowPlugin;

class SafetyScope
{
    private const MODES = ['off', 'shadow', 'enforce', 'hold'];

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

        if (in_array($mode, ['shadow', 'enforce'], true)
            && ! $this->paymentPluginsBelongToBot($bot, $scope['payment_plugin_ids'] ?? null)) {
            return 'hold';
        }

        return $mode;
    }

    private function paymentPluginsBelongToBot(Bot $bot, mixed $pluginIds): bool
    {
        if (! $bot->exists || ! is_array($pluginIds) || $pluginIds === []) {
            return false;
        }

        $ids = array_values(array_unique(array_filter(
            $pluginIds,
            fn (mixed $id): bool => is_int($id) && $id > 0,
        )));

        if (count($ids) !== count($pluginIds)) {
            return false;
        }

        return FlowPlugin::query()
            ->whereKey($ids)
            ->whereHas('flow', fn ($query) => $query->where('bot_id', $bot->getKey()))
            ->count() === count($ids);
    }
}
