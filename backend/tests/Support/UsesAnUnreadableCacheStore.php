<?php

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;

/**
 * Simulates the default cache store (Redis in production) being down: every
 * read throws, so a test can assert fail-closed behavior instead of a crash.
 */
trait UsesAnUnreadableCacheStore
{
    protected function useUnreadableCacheStore(): void
    {
        config(['cache.stores.unreadable_probe' => ['driver' => 'unreadable_probe']]);
        Cache::extend('unreadable_probe', fn () => Cache::repository(new class implements Store
        {
            public function get($key)
            {
                throw new \RuntimeException('cache unavailable: connection refused');
            }

            public function many(array $keys)
            {
                throw new \RuntimeException('cache unavailable: connection refused');
            }

            public function put($key, $value, $seconds)
            {
                return true;
            }

            public function putMany(array $values, $seconds)
            {
                return true;
            }

            public function increment($key, $value = 1)
            {
                return false;
            }

            public function decrement($key, $value = 1)
            {
                return false;
            }

            public function forever($key, $value)
            {
                return true;
            }

            public function forget($key)
            {
                return true;
            }

            public function flush()
            {
                return true;
            }

            public function touch($key, $seconds)
            {
                return true;
            }

            public function getPrefix()
            {
                return '';
            }
        }));
        config(['cache.default' => 'unreadable_probe']);
    }
}
