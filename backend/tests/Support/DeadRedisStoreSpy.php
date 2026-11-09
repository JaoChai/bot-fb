<?php

namespace Tests\Support;

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Redis\Factory;
use Mockery;
use Predis\Connection\Resource\Exception\StreamInitException;

/**
 * Stands in for the app's redis cache store during an outage: a RedisStore
 * subclass that counts every access attempt and, like real dead Redis,
 * throws a Predis StreamInitException on any of them. Used as the FIRST
 * store of the failover cache config so tests can prove the failover store
 * actually attempted Redis and then failed over to the database store.
 */
class DeadRedisStoreSpy extends RedisStore
{
    /** Cache reads attempted on the store (get/many). */
    public int $reads = 0;

    /** Every access attempt, whatever the operation. */
    public int $touches = 0;

    public function __construct()
    {
        // The overridden accessors below throw before any parent logic runs,
        // so the factory is never consulted; the mock only satisfies the
        // parent constructor.
        parent::__construct(Mockery::mock(Factory::class));
    }

    public function get($key)
    {
        $this->touches++;
        $this->reads++;

        throw $this->deadRedis();
    }

    public function many(array $keys)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function add($key, $value, $seconds)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function put($key, $value, $seconds)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function putMany(array $values, $seconds)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function increment($key, $value = 1)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function decrement($key, $value = 1)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function forever($key, $value)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function forget($key)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function flush()
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function getPrefix()
    {
        return '';
    }

    private function deadRedis(): StreamInitException
    {
        return new StreamInitException(
            'Operation timed out [tcp://redis.railway.internal:6379]'
        );
    }
}
