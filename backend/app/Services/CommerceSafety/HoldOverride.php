<?php

namespace App\Services\CommerceSafety;

use Illuminate\Support\Facades\Cache;

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
 */
final class HoldOverride
{
    private function key(int $botId): string
    {
        return "commerce_safety:hold_override:{$botId}";
    }

    public function active(int $botId): bool
    {
        return (bool) Cache::store()->get($this->key($botId), false);
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
