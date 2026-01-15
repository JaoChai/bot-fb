# Prompt Testing Guide

## A/B Testing Framework

### Test Structure

```php
// Config for A/B test
$testConfig = [
    'test_name' => 'welcome_message_v2',
    'variants' => [
        'control' => [
            'prompt' => 'Original prompt...',
            'weight' => 50,
        ],
        'variant_a' => [
            'prompt' => 'New prompt with more examples...',
            'weight' => 50,
        ],
    ],
    'metrics' => ['response_quality', 'user_satisfaction', 'escalation_rate'],
    'duration_days' => 14,
];
```

### Variant Assignment

```php
class ABTestService
{
    public function getVariant(int $userId, string $testName): string
    {
        // Consistent assignment based on user ID
        $hash = crc32($userId . $testName);
        $bucket = $hash % 100;

        $test = $this->getTestConfig($testName);
        $cumulative = 0;

        foreach ($test['variants'] as $name => $config) {
            $cumulative += $config['weight'];
            if ($bucket < $cumulative) {
                return $name;
            }
        }

        return 'control';
    }
}
```

### Logging Results

```php
class PromptTestLogger
{
    public function logInteraction(
        int $userId,
        string $testName,
        string $variant,
        string $query,
        string $response,
        array $metrics
    ): void {
        PromptTestLog::create([
            'user_id' => $userId,
            'test_name' => $testName,
            'variant' => $variant,
            'query' => $query,
            'response' => $response,
            'response_quality' => $metrics['quality'] ?? null,
            'response_time_ms' => $metrics['time_ms'] ?? null,
            'escalated' => $metrics['escalated'] ?? false,
            'user_satisfied' => $metrics['satisfied'] ?? null,
        ]);
    }
}
```

## Evaluation Metrics

### Automated Metrics

```php
class PromptEvaluator
{
    public function evaluate(string $query, string $response, string $context): array
    {
        return [
            'relevance' => $this->evaluateRelevance($query, $response),
            'completeness' => $this->evaluateCompleteness($query, $response),
            'tone' => $this->evaluateTone($response),
            'length' => $this->evaluateLength($response),
            'safety' => $this->evaluateSafety($response),
        ];
    }

    private function evaluateRelevance(string $query, string $response): float
    {
        // Use LLM to judge relevance (0-1)
        $prompt = "Rate how relevant this response is to the query (0-1):
                   Query: {$query}
                   Response: {$response}";

        return (float) $this->llm->complete($prompt);
    }

    private function evaluateTone(string $response): array
    {
        // Check for required tone markers
        return [
            'polite' => str_contains($response, 'ค่ะ') || str_contains($response, 'ครับ'),
            'professional' => !preg_match('/555|หุหุ|โอ้ว/', $response),
            'appropriate_length' => strlen($response) < 500,
        ];
    }
}
```

### Human Evaluation

```php
// Create evaluation task for QA team
class EvaluationTask
{
    public function createTask(array $interactions): EvaluationBatch
    {
        $batch = EvaluationBatch::create([
            'name' => 'Prompt test - ' . now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        foreach ($interactions as $interaction) {
            EvaluationItem::create([
                'batch_id' => $batch->id,
                'interaction_id' => $interaction['id'],
                'query' => $interaction['query'],
                'response' => $interaction['response'],
                'criteria' => [
                    'accuracy' => null,    // 1-5
                    'helpfulness' => null, // 1-5
                    'tone' => null,        // 1-5
                    'safety' => null,      // pass/fail
                ],
            ]);
        }

        return $batch;
    }
}
```

## Statistical Analysis

### Sample Size Calculator

```php
function calculateSampleSize(
    float $baseline,
    float $minDetectableEffect,
    float $alpha = 0.05,
    float $power = 0.8
): int {
    // Two-proportion z-test sample size
    $p1 = $baseline;
    $p2 = $baseline + $minDetectableEffect;
    $pBar = ($p1 + $p2) / 2;

    $zAlpha = 1.96;  // for alpha = 0.05
    $zBeta = 0.84;   // for power = 0.8

    $n = pow(
        $zAlpha * sqrt(2 * $pBar * (1 - $pBar)) +
        $zBeta * sqrt($p1 * (1 - $p1) + $p2 * (1 - $p2)),
        2
    ) / pow($p1 - $p2, 2);

    return (int) ceil($n);
}
```

### Significance Testing

