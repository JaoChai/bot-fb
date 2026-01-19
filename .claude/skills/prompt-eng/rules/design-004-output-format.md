---
id: design-004-output-format
title: Output Format Specification
impact: MEDIUM
impactDescription: "Define consistent output formats for reliable parsing and display"
category: design
tags: [output, format, json, structured]
relatedRules: [design-005-prompt-structure, pattern-001-chain-of-thought]
---

## Why This Matters

Unstructured output leads to:
- Inconsistent UI rendering
- Failed parsing (JSON, markdown)
- Extra post-processing work
- Poor user experience

Structured output enables:
- Reliable parsing
- Consistent display
- Easy integration
- Better analytics

## The Problem

Without format specification:
- AI responds in random formats
- Sometimes markdown, sometimes plain text
- JSON parsing fails
- Lists have inconsistent styling

## Solution

### Before (No Format Spec)

```
Answer the user's question about our products.
```

AI might respond:
- Plain text paragraph
- Markdown with headers
- Bullet list
- Numbered list
- Mixed formats

### After (Clear Format Spec)

```
## Output Format

### For Text Responses
- Use Thai language for Thai questions
- Keep paragraphs under 3 sentences
- Use bullet points for lists (not numbers unless ordering matters)
- Bold **important terms** sparingly
- No headers unless response is long (>5 paragraphs)

### For Structured Data
When asked for product information, respond in this JSON format:
```json
{
  "product": "string",
  "price": "number",
  "features": ["string"],
  "available": boolean
}
```

### For Step-by-Step Instructions
1. Start with brief overview
2. Number each step
3. Include code blocks for commands
4. End with expected result

Example:
"วิธีเชื่อมต่อ LINE Bot:

1. **ไปที่ LINE Developers Console** แล้วสร้าง Channel ใหม่
2. **คัดลอก Channel Access Token**
3. **วางใน Bot Settings** ของ BotFacebook

✅ เมื่อเสร็จแล้ว bot จะขึ้นสถานะ 'Connected'"
```

### Format Templates

```php
// PromptTemplates.php
class PromptTemplates
{
    public static function textResponse(): string
    {
        return <<<EOT
## Response Format
- Respond in the same language as the question
- Keep responses concise (under 200 words)
- Use bullet points for lists
- Bold key terms sparingly
- End with a follow-up question if appropriate
EOT;
    }

    public static function jsonResponse(array $schema): string
    {
        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT);

        return <<<EOT
## Response Format
Respond ONLY with valid JSON matching this schema:
```json
{$schemaJson}
```

Do not include any text before or after the JSON.
Do not use markdown code blocks in your response.
EOT;
    }

    public static function stepByStep(): string
    {
        return <<<EOT
## Response Format
1. Brief overview (1 sentence)
2. Numbered steps (use 1. 2. 3.)
3. Code in ```language``` blocks
4. End with expected result (use ✅)
EOT;
    }

    public static function comparison(): string
    {
        return <<<EOT
## Response Format
Use a markdown table for comparison:
| Feature | Option A | Option B |
|---------|----------|----------|
| ...     | ...      | ...      |

Then summarize with a recommendation.
EOT;
    }
}
```

### Dynamic Format Selection

```php
// FormatService.php
public function selectFormat(string $query, array $context): string
{
    // Detect intent
    if ($this->isComparisonQuestion($query)) {
        return PromptTemplates::comparison();
    }

    if ($this->isHowToQuestion($query)) {
        return PromptTemplates::stepByStep();
    }

    if ($this->requiresStructuredData($context)) {
        return PromptTemplates::jsonResponse($context['schema']);
    }

    return PromptTemplates::textResponse();
}

private function isHowToQuestion(string $query): bool
{
    return preg_match('/^(how|วิธี|ยังไง|ทำยังไง)/iu', trim($query));
}

private function isComparisonQuestion(string $query): bool
{
    return preg_match('/(compare|vs|versus|เปรียบเทียบ|ต่างกัน)/iu', $query);
}
```

## Implementation

### JSON Response Parsing

```php
// ResponseParser.php
public function parseStructured(string $response): ?array
{
    // Extract JSON from response (handle markdown code blocks)
    $json = $response;

    // Remove markdown code block if present
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $response, $matches)) {
        $json = $matches[1];
    }

    // Try to parse
    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        Log::warning('Failed to parse JSON response', [
            'response' => $response,
            'error' => json_last_error_msg(),
        ]);
        return null;
    }

    return $data;
}
```

### Response Formatting

```php
// MessageFormatter.php
public function formatForPlatform(string $response, string $platform): string
{
    return match ($platform) {
        'line' => $this->formatForLine($response),
        'telegram' => $this->formatForTelegram($response),
        default => $response,
    };
}

private function formatForLine(string $response): string
{
    // LINE doesn't support full markdown
    // Convert markdown to LINE-compatible format
    $response = preg_replace('/\*\*(.*?)\*\*/', '$1', $response); // Remove bold
    $response = preg_replace('/```.*?\n(.*?)```/s', '$1', $response); // Remove code blocks

    return $response;
}

private function formatForTelegram(string $response): string
{
    // Telegram supports HTML
    $response = preg_replace('/\*\*(.*?)\*\*/', '<b>$1</b>', $response);
    $response = preg_replace('/```(\w+)?\n(.*?)```/s', '<pre>$2</pre>', $response);

    return $response;
}
```

## Testing

```php
public function test_json_response_is_valid(): void
{
    $prompt = $this->service->buildPrompt([
        'format' => 'json',
        'schema' => ['name' => 'string', 'count' => 'number'],
    ]);

    $response = $this->llm->complete($prompt);
    $parsed = $this->parser->parseStructured($response);

    $this->assertNotNull($parsed);
    $this->assertArrayHasKey('name', $parsed);
}

public function test_step_format_has_numbered_steps(): void
{
    $prompt = PromptTemplates::stepByStep();
    $response = $this->llm->complete("How to create a bot? " . $prompt);

    $this->assertMatchesRegularExpression('/1\.\s/', $response);
    $this->assertMatchesRegularExpression('/2\.\s/', $response);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- LINE: Plain text or simple formatting
- Telegram: HTML supported
- Web: Full markdown supported
- Format templates in PromptTemplates class
