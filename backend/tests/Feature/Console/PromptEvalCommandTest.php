<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Bot;
use App\Services\PromptEval\CaseResult;
use App\Services\PromptEval\PromptEvalRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PromptEvalCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fakeCases(): array
    {
        return [
            ['id' => 'case_a', 'label' => 'เคส A', 'message' => 'สวัสดีครับ'],
            ['id' => 'case_b', 'label' => 'เคส B', 'message' => 'สวัสดีครับ'],
        ];
    }

    public function test_all_cases_pass_exits_zero(): void
    {
        config(['prompt-eval-cases' => $this->fakeCases()]);
        $bot = Bot::factory()->create();

        $runner = new FakePromptEvalRunner(new CaseResult('_', '_', true, 'ok', [], 1, 0.0));
        $this->app->instance(PromptEvalRunner::class, $runner);

        $this->artisan('prompt:eval', ['--bot' => $bot->id])->assertExitCode(0);

        $this->assertSame(['case_a', 'case_b'], $runner->calledCaseIds);
    }

    public function test_some_case_fails_exits_one(): void
    {
        config(['prompt-eval-cases' => $this->fakeCases()]);
        $bot = Bot::factory()->create();

        $runner = new FakePromptEvalRunner(new CaseResult('_', '_', true, 'ok', [], 1, 0.0));
        $runner->withResultFor('case_b', new CaseResult('case_b', 'เคส B', false, 'คำตอบผิด', ['ต้องมี X แต่ไม่พบ'], 1, 0.0));
        $this->app->instance(PromptEvalRunner::class, $runner);

        $this->artisan('prompt:eval', ['--bot' => $bot->id])
            ->expectsOutputToContain('case_b')
            ->assertExitCode(1);
    }

    public function test_filter_runs_only_matching_case(): void
    {
        config(['prompt-eval-cases' => $this->fakeCases()]);
        $bot = Bot::factory()->create();

        $runner = new FakePromptEvalRunner(new CaseResult('_', '_', true, 'ok', [], 1, 0.0));
        $this->app->instance(PromptEvalRunner::class, $runner);

        $this->artisan('prompt:eval', ['--bot' => $bot->id, '--filter' => 'case_b'])
            ->assertExitCode(0);

        $this->assertSame(['case_b'], $runner->calledCaseIds);
    }

    public function test_filter_with_unknown_id_warns_and_runs_only_found_cases(): void
    {
        config(['prompt-eval-cases' => $this->fakeCases()]);
        $bot = Bot::factory()->create();

        $runner = new FakePromptEvalRunner(new CaseResult('_', '_', true, 'ok', [], 1, 0.0));
        $this->app->instance(PromptEvalRunner::class, $runner);

        $this->artisan('prompt:eval', ['--bot' => $bot->id, '--filter' => 'case_a,ghost_case'])
            ->expectsOutputToContain('ghost_case')
            ->assertExitCode(0);

        $this->assertSame(['case_a'], $runner->calledCaseIds);
    }

    public function test_filter_with_no_matching_ids_exits_one_and_runs_nothing(): void
    {
        config(['prompt-eval-cases' => $this->fakeCases()]);
        $bot = Bot::factory()->create();

        $runner = new FakePromptEvalRunner(new CaseResult('_', '_', true, 'ok', [], 1, 0.0));
        $this->app->instance(PromptEvalRunner::class, $runner);

        $this->artisan('prompt:eval', ['--bot' => $bot->id, '--filter' => 'ghost_case'])
            ->assertExitCode(1);

        $this->assertSame([], $runner->calledCaseIds);
    }

    public function test_json_option_writes_file_with_expected_keys(): void
    {
        config(['prompt-eval-cases' => $this->fakeCases()]);
        $bot = Bot::factory()->create();

        $runner = new FakePromptEvalRunner(new CaseResult('_', '_', true, 'ok', [], 1, 0.0));
        $this->app->instance(PromptEvalRunner::class, $runner);

        $jsonPath = tempnam(sys_get_temp_dir(), 'prompt_eval_').'.json';

        try {
            $this->artisan('prompt:eval', ['--bot' => $bot->id, '--json' => $jsonPath])
                ->assertExitCode(0);

            $this->assertFileExists($jsonPath);
            $payload = json_decode((string) file_get_contents($jsonPath), true);

            $this->assertIsArray($payload);
            $this->assertArrayHasKey('generated_at', $payload);
            $this->assertSame($bot->id, $payload['bot_id']);
            $this->assertSame(1, $payload['runs']);
            $this->assertCount(2, $payload['cases']);

            foreach (['id', 'label', 'passed', 'runs_passed', 'failures', 'response'] as $key) {
                $this->assertArrayHasKey($key, $payload['cases'][0]);
            }
        } finally {
            @unlink($jsonPath);
        }
    }

    public function test_case_must_pass_every_run_not_just_majority(): void
    {
        config(['prompt-eval-cases' => [
            ['id' => 'case_a', 'label' => 'เคส A', 'message' => 'สวัสดีครับ'],
        ]]);
        $bot = Bot::factory()->create();

        // รอบที่ 2 จาก 3 ตก แม้ majority ผ่าน (2/3) ก็ต้องนับเป็นเคสตกทั้งหมด
        $runner = new FlakyPromptEvalRunner(failOnCall: 2);
        $this->app->instance(PromptEvalRunner::class, $runner);

        $this->artisan('prompt:eval', ['--bot' => $bot->id, '--runs' => 3])
            ->expectsOutputToContain('2/3 รอบผ่าน')
            ->assertExitCode(1);

        $this->assertSame(3, $runner->callCount);
    }

    public function test_exception_from_runner_on_one_case_does_not_crash_other_cases(): void
    {
        config(['prompt-eval-cases' => $this->fakeCases()]);
        $bot = Bot::factory()->create();

        $runner = new ThrowingPromptEvalRunner(throwForId: 'case_a');
        $this->app->instance(PromptEvalRunner::class, $runner);

        $this->artisan('prompt:eval', ['--bot' => $bot->id])
            ->assertExitCode(1);

        $this->assertSame(['case_a', 'case_b'], $runner->calledCaseIds);
    }
}

