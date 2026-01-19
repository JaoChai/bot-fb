---
id: smell-003-complex-conditional
title: Complex Conditional Detection
impact: MEDIUM
impactDescription: "Simplify complex conditional logic"
category: smell
tags: [code-smell, conditional, complexity, readability]
relatedRules: [laravel-001-extract-method, pattern-001-strategy-pattern]
---

## Code Smell

- Nested if/else > 3 levels deep
- Long if conditions
- Same condition checked multiple places
- Hard to understand logic flow
- Missing edge cases

## Root Cause

1. Requirements added incrementally
2. Edge cases patched in
3. No early returns used
4. Fear of missing cases
5. Copy-paste conditionals

## Detection

### Metrics

| Indicator | Risk |
|-----------|------|
| if/else > 3 levels | High |
| Condition > 80 chars | Medium |
| && or \|\| > 3 | Medium |
| Same condition repeated | High |

### Quick Scan

```php
// Look for deep nesting
if ($condition1) {
    if ($condition2) {
        if ($condition3) {
            // Too deep!
        }
    }
}

// Look for long conditions
if ($user->isActive() && $user->hasPermission('edit') && $user->team_id === $bot->team_id && !$user->isBanned()) {
    // Too long!
}
```

## Solution

### Pattern 1: Guard Clauses (Early Returns)

```php
// Before - Nested conditionals
public function processMessage(Message $message): Response
{
    if ($message->conversation) {
        if ($message->conversation->bot) {
            if ($message->conversation->bot->is_active) {
                if ($message->content) {
                    // Process message
                    return $this->generate($message);
                } else {
                    return $this->error('Empty message');
                }
            } else {
                return $this->error('Bot is inactive');
            }
        } else {
            return $this->error('No bot found');
        }
    } else {
        return $this->error('No conversation');
    }
}

// After - Guard clauses
public function processMessage(Message $message): Response
{
    if (!$message->conversation) {
        return $this->error('No conversation');
    }

    if (!$message->conversation->bot) {
        return $this->error('No bot found');
    }

    if (!$message->conversation->bot->is_active) {
        return $this->error('Bot is inactive');
    }

    if (!$message->content) {
        return $this->error('Empty message');
    }

    return $this->generate($message);
}
```

### Pattern 2: Extract Condition to Method

```php
// Before - Long condition
if ($user->isActive() && $user->hasPermission('edit') && $user->team_id === $bot->team_id && !$user->isBanned()) {
    // Do something
}

// After - Extracted to method
if ($this->canUserEditBot($user, $bot)) {
    // Do something
}

private function canUserEditBot(User $user, Bot $bot): bool
{
    return $user->isActive()
        && $user->hasPermission('edit')
        && $user->team_id === $bot->team_id
        && !$user->isBanned();
}

// Even better - In Policy
// app/Policies/BotPolicy.php
public function update(User $user, Bot $bot): bool
{
    return $user->isActive()
        && $user->hasPermission('edit')
        && $user->team_id === $bot->team_id
        && !$user->isBanned();
}

// Usage
$this->authorize('update', $bot);
```

### Pattern 3: Replace Conditionals with Polymorphism

```php
// Before - Type checking
public function sendMessage(Bot $bot, string $content): void
{
    if ($bot->platform === 'line') {
        $this->lineService->push($bot->channel_id, $content);
    } elseif ($bot->platform === 'telegram') {
        $this->telegramService->send($bot->chat_id, $content);
    } elseif ($bot->platform === 'facebook') {
        $this->facebookService->message($bot->page_id, $content);
    } else {
        throw new UnsupportedPlatformException($bot->platform);
    }
}

// After - Strategy pattern
interface PlatformMessenger
{
    public function send(Bot $bot, string $content): void;
}

class LineMessenger implements PlatformMessenger
{
    public function send(Bot $bot, string $content): void
    {
        $this->lineService->push($bot->channel_id, $content);
    }
}

class TelegramMessenger implements PlatformMessenger
{
    public function send(Bot $bot, string $content): void
    {
        $this->telegramService->send($bot->chat_id, $content);
    }
}

// Usage with service container
public function sendMessage(Bot $bot, string $content): void
{
    $messenger = $this->messengerFactory->make($bot->platform);
    $messenger->send($bot, $content);
}
```

### Pattern 4: Use Match Expression (PHP 8+)

```php
// Before - Switch statement
switch ($status) {
    case 'pending':
        return 'Waiting for review';
    case 'approved':
        return 'Approved and active';
    case 'rejected':
        return 'Rejected';
    default:
        return 'Unknown status';
}

// After - Match expression
return match ($status) {
    'pending' => 'Waiting for review',
    'approved' => 'Approved and active',
    'rejected' => 'Rejected',
    default => 'Unknown status',
};
```

### Pattern 5: Null Object Pattern

```php
// Before - Null checks everywhere
if ($bot->settings !== null) {
    $model = $bot->settings->model;
} else {
    $model = 'gpt-4';
}

if ($bot->settings !== null && $bot->settings->system_prompt !== null) {
    $prompt = $bot->settings->system_prompt;
} else {
    $prompt = 'You are a helpful assistant';
}

// After - Null object in model
// app/Models/BotSettings.php
class BotSettings extends Model
{
    public static function default(): self
    {
        return new self([
            'model' => 'gpt-4',
            'system_prompt' => 'You are a helpful assistant',
            'temperature' => 0.7,
        ]);
    }
}

// In Bot model
public function getSettingsAttribute(): BotSettings
{
    return $this->attributes['settings'] ?? BotSettings::default();
}

// Usage - No null checks needed
$model = $bot->settings->model;
$prompt = $bot->settings->system_prompt;
```

## Step-by-Step

1. **Identify complexity**
   - Count nesting levels
   - Measure condition length
   - Find repeated conditions

2. **Choose refactoring**
   - Deep nesting → Guard clauses
   - Long condition → Extract method
   - Type switching → Polymorphism

3. **Apply refactoring**
   - One pattern at a time
   - Keep tests passing

4. **Verify readability**
   - Can you explain in one sentence?
   - Is intent clear?

## Verification

```bash
# Check nesting depth (rough estimate)
grep -n "if.*if.*if" app/Services/*.php

# Check long conditions
grep -E "if \(.{80,}\)" app/
```

## Project-Specific Notes

**BotFacebook Context:**
- Common complex conditionals: Platform switching, model selection
- Use Laravel Policies for authorization logic
- Use match() for PHP 8+ type switching
- Pattern: Guard clauses at method start
