---
id: injection-004-output-filtering
title: Output Filtering & Validation
impact: HIGH
impactDescription: "Filter AI responses to prevent sensitive data leakage and enforce quality"
category: injection
tags: [security, output, filtering, validation]
relatedRules: [injection-002-system-prompt-protection, design-004-output-format]
---

## Why This Matters

Even with input sanitization and guardrails, AI can still:
- Accidentally leak sensitive data
- Generate hallucinated credentials
- Include internal markers in responses
- Output malformed content
- Exceed expected length limits

Output filtering is the last line of defense.

## The Problem

Potential output issues:
- API keys or credentials in response
- System prompt fragments leaked
- Internal debugging information
- Personally identifiable information (PII)
- Malformed JSON or structured data
- Offensive content that slipped through

## Solution

### Before (No Output Filtering)

```php
// Direct response to user
return $llmResponse['content'];
```

### After (Filtered Output)

```php
// OutputFilterService.php
class OutputFilterService
{
    private array $sensitivePatterns = [
        // API keys and tokens
        'api_keys' => [
            '/sk-[a-zA-Z0-9]{32,}/',  // OpenAI
            '/[a-zA-Z0-9]{32,}\.[a-zA-Z0-9]{6}\.[a-zA-Z0-9_-]{27,}/',  // JWT
            '/Bearer\s+[a-zA-Z0-9._-]+/',
            '/api[_-]?key\s*[=:]\s*["\']?[\w-]{20,}/i',
            '/secret[_-]?key\s*[=:]\s*["\']?[\w-]{20,}/i',
        ],

        // Internal markers
        'internal' => [
            '/\[SYSTEM\]/i',
            '/\[INTERNAL\]/i',
            '/\[DEBUG\]/i',
            '/<<<\s*END\s*SYSTEM/i',
            '/---\s*END\s*PROMPT\s*---/i',
        ],

        // PII patterns
        'pii' => [
            '/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/',  // Phone
            '/\b\d{13}\b/',  // Thai ID
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', // Email (unless support email)
        ],

        // Database/code artifacts
        'code_leak' => [
            '/SELECT\s+\*\s+FROM\s+\w+/i',
            '/INSERT\s+INTO\s+\w+/i',
            '/password\s*[=:]\s*["\'][^"\']+["\']/i',
            '/\$_ENV\[/i',
            '/config\([\'"][^"\']+["\']\)/i',
        ],
    ];

    // Allowed patterns (whitelist)
    private array $allowedEmails = [
        'support@botjao.com',
        'sales@botjao.com',
        'help@botjao.com',
    ];

    public function filter(string $response): FilterResult
    {
        $issues = [];
        $filtered = $response;

        // Check each pattern category
        foreach ($this->sensitivePatterns as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $response, $matches)) {
                    foreach ($matches[0] as $match) {
                        // Check whitelist
                        if ($this->isAllowed($match, $category)) {
                            continue;
                        }

                        $issues[] = [
                            'category' => $category,
                            'pattern' => $pattern,
                            'match' => $this->truncate($match, 50),
                        ];

                        // Redact
                        $filtered = str_replace($match, '[REDACTED]', $filtered);
                    }
                }
            }
        }

        // Log if issues found
        if (!empty($issues)) {
            Log::warning('Sensitive content filtered from response', [
                'issues' => $issues,
                'original_length' => strlen($response),
                'filtered_length' => strlen($filtered),
            ]);
        }

        return new FilterResult(
            content: $filtered,
            issues: $issues,
            wasModified: !empty($issues)
        );
    }

    private function isAllowed(string $value, string $category): bool
    {
        if ($category === 'pii') {
            // Allow whitelisted emails
            foreach ($this->allowedEmails as $email) {
                if (stripos($value, $email) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    private function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }
        return substr($value, 0, $length) . '...';
    }
}
```

### Quality Validation

