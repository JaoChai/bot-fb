<?php

namespace Tests\Unit\Services\Health;

use App\Services\Health\RealtimeHealthProbe;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use ReflectionMethod;
use Tests\TestCase;

class RealtimeHealthProbeTest extends TestCase
{
    private function probeWithClient(object $client): RealtimeHealthProbe
    {
        return new RealtimeHealthProbe(clientFactory: fn (): object => $client);
    }

    /**
     * Real Pusher SDK client with the HTTP layer stubbed by a Guzzle
     * MockHandler — exercises the SDK's real signing/request/decode path.
     */
    private function probeWithRealPusher(MockHandler $handler): RealtimeHealthProbe
    {
        $pusher = new Pusher(
            'health-probe-key',
            'health-probe-secret',
            'health-probe-app',
            ['cluster' => 'mt1'],
            new Client(['handler' => $handler]),
        );

        return $this->probeWithClient($pusher);
    }

    /**
     * Regression: Reverb alive with 0 subscribers answers the channels REST
     * API with {"channels":[]} — a JSON empty array, which json_decode()
     * (associative=false) yields as a PHP array, not an object. The probe
     * must report the server as LIVE, not catch a TypeError and say dead.
     */
    public function test_real_client_zero_subscribers_empty_channels_array_reports_live_true(): void
    {
        $probe = $this->probeWithRealPusher(new MockHandler([
            new Response(200, [], '{"channels":[]}'),
        ]));

        $result = $probe->check();

        $this->assertTrue($result['live'], 'Reverb alive with 0 subscribers must be live=true');
        $this->assertNull($result['error_class']);
        $this->assertIsInt($result['latency_ms']);
    }

    public function test_real_client_object_channels_with_presence_channel_reports_live_true(): void
    {
        $probe = $this->probeWithRealPusher(new MockHandler([
            new Response(200, [], '{"channels":{"presence-x":{}}}'),
        ]));

        $result = $probe->check();

        $this->assertTrue($result['live']);
        $this->assertNull($result['error_class']);
    }

    public function test_real_client_http_500_reports_live_false(): void
    {
        $probe = $this->probeWithRealPusher(new MockHandler([
            new Response(500, [], '{"error":"internal"}'),
        ]));

        $result = $probe->check();

        $this->assertFalse($result['live']);
        $this->assertSame(ApiErrorException::class, $result['error_class']);
    }

    /**
     * The old object-shaped success fake (`getChannels()` returning
     * (object)['channels' => (object)[]]) was removed: it masked the exact
     * Reverb reply shape that broke the probe. The live=true path is now
     * covered by the real-client tests above, against the real SDK.
     */
    public function test_transport_failure_reports_live_false_without_throwing(): void
    {
        $probe = $this->probeWithClient(new class
        {
            public function get(string $path, array $params = [], $associative = false): object
            {
                throw new \RuntimeException('cURL error 7: Failed to connect to reverb host');
            }
        });

        $result = $probe->check();

        $this->assertFalse($result['live']);
        $this->assertSame(\RuntimeException::class, $result['error_class']);
        $this->assertIsInt($result['latency_ms']);
    }

    public function test_server_error_502_reports_live_false_without_throwing(): void
    {
        $probe = $this->probeWithClient(new class
        {
            public function get(string $path, array $params = [], $associative = false): object
            {
                throw new \RuntimeException('Pusher error: 502 Application failed to respond');
            }
        });

        $result = $probe->check();

        $this->assertFalse($result['live']);
        $this->assertSame(\RuntimeException::class, $result['error_class']);
    }

    public function test_timeout_aborts_within_the_hard_timeout(): void
    {
        $probe = $this->probeWithClient(new class
        {
            public function get(string $path, array $params = [], $associative = false): object
            {
                usleep(300_000);
                throw new \RuntimeException('cURL error 28: Operation timed out');
            }
        });

        $start = microtime(true);
        $result = $probe->check();
        $elapsed = microtime(true) - $start;

        $this->assertFalse($result['live']);
        $this->assertLessThan(3.0, $elapsed, 'check() must not hang beyond the hard timeout');
    }

    public function test_client_is_built_with_hard_three_second_network_timeout(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.options', [
            'host' => 'reverb.example.test',
            'port' => 443,
            'scheme' => 'https',
        ]);

        $options = (new ReflectionMethod(RealtimeHealthProbe::class, 'clientOptions'))
            ->invoke(new RealtimeHealthProbe);

        $this->assertSame(3, $options['timeout']);
        $this->assertSame('reverb.example.test', $options['host']);
    }

    public function test_broken_connection_config_is_caught_as_live_false(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', null);
        Config::set('broadcasting.connections.reverb.options', ['host' => null]);

        $result = (new RealtimeHealthProbe)->check();

        $this->assertFalse($result['live']);
        $this->assertNotNull($result['error_class']);
        $this->assertIsInt($result['latency_ms']);
    }
}
