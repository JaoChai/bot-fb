<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Bot;
use App\Services\PromptEval\PromptEvalRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * รัน regression test ชุดเคส system prompt (config/prompt-eval-cases.php) กับบอทจริงผ่าน
 * PromptEvalRunner — ไม่เขียนอะไรลง DB: runner ปิด semantic cache (SemanticCacheService)
 * ระหว่างรันจึงไม่มีการเขียน rag_cache, conversation: null จึงไม่มีการบันทึก conversation/messages,
 * ไม่แก้ prompt/KB
 * ต้องรันซ้ำทุกครั้งที่แก้ prompt เพื่อจับ regression ก่อนขึ้น prod
 */
class PromptEval extends Command
{
    protected $signature = 'prompt:eval
        {--bot=26 : Bot ID}
        {--runs=1 : รันซ้ำกี่รอบต่อเคส}
        {--filter= : รันเฉพาะ case id ที่ตรง (คั่นด้วย comma)}
        {--json= : เขียนผลเป็นไฟล์ JSON ที่ path นี้}';

    protected $description = 'รัน regression test ชุดเคส system prompt กับบอทจริง (ไม่เขียน DB, ไม่แก้ prompt/KB)';

    public function handle(PromptEvalRunner $runner): int
    {
        $bot = Bot::findOrFail((int) $this->option('bot'));
        $runs = (int) $this->option('runs');

        $cases = $this->resolveCases((string) $this->option('filter'));
        if ($cases === null) {
            return self::FAILURE;
        }

        $startedAt = microtime(true);
        $summaries = [];
        $totalCost = 0.0;

        foreach ($cases as $case) {
            $summary = $this->runCase($runner, $bot, $case, $runs);
            $summaries[] = $summary;
            $totalCost += $summary['cost'];
        }

        $this->printSummary($summaries, microtime(true) - $startedAt, $totalCost);

        $jsonPath = $this->option('json');
        if ($jsonPath) {
            $this->writeJson((string) $jsonPath, $bot, $runs, $summaries);
        }

        $failedCount = count(array_filter($summaries, fn (array $s) => ! $s['passed']));

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function resolveCases(string $filter): ?array
    {
        /** @var array<int, array<string, mixed>> $allCases */
        $allCases = config('prompt-eval-cases', []);

        if (trim($filter) === '') {
            return $allCases;
        }

        $wantedIds = array_filter(array_map('trim', explode(',', $filter)));
        $casesById = [];
        foreach ($allCases as $case) {
            $casesById[$case['id']] = $case;
        }

        $found = [];
        $missing = [];
        foreach ($wantedIds as $id) {
            if (array_key_exists($id, $casesById)) {
                $found[] = $casesById[$id];
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $this->warn('ไม่พบ case id: '.implode(', ', $missing));
        }

        if ($found === []) {
            $this->error('ไม่พบเคสที่ระบุใน --filter เลยสักตัว');

            return null;
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{id: string, label: string, passed: bool, runs_passed: int, failures: array<int, string>, response: string, cost: float}
     */
    private function runCase(PromptEvalRunner $runner, Bot $bot, array $case, int $runs): array
    {
        $runsPassed = 0;
        $failures = [];
        $lastResponse = '';
        $cost = 0.0;

        for ($i = 0; $i < $runs; $i++) {
            try {
                $result = $runner->run($bot, $case);
            } catch (Throwable $e) {
                $failures[] = "รันเคสพัง: {$e->getMessage()}";

                continue;
            }

            $cost += $result->cost;
            $lastResponse = $result->response;

            if ($result->passed) {
                $runsPassed++;
            } else {
                $failures = [...$failures, ...$result->failures];
            }
        }

        $passed = $runsPassed === $runs;
        $failures = array_values(array_unique($failures));

        $this->printCaseLine($case, $passed, $runsPassed, $runs, $failures, $lastResponse);

        return [
            'id' => $case['id'],
            'label' => $case['label'],
            'passed' => $passed,
            'runs_passed' => $runsPassed,
            'failures' => $failures,
            'response' => $lastResponse,
            'cost' => $cost,
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<int, string>  $failures
     */
    private function printCaseLine(array $case, bool $passed, int $runsPassed, int $runs, array $failures, string $response): void
    {
        $icon = $passed ? '✅' : '❌';
        $this->line("{$icon} [{$case['id']}] {$case['label']} ({$runsPassed}/{$runs} รอบผ่าน)");

        if ($passed) {
            return;
        }

        foreach ($failures as $reason) {
            $this->line("   - {$reason}");
        }
        $this->line('   คำตอบ: '.mb_substr($response, 0, 200));
    }

    /**
     * @param  array<int, array{id: string, label: string, passed: bool, runs_passed: int, failures: array<int, string>, response: string, cost: float}>  $summaries
     */
    private function printSummary(array $summaries, float $elapsedSeconds, float $totalCost): void
    {
        $total = count($summaries);
        $passedCount = count(array_filter($summaries, fn (array $s) => $s['passed']));
        $failedIds = array_column(array_filter($summaries, fn (array $s) => ! $s['passed']), 'id');

        $this->newLine();
        $this->info("ผ่าน {$passedCount}/{$total} เคส");
        $this->line('เวลารวม: '.round($elapsedSeconds, 2).' วินาที');
        $this->line('ค่าใช้จ่ายรวมโดยประมาณ: $'.number_format($totalCost, 4));

        if ($failedIds !== []) {
            $this->error('เคสที่ตก: '.implode(', ', $failedIds));
        }
    }

    /**
     * @param  array<int, array{id: string, label: string, passed: bool, runs_passed: int, failures: array<int, string>, response: string, cost: float}>  $summaries
     */
    private function writeJson(string $path, Bot $bot, int $runs, array $summaries): void
    {
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'bot_id' => $bot->id,
            'runs' => $runs,
            'cases' => array_map(fn (array $s) => [
                'id' => $s['id'],
                'label' => $s['label'],
                'passed' => $s['passed'],
                'runs_passed' => $s['runs_passed'],
                'failures' => $s['failures'],
                'response' => $s['response'],
            ], $summaries),
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info("เขียนผลลง JSON ที่ {$path}");
    }
}
