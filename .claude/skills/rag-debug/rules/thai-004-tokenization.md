---
id: thai-004-tokenization
title: Thai Tokenization Issues
impact: MEDIUM
impactDescription: "Thai text not properly tokenized, affecting search and embedding"
category: thai
tags: [thai, tokenization, nlp, word-segmentation]
relatedRules: [thai-001-query-normalization, thai-003-keyword-boost]
---

## Symptom

- Thai compound words not recognized
- Search doesn't find word boundaries correctly
- Keyword search misses partial matches
- Very different results for same meaning

## Root Cause

1. Thai has no spaces between words
2. PostgreSQL doesn't support Thai tokenization natively
3. Embedding models may tokenize Thai poorly
4. Compound words split incorrectly
5. Different tokenization at index vs query time

## Diagnosis

### Quick Check

```php
// Test how query is tokenized
$query = "นโยบายการคืนเงินสินค้า";

// Check PostgreSQL tokenization
$tokens = DB::select("
    SELECT token FROM ts_debug('simple', ?)
", [$query]);

Log::info('PostgreSQL tokenization', [
    'query' => $query,
    'tokens' => collect($tokens)->pluck('token')->toArray(),
]);

// For Thai, 'simple' treats entire string as one token (bad for search)
```

### Detailed Analysis

```php
// Compare different approaches
$query = "นโยบายคืนเงิน";

// Approach 1: Simple (no word breaking)
$simple = DB::select("SELECT * FROM ts_debug('simple', ?)", [$query]);

// Approach 2: Manual word breaks with spaces
$withSpaces = "นโยบาย คืนเงิน";
$spaced = DB::select("SELECT * FROM ts_debug('simple', ?)", [$withSpaces]);

Log::info('Tokenization comparison', [
    'simple_tokens' => count($simple),
    'spaced_tokens' => count($spaced),
]);
```

## Solution

### Fix Steps

1. **Use dictionary-based word breaking**
```php
// Simple Thai word segmentation for common terms
class ThaiWordBreaker
{
    private array $dictionary = [
        'นโยบาย', 'คืนเงิน', 'สินค้า', 'ติดต่อ', 'ราคา',
        'บริการ', 'จัดส่ง', 'การชำระเงิน', 'โปรโมชั่น',
        // Add common business terms
    ];

    public function segment(string $text): string
    {
        // Sort dictionary by length (longest first)
        $sorted = $this->dictionary;
        usort($sorted, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        // Replace known words with spaced versions
        foreach ($sorted as $word) {
            $text = str_replace($word, " {$word} ", $text);
        }

        // Normalize spaces
        return preg_replace('/\s+/', ' ', trim($text));
    }
}
```

2. **N-gram approach for full coverage**
```php
// Generate character n-grams for Thai
private function generateThaiNgrams(string $text, int $n = 3): array
{
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $ngrams = [];

    for ($i = 0; $i <= count($chars) - $n; $i++) {
        $ngrams[] = implode('', array_slice($chars, $i, $n));
    }

    return $ngrams;
}
```

3. **Index with multiple representations**
```php
// Store both original and tokenized versions
public function indexDocument(Document $doc): void
{
    $original = $doc->content;
    $tokenized = $this->wordBreaker->segment($original);

    $doc->update([
        'content' => $original,
        'content_tokenized' => $tokenized,  // For full-text search
        'embedding' => $this->embeddingService->embed($original),
    ]);
}
```

### Code Fix

