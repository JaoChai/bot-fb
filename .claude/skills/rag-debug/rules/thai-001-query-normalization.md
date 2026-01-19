---
id: thai-001-query-normalization
title: Thai Query Normalization Issues
impact: HIGH
impactDescription: "Thai queries not matching due to encoding/whitespace issues"
category: thai
tags: [thai, normalization, encoding, unicode]
relatedRules: [thai-002-thai-embedding, search-002-wrong-results]
---

## Symptom

- Thai queries don't find documents that clearly exist
- Same text in document and query don't match
- Whitespace or character issues
- Copy-pasted Thai text behaves differently

## Root Cause

1. Different Thai character encodings (TIS-620 vs UTF-8)
2. Zero-width characters in Thai text
3. Different whitespace characters
4. Character normalization (NFC vs NFD)
5. Hidden combining characters

## Diagnosis

### Quick Check

```php
// Check for hidden characters
$query = "สินค้า";
Log::info('Query bytes', [
    'raw' => $query,
    'hex' => bin2hex($query),
    'length' => mb_strlen($query),
    'byte_length' => strlen($query),
]);

// Expected for 6 Thai chars: ~18 bytes (3 bytes each UTF-8)
```

### Detailed Analysis

```php
// Compare query vs document text
$query = "นโยบายคืนเงิน";
$docText = $document->content;

// Normalize both
$normalizedQuery = Normalizer::normalize($query, Normalizer::FORM_C);
$normalizedDoc = Normalizer::normalize($docText, Normalizer::FORM_C);

// Check if substring exists
$found = mb_strpos($normalizedDoc, $normalizedQuery) !== false;

Log::info('Thai text comparison', [
    'query_bytes' => bin2hex($query),
    'normalized_bytes' => bin2hex($normalizedQuery),
    'found_after_normalize' => $found,
]);
```

## Solution

### Fix Steps

1. **Normalize on input**
```php
// Add to query preprocessing
class QueryNormalizer
{
    public function normalize(string $query): string
    {
        // Unicode normalization (NFC)
        $query = Normalizer::normalize($query, Normalizer::FORM_C);

        // Remove zero-width characters
        $query = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $query);

        // Normalize whitespace
        $query = preg_replace('/\s+/u', ' ', $query);

        return trim($query);
    }
}
```

2. **Normalize documents on index**
```php
// Apply same normalization when indexing
public function indexDocument(string $content): void
{
    $normalized = $this->normalizer->normalize($content);
    // Generate embedding from normalized content
    $embedding = $this->embeddingService->embed($normalized);
    // Store both original and normalized
}
```

3. **Use consistent encoding**
```php
// Ensure UTF-8 throughout
'database' => [
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
],
```

### Code Fix

```php
// Complete Thai text normalizer
class ThaiTextNormalizer
{
    /**
     * Zero-width characters to remove
     */
    private array $zeroWidthChars = [
        "\u{200B}",  // Zero-width space
        "\u{200C}",  // Zero-width non-joiner
        "\u{200D}",  // Zero-width joiner
        "\u{FEFF}",  // Byte order mark
        "\u{00AD}",  // Soft hyphen
    ];

    public function normalize(string $text): string
    {
        // 1. Unicode NFC normalization
        if (class_exists('Normalizer')) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        // 2. Remove zero-width characters
        $text = str_replace($this->zeroWidthChars, '', $text);

        // 3. Normalize Thai specific characters
        $text = $this->normalizeThaiChars($text);

        // 4. Normalize whitespace
        $text = preg_replace('/\s+/u', ' ', $text);

        // 5. Trim
        return trim($text);
    }

    private function normalizeThaiChars(string $text): string
    {
        // Replace common Thai character variations
        $replacements = [
            // Mai han akat variations
            "\u{0E31}" => "\u{0E31}",  // Standardize sara am
            // Sara aa variations
            "ำา" => "ำ",  // Double sara aa
        ];

        return strtr($text, $replacements);
    }

    /**
     * Check if text needs normalization
     */
    public function needsNormalization(string $text): bool
    {
        $normalized = $this->normalize($text);
        return $text !== $normalized;
    }

    /**
     * Debug text encoding
     */
    public function debug(string $text): array
    {
        return [
            'original' => $text,
            'normalized' => $this->normalize($text),
            'length' => mb_strlen($text),
            'byte_length' => strlen($text),
            'encoding' => mb_detect_encoding($text, ['UTF-8', 'TIS-620', 'ISO-8859-11']),
            'hex' => bin2hex(mb_substr($text, 0, 20)),
            'has_zero_width' => preg_match('/[\x{200B}-\x{200D}\x{FEFF}]/u', $text) === 1,
        ];
    }
}

// Integration with search service
class SemanticSearchService
{
    public function __construct(
        private ThaiTextNormalizer $normalizer,
        private EmbeddingService $embeddingService,
    ) {}

    public function search(string $query, int $botId): Collection
    {
        // Always normalize Thai queries
        $normalizedQuery = $this->normalizer->normalize($query);

        Log::debug('Query normalized', [
            'original' => $query,
            'normalized' => $normalizedQuery,
            'changed' => $query !== $normalizedQuery,
        ]);

        $embedding = $this->embeddingService->embed($normalizedQuery);
        // ... rest of search
    }
}
```

## Verification

```php
// Test normalization
$normalizer = new ThaiTextNormalizer();

$testCases = [
    "สินค้า\u{200B}ราคา",  // With zero-width space
    "นโยบาย  คืนเงิน",     // Double space
    "ติดต่อ​เรา",          // Hidden character
];

foreach ($testCases as $text) {
    $result = $normalizer->debug($text);
    Log::info('Normalization test', $result);

    assert($result['has_zero_width'] === false || $result['normalized'] !== $text);
}

// Test search with normalized query
$query = "นโยบาย​คืนเงิน";  // Has hidden char
$results = $searchService->search($query, $botId);
assert($results->isNotEmpty(), 'Should find results after normalization');
```

## Prevention

- Always normalize on input (queries and documents)
- Use UTF-8 throughout the stack
- Log normalization changes for debugging
- Add normalization to document indexing pipeline
- Test with copy-pasted Thai text

## Project-Specific Notes

**BotFacebook Context:**
- Normalizer: `ThaiTextNormalizer` service
- Applied in: `SemanticSearchService`, `EmbeddingService`
- Common issue: LINE messages with hidden characters
- Config: Normalize both query and index
