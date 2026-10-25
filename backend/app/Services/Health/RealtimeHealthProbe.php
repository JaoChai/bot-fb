<?php

namespace App\Services\Health;

use Pusher\Pusher;

/**
 * Probes the configured broadcasting (Reverb) app over its REST API.
 *
 * A successful authenticated GET /channels call proves the Reverb server is
 * reachable AND serving the configured app — unlike checking the driver name,
 * which said "ok" while Reverb was dead for 18 hours on 24-25 Oct 2026.
 *
 * The raw endpoint is used instead of Pusher::getChannels(), whose
 * get_object_vars() call TypeErrors on Reverb's 0-subscriber reply
 * {"channels":[]} (a JSON empty array decodes to a PHP array, not an
 * object) — a healthy, quiet Reverb must read as live.
 *
 * check() never throws: any transport failure is reported as live=false with
 * the exception class. The client carries a hard 3 s network timeout so a
 * hung Reverb cannot stall the scheduler.
 */
class RealtimeHealthProbe
{
    public const NETWORK_TIMEOUT_SECONDS = 3;

    /**
     * @param  callable|null  $clientFactory  Test seam: returns the client to
     *                                        call get('/channels') on. Defaults
     *                                        to a Pusher client built from the
     *                                        configured connection.
     */
    public function __construct(
        private readonly ?\Closure $clientFactory = null,
    ) {}

    /**
     * @return array{live: bool, error_class: string|null, latency_ms: int}
     */
    public function check(): array
    {
        $start = microtime(true);

        try {
            // associative=true: {"channels":[]} must stay an array, never an
            // object — the value is only inspected, never passed around.
            $result = $this->client()->get('/channels', [], true);

            $channels = is_array($result) ? ($result['channels'] ?? null) : null;

            if (! is_array($channels)) {
                throw new \RuntimeException('Unexpected /channels reply: "channels" key missing or not an array');
            }

            return [
                'live' => true,
                'error_class' => null,
                'latency_ms' => $this->latencyMs($start),
            ];
        } catch (\Throwable $e) {
            return [
                'live' => false,
                'error_class' => $e::class,
                'latency_ms' => $this->latencyMs($start),
            ];
        }
    }

    /**
     * Client options for the configured broadcasting connection, with the hard
     * network timeout enforced (Pusher SDK forwards this to the HTTP client).
     *
     * @return array<string, mixed>
     */
    protected function clientOptions(): array
    {
        $options = config('broadcasting.connections.'.config('broadcasting.default').'.options', []);
        $options = is_array($options) ? $options : [];
        $options['timeout'] = self::NETWORK_TIMEOUT_SECONDS;

        return $options;
    }

    private function client(): object
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)();
        }

        $connection = config('broadcasting.connections.'.config('broadcasting.default'), []);
        $connection = is_array($connection) ? $connection : [];

        return new Pusher(
            $connection['key'] ?? '',
            $connection['secret'] ?? '',
            $connection['app_id'] ?? '',
            $this->clientOptions(),
        );
    }

    private function latencyMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