```php
// Complete Thai tokenization solution
class ThaiTokenizer
{
    /**
     * Common Thai words for dictionary-based segmentation
     */
    private array $commonWords = [
        // Business terms
        'นโยบาย', 'คืนเงิน', 'สินค้า', 'บริการ', 'ราคา',
        'โปรโมชั่น', 'ส่วนลด', 'การชำระเงิน', 'การจัดส่ง',
        'ติดต่อ', 'สอบถาม', 'รายละเอียด', 'เงื่อนไข',
        // Question words
        'อะไร', 'อย่างไร', 'ที่ไหน', 'เมื่อไหร่', 'ทำไม',
        // Common verbs
        'ต้องการ', 'สามารถ', 'จะ', 'ได้', 'ไม่',
    ];

    /**
     * Segment Thai text into words
     */
    public function segment(string $text): string
    {
        // 1. Normalize
        $text = $this->normalize($text);

        // 2. Dictionary-based segmentation
        $text = $this->dictionarySegment($text);

        // 3. Clean up
        return preg_replace('/\s+/', ' ', trim($text));
    }

    private function normalize(string $text): string
    {
        // Remove zero-width characters
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);
        return $text;
    }

    private function dictionarySegment(string $text): string
    {
        // Sort by length (longest first for greedy matching)
        $words = $this->commonWords;
        usort($words, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($words as $word) {
            // Add spaces around known words
            $text = preg_replace(
                '/(?<!\s)(' . preg_quote($word, '/') . ')(?!\s)/u',
                ' $1 ',
                $text
            );
        }

        return $text;
    }

    /**
     * Generate n-grams for Thai text
     */
    public function ngrams(string $text, int $n = 3): array
    {
        // Remove spaces for n-gram generation
        $text = preg_replace('/\s+/', '', $text);
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $ngrams = [];

        for ($i = 0; $i <= count($chars) - $n; $i++) {
            $ngrams[] = implode('', array_slice($chars, $i, $n));
        }

        return array_unique($ngrams);
    }

    /**
     * Get searchable tokens from Thai text
     */
    public function getSearchTokens(string $text): array
    {
        $segmented = $this->segment($text);
        $words = preg_split('/\s+/', $segmented);

        // Filter short tokens
        return array_filter($words, fn($w) => mb_strlen($w) >= 2);
    }
}

// Hybrid search with Thai tokenization
class ThaiAwareSearchService
{
    public function __construct(
        private ThaiTokenizer $tokenizer,
        private SemanticSearchService $semanticSearch,
    ) {}

    public function search(string $query, int $botId): Collection
    {
        // 1. Semantic search (uses embedding model's tokenization)
        $semantic = $this->semanticSearch->search($query, $botId);

        // 2. Keyword search with Thai tokenization
        $tokens = $this->tokenizer->getSearchTokens($query);
        $keyword = $this->keywordSearch($tokens, $botId);

        // 3. N-gram search for partial matches
        $ngrams = $this->tokenizer->ngrams($query, 3);
        $ngramResults = $this->ngramSearch($ngrams, $botId);

        // 4. Combine all results
        return $this->fuseResults($semantic, $keyword, $ngramResults);
    }

    private function keywordSearch(array $tokens, int $botId): Collection
    {
        if (empty($tokens)) {
            return collect();
        }

        // Search in tokenized content column
        $tsQuery = implode(' | ', array_map(fn($t) => $t . ':*', $tokens));

        return DB::table('knowledge_base_documents')
            ->select(['id', 'content'])
            ->where('bot_id', $botId)
            ->whereRaw("to_tsvector('simple', content_tokenized) @@ to_tsquery('simple', ?)", [$tsQuery])
            ->limit(20)
            ->get();
    }

    private function ngramSearch(array $ngrams, int $botId): Collection
    {
        if (empty($ngrams)) {
            return collect();
        }

        // LIKE search for n-grams (slower but catches partial matches)
        $query = DB::table('knowledge_base_documents')
            ->select(['id', 'content'])
            ->where('bot_id', $botId);

        // Match any n-gram
        $query->where(function ($q) use ($ngrams) {
            foreach (array_slice($ngrams, 0, 5) as $ngram) {
                $q->orWhere('content', 'LIKE', '%' . $ngram . '%');
            }
        });

        return $query->limit(10)->get();
    }

    private function fuseResults(
        Collection $semantic,
        Collection $keyword,
        Collection $ngram
    ): Collection {
        $scores = [];
        $docs = [];

        // Weight: semantic > keyword > ngram
        foreach ($semantic->values() as $rank => $doc) {
            $scores[$doc->id] = ($scores[$doc->id] ?? 0) + (0.5 / ($rank + 1));
            $docs[$doc->id] = $doc;
        }

        foreach ($keyword->values() as $rank => $doc) {
            $scores[$doc->id] = ($scores[$doc->id] ?? 0) + (0.3 / ($rank + 1));
            $docs[$doc->id] = $docs[$doc->id] ?? $doc;
        }

        foreach ($ngram->values() as $rank => $doc) {
            $scores[$doc->id] = ($scores[$doc->id] ?? 0) + (0.2 / ($rank + 1));
            $docs[$doc->id] = $docs[$doc->id] ?? $doc;
        }

        arsort($scores);

        return collect(array_keys($scores))
            ->take(10)
            ->map(fn($id) => $docs[$id]);
    }
}
```

## Verification

```php
// Test tokenization
$tokenizer = new ThaiTokenizer();

// Test segmentation
$text = "นโยบายการคืนเงินสินค้า";
$segmented = $tokenizer->segment($text);
Log::info('Segmentation test', [
    'input' => $text,
    'segmented' => $segmented,
    // Expected: "นโยบาย การ คืนเงิน สินค้า"
]);

// Test n-grams
$ngrams = $tokenizer->ngrams("คืนเงิน", 3);
assert(in_array('คืน', $ngrams) || in_array('ืนเ', $ngrams));

// Test search
$results = $searchService->search("คืนเงิน", $botId);
$hasRefundDoc = $results->first(fn($r) => str_contains($r->content, 'คืนเงิน'));
assert($hasRefundDoc !== null, 'Should find refund document');
```

## Prevention

- Build domain-specific Thai dictionary
- Test with compound Thai words
- Monitor search miss rates
- Consider using PyThaiNLP for server-side tokenization
- Regular dictionary updates

## Project-Specific Notes

**BotFacebook Context:**
- Dictionary: Common business Thai terms
- Approach: Dictionary + n-gram hybrid
- Column: `content_tokenized` for tokenized search
- Alternative: Consider PyThaiNLP API for better segmentation
