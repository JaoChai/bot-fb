---
id: thai-002-thai-embedding
title: Thai Text Embedding Quality Issues
impact: HIGH
impactDescription: "Thai text embeddings have poor semantic representation"
category: thai
tags: [thai, embedding, model, multilingual]
relatedRules: [embed-002-model-consistency, thai-001-query-normalization]
---

## Symptom

- Thai queries return irrelevant results
- Semantic similarity scores very low for obvious matches
- English queries work well but Thai doesn't
- Similar Thai words not recognized as related

## Root Cause

1. Embedding model not trained on Thai
2. Model tokenizes Thai poorly
3. Wrong embedding model selected
4. Thai vocabulary not in model's training data
5. Text too short for meaningful embedding

## Diagnosis

### Quick Check

```php
// Test Thai embedding quality
$thaiQuery = "นโยบายคืนเงิน";
$thaiDoc = "การคืนเงินสามารถทำได้ภายใน 30 วัน";

$queryEmb = $this->embeddingService->embed($thaiQuery);
$docEmb = $this->embeddingService->embed($thaiDoc);

$similarity = $this->cosineSimilarity($queryEmb, $docEmb);

Log::info('Thai embedding test', [
    'similarity' => $similarity,
    'expected' => '>0.6 for related content',
]);
```

### Detailed Analysis

```php
// Compare model performance
$models = [
    'text-embedding-3-large',
    'text-embedding-3-small',
    'text-embedding-ada-002',
];

$testPairs = [
    ['คืนเงิน', 'refund policy การคืนสินค้า'],
    ['ราคา', 'ค่าใช้จ่ายและราคาสินค้า'],
];

foreach ($models as $model) {
    foreach ($testPairs as [$query, $doc]) {
        $sim = $this->testSimilarity($model, $query, $doc);
        Log::info("Model test: {$model}", [
            'query' => $query,
            'doc_preview' => substr($doc, 0, 30),
            'similarity' => $sim,
        ]);
    }
}
```

## Solution

### Fix Steps

1. **Use multilingual model**
```php
// config/rag.php
'embedding' => [
    'model' => 'text-embedding-3-large',  // Best for Thai
    'dimensions' => 3072,  // Full dimensions for Thai
],

// Don't use 'text-embedding-ada-002' for Thai - poor performance
```

2. **Add context to short queries**
```php
// Enhance short Thai queries
private function enhanceThaiQuery(string $query): string
{
    // Very short queries need context
    if (mb_strlen($query) < 10) {
        return "คำถาม: {$query}";  // Add "question:" prefix
    }
    return $query;
}
```

3. **Use bilingual indexing**
```php
// Store both Thai and English versions
public function indexDocument(Document $doc): void
{
    $thaiContent = $doc->content;

    // If content is in Thai, optionally add English translation
    // for hybrid retrieval
    $embedding = $this->embeddingService->embed($thaiContent);

    // Store with language metadata
    $doc->update([
        'embedding' => $embedding,
        'language' => 'th',
    ]);
}
```

### Code Fix

```php
// Thai-optimized embedding service
class ThaiEmbeddingService
{
    private string $model = 'text-embedding-3-large';
    private int $dimensions = 3072;

    /**
     * Minimum text length for quality embeddings
     */
    private int $minLength = 5;

    public function embed(string $text): array
    {
        // 1. Validate text
        if (mb_strlen($text) < $this->minLength) {
            Log::warning('Text too short for quality embedding', [
                'text' => $text,
                'length' => mb_strlen($text),
            ]);
            $text = $this->padText($text);
        }

        // 2. Check language
        $language = $this->detectLanguage($text);

        // 3. Preprocess based on language
        $processed = $language === 'th'
            ? $this->preprocessThai($text)
            : $text;

        // 4. Generate embedding
        $response = $this->callOpenAI($processed);

        return $response['data'][0]['embedding'];
    }

    private function preprocessThai(string $text): string
    {
        // Add context markers for Thai
        if (!preg_match('/[:：]/', $text)) {
            // If no colon (likely a query), add context
            if (mb_strlen($text) < 30) {
                $text = "ข้อความ: {$text}";
            }
        }

        return $text;
    }

    private function padText(string $text): string
    {
        // Pad very short text to get better embedding
        return str_repeat($text . ' ', 3);
    }

    private function detectLanguage(string $text): string
    {
        // Simple Thai detection
        if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $text)) {
            return 'th';
        }
        return 'en';
    }

    private function callOpenAI(string $text): array
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.openai.api_key'),
        ])->post('https://api.openai.com/v1/embeddings', [
            'model' => $this->model,
            'input' => $text,
            'dimensions' => $this->dimensions,
        ])->json();
    }

    /**
     * Test embedding quality for Thai
     */
    public function testQuality(string $query, string $expectedMatch): float
    {
        $queryEmb = $this->embed($query);
        $matchEmb = $this->embed($expectedMatch);

        return $this->cosineSimilarity($queryEmb, $matchEmb);
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0;
        $normA = 0;
        $normB = 0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
}

// Test command
class TestThaiEmbeddings extends Command
{
    protected $signature = 'rag:test-thai-embeddings';

    public function handle(ThaiEmbeddingService $service): int
    {
        $testCases = [
            ['คืนเงิน', 'นโยบายการคืนเงินและเปลี่ยนสินค้า', 0.6],
            ['ติดต่อ', 'ช่องทางการติดต่อเรา', 0.6],
            ['ราคา', 'รายการราคาสินค้าทั้งหมด', 0.5],
            ['ส่งฟรี', 'บริการจัดส่งฟรีทั่วประเทศ', 0.6],
        ];

        $passed = 0;
        foreach ($testCases as [$query, $doc, $minSim]) {
            $similarity = $service->testQuality($query, $doc);
            $pass = $similarity >= $minSim;

            $this->line(sprintf(
                "%s Query: %s → Similarity: %.3f (min: %.1f)",
                $pass ? '✓' : '✗',
                $query,
                $similarity,
                $minSim
            ));

            if ($pass) $passed++;
        }

        $this->info("Passed: {$passed}/" . count($testCases));

        return $passed === count($testCases) ? 0 : 1;
    }
}
```

## Verification

```bash
# Test Thai embedding quality
php artisan rag:test-thai-embeddings

# Expected output:
# ✓ Query: คืนเงิน → Similarity: 0.723 (min: 0.6)
# ✓ Query: ติดต่อ → Similarity: 0.681 (min: 0.6)
# ...
# Passed: 4/4
```

```php
// Manual verification
$service = app(ThaiEmbeddingService::class);
$sim = $service->testQuality('สินค้า', 'รายการสินค้าและราคา');
assert($sim > 0.5, "Thai similarity should be > 0.5, got: {$sim}");
```

## Prevention

- Use `text-embedding-3-large` for Thai content
- Test embedding quality before production
- Monitor semantic similarity scores
- Compare Thai vs English performance
- Keep full 3072 dimensions for Thai

## Project-Specific Notes

**BotFacebook Context:**
- Model: `text-embedding-3-large` (required for Thai)
- Dimensions: 3072 (don't reduce for Thai)
- Test: `php artisan rag:test-thai-embeddings`
- Common issue: Short queries like "ราคา" need padding
