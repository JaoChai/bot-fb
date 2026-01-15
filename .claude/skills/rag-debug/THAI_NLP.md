# Thai NLP Guide

## Thai Language Challenges

### Word Segmentation

Thai has no spaces between words:
```
"สวัสดีครับผมชื่อสมชาย" → "สวัสดี|ครับ|ผม|ชื่อ|สมชาย"
```

### Character Variations

- **วรรณยุกต์ (Tone marks):** ่ ้ ๊ ๋
- **สระ (Vowels):** Can be above, below, before, or after consonant
- **ไม้หันอากาศ (Shortener):** Changes vowel sound

### Common Issues

| Issue | Example | Impact |
|-------|---------|--------|
| Typos | ครัช vs ครับ | Embedding mismatch |
| Formal/Informal | ค่ะ vs คะ | Different embeddings |
| Spacing | มีไหม vs มี ไหม | Tokenization differs |
| Transliteration | iPhone vs ไอโฟน | Not matched |

## Normalization

### Text Cleaning

```php
public function normalizeThaiText(string $text): string
{
    // Remove zero-width characters
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);

    // Normalize whitespace
    $text = preg_replace('/\s+/', ' ', trim($text));

    // Normalize Thai characters
    $text = $this->normalizeThaiChars($text);

    return $text;
}

private function normalizeThaiChars(string $text): string
{
    // Common normalizations
    $replacements = [
        'ๆ' => '', // Repeat mark
        '์' => '', // Silent mark (sometimes)
        'ฯ' => '', // Etc mark
    ];

    return strtr($text, $replacements);
}
```

### Handling Particles

```php
// Thai ending particles that don't change meaning
$particles = ['ครับ', 'ค่ะ', 'คะ', 'นะ', 'จ้า', 'จ๊ะ', 'ค้าบ'];

public function removeParticles(string $text): string
{
    foreach ($this->particles as $particle) {
        $text = preg_replace("/\s*{$particle}\s*$/u", '', $text);
    }
    return $text;
}
```

## Embedding Strategies

### Recommended Models for Thai

| Model | Thai Support | Dimension | Notes |
|-------|--------------|-----------|-------|
| OpenAI text-embedding-3 | Good | 1536/3072 | Best general |
| Cohere multilingual | Good | 1024 | Good for Thai |
| BGE-M3 | Excellent | 1024 | Open source |
| Thai-specific | Native | Varies | Specialized |

### Query Expansion

```php
public function expandThaiQuery(string $query): array
{
    $expanded = [$query];

    // Add common variations
    if (str_ends_with($query, 'ครับ')) {
        $expanded[] = substr($query, 0, -4); // Remove particle
    }

    // Add transliterations
    $transliterations = $this->getTransliterations($query);
    $expanded = array_merge($expanded, $transliterations);

    return array_unique($expanded);
}

private function getTransliterations(string $text): array
{
    // Common Thai-English mappings
    $mappings = [
        'ไลน์' => 'LINE',
        'ไอโฟน' => 'iPhone',
        'เฟซบุ๊ก' => 'Facebook',
        'เว็บ' => 'web',
    ];

    $results = [];
    foreach ($mappings as $thai => $english) {
        if (str_contains($text, $thai)) {
            $results[] = str_replace($thai, $english, $text);
        }
        if (str_contains(strtolower($text), strtolower($english))) {
            $results[] = str_replace($english, $thai, $text);
        }
    }

    return $results;
}
```

## Search Optimization

### Hybrid Search for Thai

```php
public function thaiHybridSearch(string $query, int $kbId): Collection
{
    $embedding = $this->embed($query);
    $normalized = $this->normalizeThaiText($query);

    // Semantic search
    $semantic = $this->vectorSearch($embedding, $kbId, 50);

    // Keyword search with Thai tokenization
    $keywords = $this->keywordSearch($normalized, $kbId);

    // Combine with weighted scoring
    return $this->combineResults($semantic, $keywords, [
        'semantic_weight' => 0.6,
        'keyword_weight' => 0.4,
    ]);
}
```

### Full-Text Search Configuration

```sql
-- Create Thai text search configuration
CREATE TEXT SEARCH CONFIGURATION thai (COPY = simple);

-- Create GIN index for Thai text
CREATE INDEX idx_chunks_content_thai
ON knowledge_chunks
USING GIN (to_tsvector('simple', content));
```

### N-gram for Thai

```php
// Generate character n-grams for fuzzy matching
public function generateNgrams(string $text, int $n = 3): array
{
    $chars = mb_str_split($text);
    $ngrams = [];

    for ($i = 0; $i <= count($chars) - $n; $i++) {
        $ngrams[] = implode('', array_slice($chars, $i, $n));
    }

    return $ngrams;
}

// Usage for fuzzy matching
public function fuzzyMatch(string $query, string $target): float
{
    $queryNgrams = $this->generateNgrams($query);
    $targetNgrams = $this->generateNgrams($target);

    $intersection = array_intersect($queryNgrams, $targetNgrams);
    $union = array_unique(array_merge($queryNgrams, $targetNgrams));

    return count($intersection) / count($union); // Jaccard similarity
}
```

## Common Thai Query Patterns

### FAQ Patterns

```php
$faqPatterns = [
    '/^(.+)เท่าไ(ห)?ร่?$/u' => 'price_query', // "X เท่าไหร่"
    '/^(.+)(มี)?ไหม$/u' => 'availability_query', // "X มีไหม"
    '/^(ยัง)?ไง|อย่างไร$/u' => 'how_to_query', // "ทำยังไง"
    '/^ที่ไหน|อยู่ไหน$/u' => 'location_query', // "อยู่ที่ไหน"
    '/^เมื่อไ(ห)?ร่?$/u' => 'time_query', // "เมื่อไหร่"
];
```

### Intent Detection

```php
public function detectIntent(string $query): string
{
    $query = $this->normalizeThaiText($query);

    // Price intent
    if (preg_match('/(ราคา|เท่าไ(ห)?ร่?|กี่บาท)/u', $query)) {
        return 'price';
    }

    // Availability intent
    if (preg_match('/(มีไหม|มี.+ไหม|ยังมี)/u', $query)) {
        return 'availability';
    }

    // Order intent
    if (preg_match('/(สั่ง|ซื้อ|จอง|order)/ui', $query)) {
        return 'order';
    }

    return 'general';
}
```

## Debugging Thai Search

### Query Analysis

```php
public function analyzeThaiQuery(string $query): array
{
    return [
        'original' => $query,
        'length' => mb_strlen($query),
        'thai_ratio' => $this->getThaiCharRatio($query),
        'has_particles' => $this->hasParticles($query),
        'normalized' => $this->normalizeThaiText($query),
        'tokens' => $this->tokenize($query),
        'intent' => $this->detectIntent($query),
    ];
}

private function getThaiCharRatio(string $text): float
{
    $thaiChars = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $text);
    $totalChars = mb_strlen($text);

    return $totalChars > 0 ? $thaiChars / $totalChars : 0;
}
```

### Common Issues Checklist

- [ ] Query properly normalized
- [ ] Particles removed for matching
- [ ] Transliterations considered
- [ ] Threshold lowered for Thai (0.70 vs 0.75)
- [ ] Hybrid search enabled
- [ ] Thai-specific embedding model used
