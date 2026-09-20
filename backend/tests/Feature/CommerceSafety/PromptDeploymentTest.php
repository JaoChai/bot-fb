<?php

namespace Tests\Feature\CommerceSafety;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\PromptDeployment;
use App\Models\RagCache;
use App\Services\CommerceSafety\PromptDeploymentService;
use App\Services\FlowCacheService;
use App\Services\SemanticCacheService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PromptDeploymentTest extends TestCase
{
    protected Bot $bot;

    protected Flow $flow;

    protected string $original = "  ต้นฉบับ\r\nexact bytes \n";

    protected string $candidate = "ผู้ช่วย AI\nใหม่\n";

    protected string $directory;

    protected string $path;

    protected array $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        Http::preventStrayRequests();
        $this->directory = base_path('storage/framework/testing/prompt-'.Str::uuid());
        mkdir($this->directory, 0700, true);
        $this->path = $this->directory.'/v-test.txt';
        config(['prompt_deployment.artifact_root' => $this->directory]);
        $this->bot = Bot::factory()->create(['id' => 26, 'primary_chat_model' => 'openai/gpt-5.6-luna', 'reasoning_effort' => null, 'system_prompt' => null]);
        $this->flow = Flow::factory()->create(['id' => 24, 'bot_id' => 26, 'is_default' => true, 'system_prompt' => $this->original]);
        $this->bot->update(['default_flow_id' => 24]);
        $this->manifest = [
            'bot_id' => 26, 'flow_id' => 24, 'version' => 'v-test',
            'model' => 'openai/gpt-5.6-luna', 'reasoning' => 'medium',
            'artifact' => ['unicode_characters' => mb_strlen($this->candidate), 'bytes' => strlen($this->candidate), 'sha256' => hash('sha256', $this->candidate), 'md5' => md5($this->candidate), 'encoding' => 'UTF-8', 'trailing_lf' => true],
            'source' => ['unicode_characters' => mb_strlen($this->original), 'md5' => md5($this->original)],
        ];
        file_put_contents($this->path, $this->candidate);
        $this->writeManifest();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    protected function writeManifest(): void
    {
        file_put_contents($this->directory.'/v-test.manifest.json', json_encode($this->manifest, JSON_THROW_ON_ERROR));
    }

    protected function service(): PromptDeploymentService
    {
        return app(PromptDeploymentService::class);
    }

    protected function prepare(): PromptDeployment
    {
        return $this->service()->prepare(26, 24, $this->path, md5($this->original), hash('sha256', $this->candidate), 'preparer');
    }

    protected function reject(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected fail-closed rejection');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $e->getMessage());
        }
    }

    public function test_prepare_encrypts_exact_backup_and_leaves_prompt_and_caches_untouched(): void
    {
        $this->mock(FlowCacheService::class)->shouldNotReceive('invalidateBot');
        $this->mock(SemanticCacheService::class)->shouldNotReceive('clearForBot');
        Cache::put('bot:26:default_flow', 'sentinel');
        $row = $this->prepare();
        $raw = DB::table('prompt_deployments')->value('previous_prompt');
        $this->assertNotSame($this->original, $raw);
        $this->assertSame($this->original, Crypt::decryptString($raw));
        $this->assertSame($this->original, $row->previous_prompt);
        $this->assertArrayNotHasKey('previous_prompt', $row->toArray());
        $this->assertTrue(Str::isUuid($row->id));
        $this->assertSame(['*'], $row->getGuarded());
        $this->assertSame($this->original, $this->flow->fresh()->system_prompt);
        $this->assertSame('sentinel', Cache::get('bot:26:default_flow'));
        $this->assertSame('prepared', $row->status);
        $this->assertSame(md5($this->original), $row->previous_md5);
        $this->assertSame(hash_file('sha256', $this->directory.'/v-test.manifest.json'), $row->manifest_sha256);
        $this->assertStringStartsWith('backend/storage/', $row->artifact_path);
    }

    public static function preconditions(): array
    {
        return array_map(fn ($case) => [$case], ['missing_bot', 'deleted_bot', 'missing_flow', 'deleted_flow', 'ownership', 'flow_default', 'bot_default', 'bot_prompt', 'model', 'reasoning', 'current_prompt']);
    }

    protected function breakPrecondition(string $case): void
    {
        match ($case) {
            'missing_bot' => DB::table('bots')->where('id', 26)->update(['id' => 99]),
            'missing_flow' => DB::table('flows')->where('id', 24)->update(['id' => 99]),
            'deleted_bot' => $this->bot->delete(),
            'deleted_flow' => $this->flow->delete(),
            'ownership' => $this->flow->update(['bot_id' => Bot::factory()->create()->id]),
            'flow_default' => $this->flow->update(['is_default' => false]),
            'bot_default' => $this->bot->update(['default_flow_id' => null]),
            'bot_prompt' => $this->bot->update(['system_prompt' => 'override']),
            'model' => $this->bot->update(['primary_chat_model' => 'wrong']),
            'reasoning' => $this->bot->update(['reasoning_effort' => 'high']),
            'current_prompt' => $this->flow->update(['system_prompt' => 'third party']),
        };
    }

    #[DataProvider('preconditions')]
    public function test_prepare_rejects_preconditions(string $case): void
    {
        // Missing rows are represented by removing the reference first; no audit exists yet.
        if ($case === 'missing_bot') {
            $this->flow->forceDelete();
            $this->bot->forceDelete();
        } elseif ($case === 'missing_flow') {
            $this->bot->update(['default_flow_id' => null]);
            $this->flow->forceDelete();
        } else {
            $this->breakPrecondition($case);
        }
        $this->reject(fn () => $this->prepare());
        $this->assertDatabaseCount('prompt_deployments', 0);
    }

    #[DataProvider('preconditions')]
    public function test_apply_rechecks_preconditions(string $case): void
    {
        if (str_starts_with($case, 'missing_')) {
            // Restrict-delete audit FKs forbid physical removal; soft deletion covers absence on reload.
            $case = str_replace('missing_', 'deleted_', $case);
        }
        $row = $this->prepare();
        $this->breakPrecondition($case);
        $this->mock(FlowCacheService::class)->shouldNotReceive('invalidateBot');
        $this->mock(SemanticCacheService::class)->shouldNotReceive('clearForBot');
        $this->reject(fn () => $this->service()->apply($row, 'operator'));
        $this->assertSame('prepared', $row->fresh()->status);
    }

    public static function malformedArtifacts(): array
    {
        return array_map(fn ($case) => [$case], ['json', 'flat', 'bot_id', 'flow_id', 'version', 'model', 'reasoning', 'bytes', 'unicode_characters', 'sha256', 'md5', 'encoding', 'trailing_lf', 'source_md5', 'source_characters', 'utf8', 'missing', 'outside', 'symlink', 'manifest_symlink']);
    }

    #[DataProvider('malformedArtifacts')]
    public function test_prepare_rejects_malformed_artifacts(string $case): void
    {
        if (in_array($case, ['bot_id', 'flow_id', 'version', 'model', 'reasoning'])) {
            $this->manifest[$case] = 'wrong';
        } elseif (isset($this->manifest['artifact'][$case])) {
            $this->manifest['artifact'][$case] = 'wrong';
        } elseif ($case === 'source_md5') {
            $this->manifest['source']['md5'] = md5('stale');
        } elseif ($case === 'source_characters') {
            $this->manifest['source']['unicode_characters']++;
        } elseif ($case === 'flat') {
            $this->manifest = ['source_md5' => md5($this->original)] + $this->manifest['artifact'];
        }
        $this->writeManifest();
        match ($case) {
            'json' => file_put_contents($this->directory.'/v-test.manifest.json', '{'),
            'utf8' => file_put_contents($this->path, "\xFF"),
            'missing' => unlink($this->path),
            'outside' => $this->path = base_path('composer.json'),
            'symlink' => (function () {
                unlink($this->path);
                symlink(base_path('composer.json'), $this->path);
            })(),
            'manifest_symlink' => (function () {
                unlink($this->directory.'/v-test.manifest.json');
                symlink(base_path('composer.json'), $this->directory.'/v-test.manifest.json');
            })(),
            default => null,
        };
        $this->reject(fn () => $this->prepare());
        $this->assertDatabaseCount('prompt_deployments', 0);
    }

    public function test_scope_hash_actor_and_nested_transaction_rejections(): void
    {
        foreach ([[27, 24, md5($this->original), hash('sha256', $this->candidate), 'actor'], [26, 25, md5($this->original), hash('sha256', $this->candidate), 'actor'], [26, 24, 'bad', hash('sha256', $this->candidate), 'actor'], [26, 24, md5($this->original), hash('sha256', 'wrong'), 'actor'], [26, 24, md5($this->original), 'bad', 'actor'], [26, 24, md5($this->original), hash('sha256', $this->candidate), ' ']] as [$bot, $flow, $md5, $sha, $actor]) {
            $this->reject(fn () => $this->service()->prepare($bot, $flow, $this->path, $md5, $sha, $actor));
        }
        $row = $this->prepare();
        DB::transaction(function () use ($row) {
            $this->reject(fn () => $this->prepare());
            $this->reject(fn () => $this->service()->apply($row, 'actor'));
            $this->reject(fn () => $this->service()->rollback($row, hash('sha256', $this->candidate), 'actor'));
            $this->reject(fn () => $this->service()->readBack($row));
        });
    }

    public function test_duplicate_prepare_is_idempotent_and_keeps_first_actor_and_backup(): void
    {
        $row = $this->prepare();
        $again = $this->service()->prepare(26, 24, $this->path, md5($this->original), hash('sha256', $this->candidate), 'second');
        $this->assertSame($row->id, $again->id);
        $this->assertSame('preparer', $again->prepared_by);
        $this->assertDatabaseCount('prompt_deployments', 1);
    }

    public static function changedFiles(): array
    {
        return [['artifact'], ['manifest'], ['symlink'], ['manifest_symlink']];
    }

    #[DataProvider('changedFiles')]
    public function test_apply_rechecks_artifact_and_manifest(string $case): void
    {
        $row = $this->prepare();
        if ($case === 'artifact') {
            file_put_contents($this->path, 'changed');
        } elseif ($case === 'manifest') {
            file_put_contents($this->directory.'/v-test.manifest.json', "\n", FILE_APPEND);
        } else {
            $path = $case === 'symlink' ? $this->path : $this->directory.'/v-test.manifest.json';
            unlink($path);
            symlink(base_path('composer.json'), $path);
        }
        $this->reject(fn () => $this->service()->apply($row, 'actor'));
        $this->assertSame($this->original, $this->flow->fresh()->system_prompt);
    }

    public function test_apply_flushes_primed_caches_and_isolates_bot_27_then_rollback_restores_bytes_without_artifacts(): void
    {
        $other = Bot::factory()->create(['id' => 27])->fresh();
        $otherFlow = Flow::factory()->create(['bot_id' => 27, 'is_default' => true]);
        foreach ([26, 27] as $id) {
            RagCache::create(['bot_id' => $id, 'query_text' => 'synthetic', 'query_normalized' => 'synthetic', 'response' => 'old', 'expires_at' => now()->addHour()]);
            app(FlowCacheService::class)->getDefaultFlow($id);
        }
        $row = $this->prepare();
        $row->bot_id = 27; // Passed attributes must never be trusted.
        $row->candidate_sha256 = hash('sha256', 'forged');
        $this->service()->apply($row, 'applier');
        $safe = $this->service()->readBack($row);
        $this->assertSame(hash('sha256', $this->candidate), $safe['flow_sha256']);
        $this->assertSame($safe['flow_sha256'], $safe['cached_flow_sha256']);
        $this->assertSame(0, $safe['semantic_count']);
        $this->assertNotNull($row->fresh()->apply_cache_verified_at);
        $this->assertSame('applier', $row->fresh()->applied_by);
        $this->assertSame($otherFlow->system_prompt, app(FlowCacheService::class)->getDefaultFlow(27)->system_prompt);
        $this->assertSame(1, app(SemanticCacheService::class)->countForBot(27));
        $this->assertSame($other->getAttributes(), $other->fresh()->getAttributes());
        unlink($this->path);
        unlink($this->directory.'/v-test.manifest.json');
        $this->service()->apply($row, 'noop');
        $this->service()->rollback($row, hash('sha256', $this->candidate), 'rollback-actor');
        $this->assertSame($this->original, $this->flow->fresh()->system_prompt);
        $this->assertSame($this->original, app(FlowCacheService::class)->getDefaultFlow(26)->system_prompt);
        $this->assertNotNull($row->fresh()->rollback_cache_verified_at);
        $this->assertSame('rollback-actor', $row->fresh()->rolled_back_by);
        $before = $row->fresh()->getAttributes();
        $this->service()->rollback($row, hash('sha256', $this->candidate), 'noop');
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->reject(fn () => $this->service()->apply($row, 'actor'));
    }

    public static function failureStages(): array
    {
        return [['apply', 'flow'], ['apply', 'audit'], ['rollback', 'flow'], ['rollback', 'audit']];
    }

    #[DataProvider('failureStages')]
    public function test_database_failure_is_atomic_and_never_calls_caches(string $action, string $target): void
    {
        $row = $this->prepare();
        if ($action === 'rollback') {
            $this->service()->apply($row, 'actor');
        }
        $before = $row->fresh()->getAttributes();
        $prompt = $this->flow->fresh()->system_prompt;
        $this->mock(FlowCacheService::class)->shouldNotReceive('invalidateBot');
        $this->mock(SemanticCacheService::class)->shouldNotReceive('clearForBot');
        $table = $target === 'flow' ? 'flows' : 'prompt_deployments';
        DB::unprepared("CREATE TRIGGER c3_fail BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'injected'); END");
        try {
            try {
                $action === 'apply' ? $this->service()->apply($row, 'actor') : $this->service()->rollback($row, hash('sha256', $this->candidate), 'actor');
                $this->fail('Expected database failure');
            } catch (QueryException) {
                $this->assertSame($prompt, $this->flow->fresh()->system_prompt);
                $this->assertSame($before, $row->fresh()->getAttributes());
            }
        } finally {
            DB::unprepared('DROP TRIGGER c3_fail');
        }
    }

    public static function cacheFailures(): array
    {
        $cases = [];
        foreach (['apply', 'rollback'] as $action) {
            foreach (['flow', 'semantic', 'readback', 'stale', 'nonzero'] as $failure) {
                $cases[] = [$action, $failure];
            }
        }

        return $cases;
    }

    #[DataProvider('cacheFailures')]
    public function test_cache_failure_attempts_both_at_level_zero_and_retry_never_rewrites_prompt(string $action, string $failure): void
    {
        $row = $this->prepare();
        if ($action === 'rollback') {
            $this->service()->apply($row, 'actor');
        }
        $flowCache = Mockery::mock(FlowCacheService::class);
        $flowCache->shouldReceive('invalidateBot')->once()->with(26)->andReturnUsing(function () use ($failure) {
            $this->assertSame(0, DB::transactionLevel());
            if ($failure === 'flow') {
                throw new RuntimeException('private details');
            }
        });
        $flowCache->shouldReceive('getDefaultFlow')->with(26)->andReturnUsing(function () use ($failure) {
            if ($failure === 'readback') {
                throw new RuntimeException('private details');
            }

            return $failure === 'stale' ? new Flow(['system_prompt' => 'stale']) : $this->flow->fresh();
        });
        $semantic = Mockery::mock(SemanticCacheService::class);
        $semantic->shouldReceive('clearForBot')->once()->with(26)->andReturnUsing(function () use ($failure) {
            $this->assertSame(0, DB::transactionLevel());
            if ($failure === 'semantic') {
                throw new RuntimeException('private details');
            }

            return 0;
        });
        $semantic->shouldReceive('countForBot')->with(26)->andReturn($failure === 'nonzero' ? 1 : 0);
        $this->app->instance(FlowCacheService::class, $flowCache);
        $this->app->instance(SemanticCacheService::class, $semantic);
        $invoke = fn () => $action === 'apply' ? $this->service()->apply($row, 'actor') : $this->service()->rollback($row, hash('sha256', $this->candidate), 'actor');
        $this->reject($invoke);
        $failed = $row->fresh();
        $this->assertSame('failed', $failed->status);
        $this->assertSame($action.'_cache', $failed->failure_stage);
        $this->assertNotNull($failed->last_failure_at);
        $this->assertNotNull($failed->applied_at);
        $this->assertNull($failed->{$action.'_cache_verified_at'});
        $this->assertSame($action === 'apply' ? $this->candidate : $this->original, $this->flow->fresh()->system_prompt);
        $this->app->forgetInstance(FlowCacheService::class);
        $this->app->forgetInstance(SemanticCacheService::class);
        DB::unprepared("CREATE TRIGGER c3_no_rewrite BEFORE UPDATE ON flows BEGIN SELECT RAISE(ABORT, 'rewritten'); END");
        try {
            $invoke();
            $this->assertNotNull($row->fresh()->{$action.'_cache_verified_at'});
            $this->assertNull($row->fresh()->failure_stage);
        } finally {
            DB::unprepared('DROP TRIGGER c3_no_rewrite');
        }
    }

    public function test_rollback_fails_closed_on_wrong_hash_third_party_edit_and_unapplied_row(): void
    {
        $row = $this->prepare();
        $sha = hash('sha256', $this->candidate);
        $this->reject(fn () => $this->service()->rollback($row, $sha, 'actor'));
        $this->service()->apply($row, 'actor');
        foreach (['malformed', hash('sha256', 'wrong')] as $wrong) {
            $this->reject(fn () => $this->service()->rollback($row, $wrong, 'actor'));
        }
        $this->flow->update(['system_prompt' => 'third-party']);
        $this->reject(fn () => $this->service()->rollback($row, $sha, 'actor'));
        $this->assertSame('third-party', $this->flow->fresh()->system_prompt);
        $this->assertSame('applied', $row->fresh()->status);
    }

    public function test_migration_order_schema_constraints_and_disposable_sqlite_up_down(): void
    {
        $lastMigration = '2026_09_20_000001_drop_openrouter_columns_from_user_settings_table.php';
        $files = glob(database_path('migrations/*.php'));
        sort($files);
        $this->assertSame($lastMigration, basename(end($files)));
        // The C3 prompt_deployments migration itself — rehearsed below independently
        // of whichever migration is currently last on disk.
        $name = '2026_09_15_000002_create_prompt_deployments_table.php';
        $columns = Schema::getColumnListing('prompt_deployments');
        $this->assertCount(25, $columns);
        $row = $this->prepare();
        foreach (['bots', 'flows', 'unique'] as $constraint) {
            try {
                if ($constraint === 'unique') {
                    DB::table('prompt_deployments')->insert(array_replace($row->getAttributes(), ['id' => (string) Str::uuid()]));
                } else {
                    DB::table($constraint)->where('id', $constraint === 'bots' ? 26 : 24)->delete();
                }
                $this->fail('Constraint missing');
            } catch (QueryException) {
                $this->assertDatabaseCount('prompt_deployments', 1);
            }
        }
        $file = tempnam(sys_get_temp_dir(), 'c3-schema-');
        $default = DB::getDefaultConnection();
        config(['database.connections.c3_rehearsal' => ['driver' => 'sqlite', 'database' => $file, 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('c3_rehearsal');
        try {
            Schema::create('bots', fn ($t) => $t->id());
            Schema::create('flows', fn ($t) => $t->id());
            $migration = require database_path('migrations/'.$name);
            $migration->up();
            $this->assertTrue(Schema::hasTable('prompt_deployments'));
            $this->assertCount(2, Schema::getForeignKeys('prompt_deployments'));
            $migration->down();
            $this->assertFalse(Schema::hasTable('prompt_deployments'));
            $migration->up();
            $this->assertTrue(Schema::hasTable('prompt_deployments'));
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('c3_rehearsal');
            unlink($file);
        }
    }

    public function test_effective_fallback_model_and_empty_reasoning_are_accepted(): void
    {
        $this->bot->update(['primary_chat_model' => null, 'fallback_chat_model' => 'openai/gpt-5.6-luna', 'reasoning_effort' => '']);
        $row = $this->prepare();
        $this->service()->apply($row, 'actor');
        $this->assertSame('medium', $row->reasoning_effort);
        $this->assertSame('openai/gpt-5.6-luna', $row->serving_model);
    }

    public function test_retry_apply_fails_closed_if_candidate_was_edited_after_cache_failure(): void
    {
        $row = $this->prepare();
        $this->mock(SemanticCacheService::class)->shouldReceive('clearForBot')->andThrow(new RuntimeException('unavailable'));
        $this->reject(fn () => $this->service()->apply($row, 'actor'));
        $this->flow->update(['system_prompt' => 'third party']);
        $this->app->forgetInstance(SemanticCacheService::class);
        $this->reject(fn () => $this->service()->apply($row, 'actor'));
        $this->assertSame('third party', $this->flow->fresh()->system_prompt);
        $this->assertNull($row->fresh()->apply_cache_verified_at);
    }

    public function test_rollback_from_apply_cache_failure_and_corrupt_backup_fails_closed(): void
    {
        $row = $this->prepare();
        $this->mock(SemanticCacheService::class)->shouldReceive('clearForBot')->andThrow(new RuntimeException('unavailable'));
        $this->reject(fn () => $this->service()->apply($row, 'actor'));
        $this->app->forgetInstance(SemanticCacheService::class);
        $ciphertext = DB::table('prompt_deployments')->where('id', $row->id)->value('previous_prompt');
        DB::table('prompt_deployments')->where('id', $row->id)->update(['previous_prompt' => Crypt::encryptString('wrong backup')]);
        $this->reject(fn () => $this->service()->rollback($row, hash('sha256', $this->candidate), 'actor'));
        $this->assertSame($this->candidate, $this->flow->fresh()->system_prompt);
        DB::table('prompt_deployments')->where('id', $row->id)->update(['previous_prompt' => $ciphertext]);
        $this->service()->rollback($row, hash('sha256', $this->candidate), 'actor');
        $this->assertSame($this->original, $this->flow->fresh()->system_prompt);
    }

    public function test_rolled_back_candidate_cannot_be_prepared_again(): void
    {
        $row = $this->prepare();
        $this->service()->apply($row, 'actor');
        $this->service()->rollback($row, hash('sha256', $this->candidate), 'actor');
        $this->reject(fn () => $this->prepare());
        $this->assertDatabaseCount('prompt_deployments', 1);
    }

    public function test_readback_is_safe_and_rejects_missing_or_out_of_scope_audit(): void
    {
        $row = $this->prepare();
        $output = json_encode($this->service()->readBack($row));
        $this->assertStringNotContainsString('previous_prompt', $output);
        $this->assertStringNotContainsString('query_text', $output);
        $this->assertStringNotContainsString('artifact_path', $output);
        $reference = new PromptDeployment;
        $reference->id = (string) Str::uuid();
        $this->reject(fn () => $this->service()->readBack($reference));
        $this->reject(fn () => $this->service()->apply($reference, 'actor'));
        Bot::factory()->create(['id' => 27]);
        DB::table('prompt_deployments')->where('id', $row->id)->update(['bot_id' => 27]);
        $this->reject(fn () => $this->service()->readBack($row));
        $this->reject(fn () => $this->service()->apply($row, 'actor'));
    }

    public function test_manifest_key_order_is_not_significant_but_schema_alternatives_are_rejected(): void
    {
        $this->manifest['artifact'] = array_reverse($this->manifest['artifact'], true);
        $this->writeManifest();
        $row = $this->prepare();
        $this->assertSame('prepared', $row->status);
        $this->manifest['source_md5'] = md5($this->original);
        $this->writeManifest();
        $this->reject(fn () => $this->prepare());
    }

    public function test_slow_failed_callback_cannot_downgrade_concurrent_verified_retry(): void
    {
        $row = $this->prepare();
        $retryService = $this->service();
        $flowCache = Mockery::mock(FlowCacheService::class)->makePartial();
        $flowCache->shouldReceive('invalidateBot')->once()->with(26)->andReturnUsing(function () use ($row, $retryService) {
            $retryService->apply($row, 'concurrent-retry');
        });
        $this->app->instance(FlowCacheService::class, $flowCache);
        $this->mock(SemanticCacheService::class)->shouldReceive('clearForBot')->once()->with(26)->andThrow(new RuntimeException('late failure'));
        $this->service()->apply($row, 'first-actor');
        $this->assertSame('applied', $row->fresh()->status);
        $this->assertNotNull($row->fresh()->apply_cache_verified_at);
        $this->assertNull($row->fresh()->failure_stage);
    }

    public static function concurrentReadbackChanges(): array
    {
        return [['deleted_bot'], ['deleted_flow'], ['ownership'], ['bot_prompt'], ['model'], ['current_prompt']];
    }

    #[DataProvider('concurrentReadbackChanges')]
    public function test_changed_serving_state_during_readback_marks_failed(string $case): void
    {
        $row = $this->prepare();
        $cache = Mockery::mock(FlowCacheService::class)->makePartial();
        $cache->shouldReceive('invalidateBot')->once()->with(26)->andReturnUsing(function () use ($case) {
            $this->assertSame(0, DB::transactionLevel());
            $this->breakPrecondition($case);
        });
        $this->app->instance(FlowCacheService::class, $cache);
        $this->reject(fn () => $this->service()->apply($row, 'actor'));
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame('apply_cache', $row->fresh()->failure_stage);
        $this->assertNull($row->fresh()->apply_cache_verified_at);
    }

    /** Never uses the application's configured production DSN or public schema. */
    private function withDisposablePostgresql(callable $test): void
    {
        if (getenv('PROMPT_DEPLOYMENT_PG_RACE') !== '1') {
            $this->markTestSkipped('Set PROMPT_DEPLOYMENT_PG_RACE=1 and a disposable PROMPT_DEPLOYMENT_PG_URL to run.');
        }
        $url = getenv('PROMPT_DEPLOYMENT_PG_URL');
        $this->assertNotEmpty($url, 'An explicitly disposable PostgreSQL URL is required.');
        $schema = 'c3_'.str_replace('-', '', (string) Str::uuid());
        $default = DB::getDefaultConnection();
        config(['database.connections.c3_pg' => ['driver' => 'pgsql', 'url' => $url, 'charset' => 'utf8', 'prefix' => '', 'search_path' => $schema, 'sslmode' => 'prefer']]);
        DB::setDefaultConnection('c3_pg');
        try {
            DB::statement('CREATE SCHEMA '.$schema);
            // Minimal disposable serving tables keep this test independent of pgvector/extensions.
            Schema::create('bots', function ($t) {
                $t->id();
                $t->unsignedBigInteger('default_flow_id')->nullable();
                $t->text('system_prompt')->nullable();
                $t->string('primary_chat_model')->nullable();
                $t->string('fallback_chat_model')->nullable();
                $t->string('reasoning_effort')->nullable();
                $t->softDeletes();
                $t->timestamps();
            });
            Schema::create('flows', function ($t) {
                $t->id();
                $t->foreignId('bot_id')->constrained();
                $t->text('system_prompt');
                $t->boolean('is_default');
                $t->softDeletes();
                $t->timestamps();
            });
            Schema::create('rag_cache', function ($t) {
                $t->id();
                $t->foreignId('bot_id')->constrained();
            });
            $migration = require database_path('migrations/2026_09_15_000002_create_prompt_deployments_table.php');
            $migration->up();
            $this->assertSame('uuid', collect(Schema::getColumns('prompt_deployments'))->firstWhere('name', 'id')['type_name']);
            $this->assertCount(2, Schema::getForeignKeys('prompt_deployments'));
            $migration->down();
            $this->assertFalse(Schema::hasTable('prompt_deployments'));
            $migration->up();
            Bot::forceCreate(['id' => 26, 'default_flow_id' => 24, 'primary_chat_model' => 'openai/gpt-5.6-luna']);
            Flow::forceCreate(['id' => 24, 'bot_id' => 26, 'system_prompt' => $this->original, 'is_default' => true]);
            $test();
        } finally {
            DB::connection('c3_pg')->statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
            DB::setDefaultConnection($default);
            DB::purge('c3_pg');
        }
    }

    public function test_postgresql_concurrent_duplicate_prepare(): void
    {
        $this->withDisposablePostgresql(function () {
            $this->assertTrue(function_exists('pcntl_fork'), 'pcntl is required for the PostgreSQL race test.');
            // Fork only after disconnecting PDO: children must use independent connections.
            DB::purge('c3_pg');
            $children = [];
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        file_put_contents($this->directory.'/ready-'.$i, 'ready');
                        $deadline = microtime(true) + 10;
                        while (! file_exists($this->directory.'/start') && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        $row = $this->prepare();
                        file_put_contents($this->directory.'/result-'.$i, $row->id);
                        exit(0);
                    } catch (\Throwable) {
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            $deadline = microtime(true) + 10;
            while ((! file_exists($this->directory.'/ready-0') || ! file_exists($this->directory.'/ready-1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            file_put_contents($this->directory.'/start', 'start');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $this->assertSame(file_get_contents($this->directory.'/result-0'), file_get_contents($this->directory.'/result-1'));
            $this->assertDatabaseCount('prompt_deployments', 1);
            $row = PromptDeployment::sole();
            // PostgreSQL enforces the UUID type and the duplicate constraint, not just Eloquent.
            foreach (['bad-uuid', (string) Str::uuid()] as $id) {
                try {
                    DB::table('prompt_deployments')->insert(array_replace($row->getAttributes(), ['id' => $id]));
                    $this->fail('Expected PostgreSQL constraint rejection');
                } catch (QueryException) {
                    $this->assertDatabaseCount('prompt_deployments', 1);
                }
            }
            foreach (['bots' => 26, 'flows' => 24] as $table => $id) {
                try {
                    DB::table($table)->where('id', $id)->delete();
                    $this->fail('Expected restricted delete');
                } catch (QueryException) {
                    $this->assertDatabaseCount('prompt_deployments', 1);
                }
            }
        });
    }

    public function test_prepare_accepts_the_real_committed_v28_manifest_and_measurements_match_file_bytes(): void
    {
        config(['prompt_deployment.artifact_root' => base_path('resources/prompts/bot26')]);
        $path = base_path('resources/prompts/bot26/v28.txt');
        $manifestPath = base_path('resources/prompts/bot26/v28.manifest.json');
        $this->assertFileExists($path);
        $this->assertFileExists($manifestPath);

        $bytes = file_get_contents($path);
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        // Manifest measurements must match the actual committed artifact bytes.
        $this->assertSame(26, $manifest['bot_id']);
        $this->assertSame(24, $manifest['flow_id']);
        $this->assertSame('v28', $manifest['version']);
        $this->assertSame('openai/gpt-5.6-luna', $manifest['model']);
        $this->assertSame('medium', $manifest['reasoning']);
        $this->assertSame(mb_strlen($bytes, 'UTF-8'), $manifest['artifact']['unicode_characters']);
        $this->assertSame(strlen($bytes), $manifest['artifact']['bytes']);
        $this->assertSame(hash('sha256', $bytes), $manifest['artifact']['sha256']);
        $this->assertSame(md5($bytes), $manifest['artifact']['md5']);
        $this->assertSame('UTF-8', $manifest['artifact']['encoding']);
        $this->assertTrue($manifest['artifact']['trailing_lf']);
        $this->assertSame(24916, $manifest['artifact']['unicode_characters']);
        $this->assertSame(63473, $manifest['artifact']['bytes']);
        $this->assertSame('b88b8619faa911e2e76f92a08389c85dd695a02bc213bc272a93356bbdbd3473', $manifest['artifact']['sha256']);
        $this->assertSame(41100, $manifest['source']['unicode_characters']);
        $this->assertSame('3f08720a6fb34f916561e5531119d5f1', $manifest['source']['md5']);

        // Drive the real manifest through C3's own prepare() validation. This environment
        // cannot reproduce the actual 41,100-character production prompt bytes (only their
        // hash is known), so a synthetic flow of the matching length is used: every other
        // check (manifest schema, artifact hash/byte/char measurements against the real
        // file, version, model, reasoning, bot/flow serving preconditions) must pass, and
        // the only remaining rejection is the literal production-content mismatch.
        $this->flow->update(['system_prompt' => str_repeat('ก', 41100)]);
        try {
            $this->service()->prepare(26, 24, $path, $manifest['source']['md5'], $manifest['artifact']['sha256'], 'release-check');
            $this->fail('Expected rejection: this environment cannot reproduce the real production prompt bytes.');
        } catch (RuntimeException $e) {
            $this->assertSame('source_mismatch', $e->getMessage());
        }
        $this->assertDatabaseCount('prompt_deployments', 0);
    }

    public function test_postgresql_cache_readback_and_exact_rollback(): void
    {
        $this->withDisposablePostgresql(function () {
            Bot::forceCreate(['id' => 27]);
            Flow::forceCreate(['id' => 25, 'bot_id' => 27, 'system_prompt' => 'other', 'is_default' => true]);
            DB::table('rag_cache')->insert([['bot_id' => 26], ['bot_id' => 27]]);
            foreach ([26, 27] as $id) {
                app(FlowCacheService::class)->getDefaultFlow($id);
            }
            $row = $this->prepare();
            $this->service()->apply($row, 'pg-actor');
            $safe = $this->service()->readBack($row);
            $this->assertSame(hash('sha256', $this->candidate), $safe['flow_sha256']);
            $this->assertSame($safe['flow_sha256'], $safe['cached_flow_sha256']);
            $this->assertSame(0, $safe['semantic_count']);
            $this->assertSame(1, app(SemanticCacheService::class)->countForBot(27));
            $this->assertSame('other', app(FlowCacheService::class)->getDefaultFlow(27)->system_prompt);
            $this->service()->rollback($row, hash('sha256', $this->candidate), 'pg-actor');
            $this->assertSame($this->original, Flow::findOrFail(24)->system_prompt);
        });
    }
}