```php
// ResponseValidator.php
class ResponseValidator
{
    public function validate(string $response, array $options = []): ValidationResult
    {
        $issues = [];

        // Length check
        $maxLength = $options['max_length'] ?? 2000;
        if (mb_strlen($response) > $maxLength) {
            $issues[] = 'Response exceeds max length';
            $response = mb_substr($response, 0, $maxLength) . '...';
        }

        // Empty check
        if (empty(trim($response))) {
            $issues[] = 'Response is empty';
            $response = $this->getFallbackResponse($options['language'] ?? 'th');
        }

        // Repetition check (AI sometimes loops)
        if ($this->hasExcessiveRepetition($response)) {
            $issues[] = 'Response has excessive repetition';
            $response = $this->truncateRepetition($response);
        }

        // Format validation (if expecting JSON)
        if (($options['format'] ?? null) === 'json') {
            $parsed = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $issues[] = 'Invalid JSON format';
                // Try to extract JSON from markdown code block
                $response = $this->extractJson($response) ?? $response;
            }
        }

        // Incomplete sentence check
        if ($this->hasIncompleteSentence($response)) {
            $issues[] = 'Response appears truncated';
        }

        return new ValidationResult(
            content: $response,
            isValid: empty($issues),
            issues: $issues
        );
    }

    private function hasExcessiveRepetition(string $response): bool
    {
        // Check for repeated phrases (> 3 times)
        $words = str_word_count(strtolower($response), 1);
        $frequency = array_count_values($words);

        foreach ($frequency as $word => $count) {
            if (strlen($word) > 5 && $count > 5) {
                return true;
            }
        }

        // Check for repeated sentences
        $sentences = preg_split('/[.!?]+/', $response);
        $uniqueSentences = array_unique(array_map('trim', $sentences));

        if (count($sentences) > 3 && count($uniqueSentences) < count($sentences) / 2) {
            return true;
        }

        return false;
    }

    private function hasIncompleteSentence(string $response): bool
    {
        $trimmed = trim($response);
        $lastChar = mb_substr($trimmed, -1);

        // Ends with punctuation is complete
        if (in_array($lastChar, ['.', '!', '?', '。', ')', ']', '"', '\'', 'ครับ', 'ค่ะ'])) {
            return false;
        }

        // Short responses might be complete
        if (mb_strlen($trimmed) < 50) {
            return false;
        }

        return true;
    }

    private function extractJson(string $response): ?string
    {
        // Try to extract JSON from markdown code block
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $response, $matches)) {
            $json = trim($matches[1]);
            if (json_decode($json) !== null) {
                return $json;
            }
        }

        // Try to find JSON object/array
        if (preg_match('/\{[\s\S]*\}|\[[\s\S]*\]/', $response, $matches)) {
            $json = $matches[0];
            if (json_decode($json) !== null) {
                return $json;
            }
        }

        return null;
    }

    private function getFallbackResponse(string $language): string
    {
        return match ($language) {
            'th' => 'ขอโทษครับ เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง',
            default => "I'm sorry, an error occurred. Please try again.",
        };
    }
}
```

## Implementation

```php
// RAGService.php
public function processMessage(Message $message): array
{
    $response = $this->llmService->chat($this->buildMessages($message));

    // Apply output filtering pipeline
    $filter = new OutputFilterService();
    $validator = new ResponseValidator();

    // Step 1: Filter sensitive content
    $filtered = $filter->filter($response['content']);

    if ($filtered->wasModified) {
        // Track for monitoring
        $this->trackFilteredResponse($message, $filtered->issues);
    }

    // Step 2: Validate quality
    $validated = $validator->validate($filtered->content, [
        'max_length' => 2000,
        'language' => $message->conversation->bot->language,
        'format' => $this->getExpectedFormat($message),
    ]);

    return [
        'content' => $validated->content,
        'filtered' => $filtered->wasModified,
        'validation_issues' => $validated->issues,
    ];
}
```

## Testing

```php
public function test_api_keys_are_redacted(): void
{
    $filter = new OutputFilterService();

    $response = "Here is your API key: sk-abc123def456ghi789jkl012mno345pqr";
    $result = $filter->filter($response);

    $this->assertStringContainsString('[REDACTED]', $result->content);
    $this->assertTrue($result->wasModified);
}

public function test_allowed_emails_pass_through(): void
{
    $filter = new OutputFilterService();

    $response = "Contact us at support@botjao.com for help.";
    $result = $filter->filter($response);

    $this->assertStringContainsString('support@botjao.com', $result->content);
    $this->assertFalse($result->wasModified);
}

public function test_excessive_repetition_is_detected(): void
{
    $validator = new ResponseValidator();

    $response = "This is great. This is great. This is great. This is great.";
    $result = $validator->validate($response);

    $this->assertContains('Response has excessive repetition', $result->issues);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- OutputFilterService in app/Services/AI/
- Whitelist support emails in config
- Log filtered responses for review
- Apply platform-specific formatting after filtering
