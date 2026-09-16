<?php

namespace App\Services\CommerceSafety;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A shared, cache-backed emergency kill switch: forces commerce_safety mode
 * to `hold` for a bot across every process — web, every queue worker,
 * scheduler — without a restart, redeploy or config-cache purge.
 *
 * Why this exists: config/commerce_safety.php resolves BOT26_COMMERCE_SAFETY_MODE
 * via env() once per process, and production runs `php artisan config:cache`
 * at container boot (see backend/Dockerfile), baking that value into
 * bootstrap/cache/config.php for the lifetime of every supervised process
 * (php-fpm, all queue workers, scheduler). Changing the env var alone does
 * not reach an already-running process. SafetyScope::mode() checks this
 * override first, so a worker that resolved its dependencies while the mode
 * was `enforce` still sees `hold` on its very next check — nothing here is
 * memoized per-process; every read hits the shared default cache store.
 *
 * Fail-closed on read: if the cache store (Redis in production) is down or
 * throws, we cannot tell whether an operator-engaged hold is in effect. That
 * ambiguity must not be read as "not engaged" — active() reports `true` and
 * logs the reason, so SafetyScope::mode() resolves to `hold` rather than
 * silently falling through to the (possibly boot-frozen) config mode.
 */
final class HoldOverride
{
    private function key(int $botId): string
    {
        return "commerce_safety:hold_override:{$botId}";
    }

    /** @return array{readable: bool, value: bool} */
    private function read(int $botId): array
    {
        try {
            return ['readable' => true, 'value' => (bool) Cache::store()->get($this->key($botId), false)];
        } catch (\Throwable $e) {
            Log::error('commerce_safety.hold_override.cache_unreadable_failing_closed', [
                'bot_id' => $botId,
                'reason' => $e->getMessage(),
            ]);

            return ['readable' => false, 'value' => true];
        }
    }

    /** Fails closed to `true` (forcing `hold` via SafetyScope::mode()) when the cache store cannot be read. */
    public function active(int $botId): bool
    {
        return $this->read($botId)['value'];
    }

    /** False when the cache store could not be read at all — distinct from a confirmed-inactive flag. */
    public function readable(int $botId): bool
    {
        return $this->read($botId)['readable'];
    }

    /** No TTL: an operational hold must not silently expire back to a stale mode. */
    public function engage(int $botId): void
    {
        Cache::store()->forever($this->key($botId), true);
    }

    public function release(int $botId): void
    {
        Cache::store()->forget($this->key($botId));
    }
}
