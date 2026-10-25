<?php

namespace Tests\Unit\Services\Health;

use App\Services\Health\RealtimeHealthProbe;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

class RealtimeHealthProbeTest extends TestCase
{
    private function probeWithClient(object $client): RealtimeHealthProbe
    {
        return new RealtimeHealthProbe(clientFactory: fn (): object => $client);
    }

    public function test_success_reports_live_true_without_error(): void
    {
        $probe = $this->probeWithClient(new class
        {
            public function getChannels(): object
            {
                return (object) ['channels' => (object) []];
            }
        });

        $result = $probe->check();

        $this->assertTrue($result['live']);
        $this->assertNull($result['error_class']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
    }

    public function test_transport_failure_reports_live_false_without_throwing(): void
    {
        $probe = $this->probeWithClient(new class
        {
            public function getChannels(): object
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
            public function getChannels(): object
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
            public function getChannels(): object
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
