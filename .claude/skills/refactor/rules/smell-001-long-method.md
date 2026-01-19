---
id: smell-001-long-method
title: Long Method Detection
impact: HIGH
impactDescription: "Detect and refactor methods that are too long"
category: smell
tags: [code-smell, long-method, extract, readability]
relatedRules: [laravel-001-extract-method, react-001-extract-component]
---

## Code Smell

- Method > 30 lines
- Multiple levels of indentation
- Comments explaining "what happens next"
- Hard to describe what method does
- Scrolling required to read method

## Root Cause

1. Feature added incrementally
2. "Just add it here" mentality
3. No refactoring habit
4. Fear of creating new methods
5. Copy-paste development

## Detection

### Metrics

| Threshold | Risk Level | Action |
|-----------|------------|--------|
| > 30 lines | Medium | Consider refactoring |
| > 50 lines | High | Should refactor |
| > 100 lines | Critical | Must refactor |

### Quick Scan

```bash
# Find long methods in PHP
grep -n "function " *.php | while read line; do
  # Check method length between function and next function/class end
done

# Find long components in React
# Look for components > 200 lines
wc -l src/components/**/*.tsx | sort -n | tail -20
```

## Solution

### Before (Long Method)

```php
class BotService
{
    public function processMessage(Message $message): void
    {
        // Validate message
        if (empty($message->content)) {
            throw new InvalidArgumentException('Empty message');
        }
        if (strlen($message->content) > 4000) {
            throw new InvalidArgumentException('Message too long');
        }

        // Get bot settings
        $bot = $message->conversation->bot;
        $settings = $bot->settings;
        $systemPrompt = $settings->system_prompt ?? 'You are a helpful assistant';

        // Build context
        $context = [];
        $previousMessages = $message->conversation
            ->messages()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->reverse();

        foreach ($previousMessages as $prev) {
            $context[] = [
                'role' => $prev->role,
                'content' => $prev->content,
            ];
        }

        // Search knowledge base
        $knowledge = [];
        if ($bot->knowledgeBases()->exists()) {
            $embedding = $this->embeddingService->embed($message->content);
            $chunks = $this->searchService->search($embedding, $bot->id, 5);

            foreach ($chunks as $chunk) {
                $knowledge[] = $chunk->content;
            }
        }

        // Build prompt
        $prompt = $systemPrompt;
        if (!empty($knowledge)) {
            $prompt .= "\n\nContext:\n" . implode("\n", $knowledge);
        }

        // Call LLM
        $response = $this->llmService->chat([
            'model' => $settings->model ?? 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ...$context,
                ['role' => 'user', 'content' => $message->content],
            ],
        ]);

        // Save response
        $reply = Message::create([
            'conversation_id' => $message->conversation_id,
            'role' => 'assistant',
            'content' => $response['content'],
            'tokens' => $response['usage']['total_tokens'],
        ]);

        // Send to platform
        if ($bot->platform === 'line') {
            $this->lineService->reply($message->reply_token, $reply->content);
        } elseif ($bot->platform === 'telegram') {
            $this->telegramService->send($message->chat_id, $reply->content);
        }

        // Track analytics
        $this->analyticsService->track('message_processed', [
            'bot_id' => $bot->id,
            'tokens' => $response['usage']['total_tokens'],
        ]);
    }
}
```

### After (Extracted Methods)

```php
class BotService
{
    public function processMessage(Message $message): void
    {
        $this->validateMessage($message);

        $bot = $message->conversation->bot;
        $context = $this->buildConversationContext($message);
        $knowledge = $this->searchKnowledgeBase($bot, $message->content);

        $response = $this->generateResponse($bot, $context, $knowledge, $message->content);
        $reply = $this->saveReply($message, $response);

        $this->sendToPlatform($bot, $message, $reply);
        $this->trackAnalytics($bot, $response);
    }

    private function validateMessage(Message $message): void
    {
        if (empty($message->content)) {
            throw new InvalidArgumentException('Empty message');
        }
        if (strlen($message->content) > 4000) {
            throw new InvalidArgumentException('Message too long');
        }
    }

    private function buildConversationContext(Message $message): array
    {
        return $message->conversation
            ->messages()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->reverse()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }

    private function searchKnowledgeBase(Bot $bot, string $query): array
    {
        if (!$bot->knowledgeBases()->exists()) {
            return [];
        }

        $embedding = $this->embeddingService->embed($query);
        $chunks = $this->searchService->search($embedding, $bot->id, 5);

        return $chunks->pluck('content')->toArray();
    }

    private function generateResponse(Bot $bot, array $context, array $knowledge, string $query): array
    {
        $prompt = $this->buildPrompt($bot, $knowledge);

        return $this->llmService->chat([
            'model' => $bot->settings->model ?? 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ...$context,
                ['role' => 'user', 'content' => $query],
            ],
        ]);
    }

    private function buildPrompt(Bot $bot, array $knowledge): string
    {
        $prompt = $bot->settings->system_prompt ?? 'You are a helpful assistant';

        if (!empty($knowledge)) {
            $prompt .= "\n\nContext:\n" . implode("\n", $knowledge);
        }

        return $prompt;
    }

    private function saveReply(Message $message, array $response): Message
    {
        return Message::create([
            'conversation_id' => $message->conversation_id,
            'role' => 'assistant',
            'content' => $response['content'],
            'tokens' => $response['usage']['total_tokens'],
        ]);
    }

    private function sendToPlatform(Bot $bot, Message $message, Message $reply): void
    {
        match ($bot->platform) {
            'line' => $this->lineService->reply($message->reply_token, $reply->content),
            'telegram' => $this->telegramService->send($message->chat_id, $reply->content),
            default => null,
        };
    }

    private function trackAnalytics(Bot $bot, array $response): void
    {
        $this->analyticsService->track('message_processed', [
            'bot_id' => $bot->id,
            'tokens' => $response['usage']['total_tokens'],
        ]);
    }
}
```

## Step-by-Step

1. **Identify extraction points**
   - Look for comments like "// Step 1:", "// Now..."
   - Find logical groupings
   - Spot repeated patterns

2. **Name the extraction**
   - What does this block do?
   - Method name should describe intent

3. **Extract to private method**
   - Move code block
   - Pass required parameters
   - Return needed values

4. **Replace with method call**
   - Call new method
   - Use returned values

5. **Test**
   - Run existing tests
   - Verify behavior unchanged

## Verification

```bash
# Count lines per method (rough)
grep -c "^" method.php

# Check method complexity
php artisan insights  # Laravel Insights
```

## Project-Specific Notes

**BotFacebook Context:**
- Target: < 30 lines per method
- Services: RAGService, OpenRouterService often need splitting
- Pattern: Extract to private methods first, then to services if reused