```php
function isSignificant(
    int $controlSuccess,
    int $controlTotal,
    int $variantSuccess,
    int $variantTotal
): array {
    $p1 = $controlSuccess / $controlTotal;
    $p2 = $variantSuccess / $variantTotal;

    $pPooled = ($controlSuccess + $variantSuccess) / ($controlTotal + $variantTotal);
    $se = sqrt($pPooled * (1 - $pPooled) * (1 / $controlTotal + 1 / $variantTotal));

    $zScore = ($p2 - $p1) / $se;
    $pValue = 2 * (1 - normalCDF(abs($zScore)));

    return [
        'control_rate' => $p1,
        'variant_rate' => $p2,
        'lift' => ($p2 - $p1) / $p1,
        'z_score' => $zScore,
        'p_value' => $pValue,
        'significant' => $pValue < 0.05,
    ];
}
```

## Test Cases

### Functional Tests

```php
class PromptFunctionalTest extends TestCase
{
    /**
     * @dataProvider queryProvider
     */
    public function test_prompt_handles_query_correctly(
        string $query,
        array $expectedPatterns,
        array $forbiddenPatterns
    ): void {
        $response = $this->aiService->generateResponse($query);

        foreach ($expectedPatterns as $pattern) {
            $this->assertMatchesRegularExpression($pattern, $response);
        }

        foreach ($forbiddenPatterns as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $response);
        }
    }

    public static function queryProvider(): array
    {
        return [
            'price_query' => [
                'query' => 'ราคาเท่าไหร่',
                'expected' => ['/\d+.*บาท/', '/ค่ะ|ครับ/'],
                'forbidden' => ['/ไม่รู้/', '/คู่แข่ง/'],
            ],
            'competitor_query' => [
                'query' => 'ร้าน X ดีกว่าไหม',
                'expected' => ['/สินค้าของเรา/', '/ค่ะ|ครับ/'],
                'forbidden' => ['/ร้าน X/', '/คู่แข่ง.*ดี/'],
            ],
            'angry_customer' => [
                'query' => 'ส่งช้ามาก โกรธมาก!!',
                'expected' => ['/ขออภัย|เสียใจ/', '/แก้ไข|ช่วยเหลือ/'],
                'forbidden' => ['/ไม่ได้|ทำไม่ได้/'],
            ],
        ];
    }
}
```

### Regression Tests

```php
class PromptRegressionTest extends TestCase
{
    public function test_golden_set(): void
    {
        $goldenSet = json_decode(
            file_get_contents('tests/fixtures/golden_responses.json'),
            true
        );

        foreach ($goldenSet as $case) {
            $response = $this->aiService->generateResponse($case['query']);

            // Check similarity to expected response
            $similarity = $this->calculateSimilarity($response, $case['expected']);

            $this->assertGreaterThan(
                0.8,
                $similarity,
                "Response for '{$case['query']}' deviates too much from golden response"
            );
        }
    }
}
```

## Continuous Monitoring

### Quality Dashboard

```sql
-- Daily quality metrics
SELECT
    DATE(created_at) as date,
    variant,
    COUNT(*) as total_interactions,
    AVG(response_quality) as avg_quality,
    AVG(CASE WHEN user_satisfied THEN 1 ELSE 0 END) as satisfaction_rate,
    AVG(CASE WHEN escalated THEN 1 ELSE 0 END) as escalation_rate
FROM prompt_test_logs
WHERE test_name = 'welcome_message_v2'
GROUP BY DATE(created_at), variant
ORDER BY date DESC;
```

### Alert Conditions

```php
class PromptQualityMonitor
{
    public function checkQuality(): void
    {
        $metrics = $this->getRecentMetrics();

        // Alert if quality drops
        if ($metrics['avg_quality'] < 0.7) {
            $this->alert('Prompt quality dropped below threshold');
        }

        // Alert if escalation spikes
        if ($metrics['escalation_rate'] > 0.2) {
            $this->alert('Escalation rate too high');
        }

        // Alert if response time increases
        if ($metrics['avg_response_time'] > 3000) {
            $this->alert('Response time too slow');
        }
    }
}
```

## Best Practices

### DO

- Define clear success metrics before testing
- Run tests for at least 2 weeks
- Ensure statistical significance before concluding
- Test with diverse user segments
- Document all prompt changes

### DON'T

- Stop tests early based on initial results
- Change prompts mid-test
- Ignore edge cases
- Test too many variants at once
- Forget to check for negative impacts
