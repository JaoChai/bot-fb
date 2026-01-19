---
id: laravel-001-extract-method
title: Extract Method Refactoring
impact: MEDIUM
impactDescription: "Break down long methods into smaller, focused functions"
category: laravel
tags: [extract, method, readability, maintainability]
relatedRules: [laravel-002-extract-service, smell-001-long-method]
---

## Code Smell

- Method longer than 30 lines
- Multiple levels of abstraction
- Comments explaining "sections" of code
- Same code block with minor variations
- Hard to understand at a glance

## Root Cause

1. Feature creep over time
2. Copy-paste development
3. No initial structure planning
4. Fear of creating new methods
5. Lack of refactoring time

## When to Apply

**Apply when:**
- Method > 30 lines
- Can name what a block does
- Block is reusable
- Block has clear input/output

**Don't apply when:**
- Method is already readable
- Extraction would add confusion
- Code will be deleted soon

## Solution

### Before

```php
class BotService
{
    public function processMessage(Message $message): Response
    {
        // Validate message
        if (empty($message->content)) {
            throw new InvalidMessageException('Empty content');
        }
        if (strlen($message->content) > 10000) {
            throw new InvalidMessageException('Content too long');
        }
        if ($message->bot->status !== 'active') {
            throw new BotInactiveException();
        }

        // Get context
        $conversation = Conversation::where('id', $message->conversation_id)
            ->with(['messages' => fn($q) => $q->latest()->take(10)])
            ->first();
        $recentMessages = $conversation->messages->reverse()->values();
        $context = $recentMessages->map(fn($m) => [
            'role' => $m->sender_type,
            'content' => $m->content,
        ])->toArray();

        // Generate response
        $prompt = $this->buildPrompt($context);
        $response = $this->openRouter->chat($prompt);
        $content = $response['choices'][0]['message']['content'];

        // Save and return
        $reply = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $content,
            'sender_type' => 'assistant',
        ]);

        return new Response($reply);
    }
}
```

### After

```php
class BotService
{
    public function processMessage(Message $message): Response
    {
        $this->validateMessage($message);
        $context = $this->getConversationContext($message->conversation_id);
        $content = $this->generateResponse($context);
        $reply = $this->saveReply($message->conversation_id, $content);

        return new Response($reply);
    }

    private function validateMessage(Message $message): void
    {
        if (empty($message->content)) {
            throw new InvalidMessageException('Empty content');
        }
        if (strlen($message->content) > 10000) {
            throw new InvalidMessageException('Content too long');
        }
        if ($message->bot->status !== 'active') {
            throw new BotInactiveException();
        }
    }

    private function getConversationContext(int $conversationId): array
    {
        $conversation = Conversation::where('id', $conversationId)
            ->with(['messages' => fn($q) => $q->latest()->take(10)])
            ->first();

        return $conversation->messages->reverse()->values()
            ->map(fn($m) => [
                'role' => $m->sender_type,
                'content' => $m->content,
            ])->toArray();
    }

    private function generateResponse(array $context): string
    {
        $prompt = $this->buildPrompt($context);
        $response = $this->openRouter->chat($prompt);
        return $response['choices'][0]['message']['content'];
    }

    private function saveReply(int $conversationId, string $content): Message
    {
        return Message::create([
            'conversation_id' => $conversationId,
            'content' => $content,
            'sender_type' => 'assistant',
        ]);
    }
}
```

### Step-by-Step

1. **Identify extraction candidates**
   - Look for comments like "// Step 1:", "// Validate:", etc.
   - Find code blocks that do one thing
   - Note blocks that could be named

2. **Extract one method at a time**
   ```php
   // Select code block
   // Cut and paste to new private method
   // Replace original with method call
   // Run tests
   ```

3. **Name methods clearly**
   - Use verb-noun: `validateMessage`, `getContext`
   - Be specific: `getConversationContext` not `getData`
   - Match domain language

4. **Verify after each extraction**
   ```bash
   php artisan test --filter BotServiceTest
   ```

## Verification

```bash
# After refactoring
php artisan test

# Check method is called
grep -r "validateMessage" app/

# Verify no behavior change
# Compare API responses before/after
```

## Anti-Patterns

- **Over-extraction**: 2-line methods that add noise
- **Wrong abstraction**: Grouping unrelated code
- **Breaking encapsulation**: Extracting to public method
- **Premature extraction**: Before understanding code

## Project-Specific Notes

**BotFacebook Context:**
- Common long methods: ProcessMessage, HandleWebhook
- Target: Methods < 30 lines
- Services: RAGService, BotService, MessageService
- Test coverage needed before refactoring
