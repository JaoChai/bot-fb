<?php

namespace Tests\Feature\Console;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\PromptDeployment;
use App\Services\CommerceSafety\PromptDeploymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeployBotPromptCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public static function invalidOptions(): array
    {
        $uuid = '12345678-1234-4123-8123-123456789012';
        $cases = [[]];
        $actions = ['--prepare' => true, '--apply' => $uuid, '--rollback' => $uuid, '--status' => $uuid];
        for ($mask = 1; $mask < 16; $mask++) {
            $options = [];
            foreach (array_keys($actions) as $i => $key) {
                if ($mask & (1 << $i)) {
                    $options[$key] = $actions[$key];
                }
            }
            if (count($options) > 1) {
                $cases[] = $options + ['--actor' => 'operator'];
            }
        }
        foreach (['--prepare' => true, '--apply' => $uuid, '--rollback' => $uuid] as $key => $value) {
            $cases[] = [$key => $value];
            $cases[] = [$key => $value, '--actor' => ' '];
        }
        $cases[] = ['--prepare' => true, '--actor' => 'operator'];
        $cases[] = ['--prepare' => true, '--actor' => 'operator', 'bot' => 'wrong', 'flow' => '24', 'path' => 'irrelevant'];
        foreach (['--apply', '--rollback', '--status'] as $action) {
            $cases[] = [$action => $uuid, '--actor' => 'operator', 'bot' => '26'];
            $cases[] = [$action => $uuid, '--actor' => 'operator', '--expected-current-md5' => str_repeat('a', 32)];
            $cases[] = [$action => $uuid, '--actor' => 'operator', '--expected-candidate-sha256' => str_repeat('a', 64)];
        }
        foreach (['--apply', '--status', '--prepare'] as $action) {
            $cases[] = [$action => $actions[$action], '--actor' => 'operator', '--expected-current-sha256' => str_repeat('a', 64)];
        }

        return array_map(fn ($case) => [$case], $cases);
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_option_matrix_never_invokes_service(array $options): void
    {
        $service = $this->mock(PromptDeploymentService::class);
        foreach (['prepare', 'apply', 'rollback', 'readBack'] as $method) {
            $service->shouldNotReceive($method);
        }
        $this->artisan('bot:deploy-prompt', $options)->assertFailed();
    }

    public static function productionActions(): array
    {
        return [['--apply'], ['--rollback']];
    }

    #[DataProvider('productionActions')]
    public function test_production_requires_force(string $action): void
    {
        $this->app->instance('env', 'production');
        $this->mock(PromptDeploymentService::class)->shouldNotReceive('apply')->shouldNotReceive('rollback');
        $this->artisan('bot:deploy-prompt', [$action => (string) Str::uuid(), '--actor' => 'operator'])->expectsOutputToContain('production_force_required')->assertFailed();
    }

    public function test_discovery_and_thin_adapter_success_matrix(): void
    {
        $this->assertArrayHasKey('bot:deploy-prompt', Artisan::all());
        $row = new PromptDeployment;
        $row->forceFill(['id' => (string) Str::uuid(), 'status' => 'prepared', 'previous_sha256' => str_repeat('a', 64), 'candidate_sha256' => str_repeat('b', 64), 'previous_prompt' => 'secret backup']);
        $service = $this->mock(PromptDeploymentService::class);
        $service->shouldReceive('prepare')->once()->with(26, 24, 'synthetic.txt', str_repeat('a', 32), str_repeat('b', 64), 'operator')->andReturn($row);
        $service->shouldReceive('apply')->twice()->withArgs(fn ($reference, $actor) => $reference->id === $row->id && $actor === 'operator');
        $service->shouldReceive('rollback')->twice()->withArgs(fn ($reference, $sha, $actor) => $reference->id === $row->id && $sha === str_repeat('b', 64) && $actor === 'operator');
        $service->shouldReceive('readBack')->times(5)->andReturn(['id' => $row->id, 'flow_sha256' => str_repeat('b', 64), 'cached_flow_sha256' => str_repeat('b', 64), 'semantic_count' => 0]);
        $this->artisan('bot:deploy-prompt', ['--prepare' => true, 'bot' => '26', 'flow' => '24', 'path' => 'synthetic.txt', '--expected-current-md5' => str_repeat('a', 32), '--expected-candidate-sha256' => str_repeat('b', 64), '--actor' => 'operator'])->doesntExpectOutputToContain('secret backup')->assertSuccessful();
        foreach (['testing', 'production'] as $environment) {
            $this->app->instance('env', $environment);
            $force = $environment === 'production' ? ['--force' => true] : [];
            $this->artisan('bot:deploy-prompt', ['--apply' => $row->id, '--actor' => 'operator'] + $force)->expectsOutputToContain('semantic_count')->assertSuccessful();
            $this->artisan('bot:deploy-prompt', ['--rollback' => $row->id, '--actor' => 'operator', '--expected-current-sha256' => str_repeat('b', 64)] + $force)->assertSuccessful();
        }
        $this->artisan('bot:deploy-prompt', ['--status' => $row->id])->assertSuccessful();
    }

    public static function malformedIdsAndHashes(): array
    {
        $cases = [];
        foreach (['--apply', '--rollback', '--status'] as $action) {
            foreach (['not-uuid', '', '123'] as $id) {
                $cases[] = [$action => $id, '--actor' => 'operator'];
            }
        }
        foreach (['', 'wrong', str_repeat('g', 64)] as $hash) {
            $cases[] = ['--rollback' => '12345678-1234-4123-8123-123456789012', '--actor' => 'operator', '--expected-current-sha256' => $hash];
            $cases[] = ['--prepare' => true, 'bot' => '26', 'flow' => '24', 'path' => 'synthetic.txt', '--actor' => 'operator', '--expected-current-md5' => $hash, '--expected-candidate-sha256' => $hash];
        }

        return array_map(fn ($case) => [$case], $cases);
    }

    #[DataProvider('malformedIdsAndHashes')]
    public function test_malformed_identifiers_and_hashes_fail_safely(array $options): void
    {
        $this->artisan('bot:deploy-prompt', $options)->expectsOutputToContain('prompt_deployment_failed')->assertFailed();
    }

    public function test_service_errors_never_expose_private_details(): void
    {
        $this->mock(PromptDeploymentService::class)->shouldReceive('apply')->once()->andThrow(new \RuntimeException('secret prompt and database password'));
        $this->artisan('bot:deploy-prompt', ['--apply' => (string) Str::uuid(), '--actor' => 'operator'])->expectsOutputToContain('prompt_deployment_failed')->doesntExpectOutputToContain('secret prompt')->assertFailed();
    }

    public function test_local_cli_prepare_apply_status_and_rollback_with_real_service(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $directory = base_path('storage/framework/testing/cli-'.Str::uuid());
        mkdir($directory, 0700, true);
        config(['prompt_deployment.artifact_root' => $directory]);
        $original = "synthetic original\n";
        $candidate = "synthetic candidate\n";
        $sha = hash('sha256', $candidate);
        $bot = Bot::factory()->create(['id' => 26, 'primary_chat_model' => 'openai/gpt-5.6-luna']);
        $flow = Flow::factory()->create(['id' => 24, 'bot_id' => 26, 'is_default' => true, 'system_prompt' => $original]);
        $bot->update(['default_flow_id' => 24]);
        file_put_contents($directory.'/test.txt', $candidate);
        file_put_contents($directory.'/test.manifest.json', json_encode(['bot_id' => 26, 'flow_id' => 24, 'version' => 'test', 'model' => 'openai/gpt-5.6-luna', 'reasoning' => 'medium', 'artifact' => ['unicode_characters' => mb_strlen($candidate), 'bytes' => strlen($candidate), 'sha256' => $sha, 'md5' => md5($candidate), 'encoding' => 'UTF-8', 'trailing_lf' => true], 'source' => ['unicode_characters' => mb_strlen($original), 'md5' => md5($original)]]));
        try {
            $this->artisan('bot:deploy-prompt', ['--prepare' => true, 'bot' => '26', 'flow' => '24', 'path' => $directory.'/test.txt', '--expected-current-md5' => md5($original), '--expected-candidate-sha256' => $sha, '--actor' => 'test'])->assertSuccessful();
            $this->assertSame($original, $flow->fresh()->system_prompt);
            $id = PromptDeployment::sole()->id;
            $this->artisan('bot:deploy-prompt', ['--apply' => $id, '--actor' => 'test'])->assertSuccessful();
            $this->assertSame($candidate, $flow->fresh()->system_prompt);
            $this->artisan('bot:deploy-prompt', ['--status' => $id])->expectsOutputToContain($sha)->assertSuccessful();
            $this->artisan('bot:deploy-prompt', ['--rollback' => $id, '--expected-current-sha256' => $sha, '--actor' => 'test'])->assertSuccessful();
            $this->assertSame($original, $flow->fresh()->system_prompt);
        } finally {
            unlink($directory.'/test.txt');
            unlink($directory.'/test.manifest.json');
            rmdir($directory);
        }
    }
}