/**
 * Test double คุมผลลัพธ์ต่อ case id เอง — ไม่เรียก AIService/RAGService จริง
 */
class FakePromptEvalRunner extends PromptEvalRunner
{
    /** @var array<int, string> */
    public array $calledCaseIds = [];

    /** @var array<string, CaseResult> */
    private array $resultsById = [];

    public function __construct(private readonly CaseResult $default)
    {
        // จงใจไม่เรียก parent::__construct() — ไม่ต้องมี AIService/RAGService จริงในเทสต์นี้
    }

    public function withResultFor(string $id, CaseResult $result): static
    {
        $this->resultsById[$id] = $result;

        return $this;
    }

    public function run(Bot $bot, array $case): CaseResult
    {
        $this->calledCaseIds[] = $case['id'];

        return $this->resultsById[$case['id']] ?? $this->default;
    }
}

/**
 * Test double จำลอง case ที่ flaky — ตกเฉพาะรอบที่ระบุ
 */
class FlakyPromptEvalRunner extends PromptEvalRunner
{
    public int $callCount = 0;

    public function __construct(private readonly int $failOnCall)
    {
        // จงใจไม่เรียก parent::__construct()
    }

    public function run(Bot $bot, array $case): CaseResult
    {
        $this->callCount++;
        $passed = $this->callCount !== $this->failOnCall;

        return new CaseResult(
            $case['id'],
            $case['label'],
            $passed,
            'คำตอบ',
            $passed ? [] : ['รอบนี้ตก'],
            1,
            0.0,
        );
    }
}

/**
 * Test double จำลอง case ที่ throw exception ตอนเรียก run() — คำสั่งต้องจับไว้ไม่ให้ล้มทั้ง suite
 */
class ThrowingPromptEvalRunner extends PromptEvalRunner
{
    /** @var array<int, string> */
    public array $calledCaseIds = [];

    public function __construct(private readonly string $throwForId)
    {
        // จงใจไม่เรียก parent::__construct()
    }

    public function run(Bot $bot, array $case): CaseResult
    {
        $this->calledCaseIds[] = $case['id'];

        if ($case['id'] === $this->throwForId) {
            throw new RuntimeException('boom');
        }

        return new CaseResult($case['id'], $case['label'], true, 'ok', [], 1, 0.0);
    }
}
