---
id: telegram-003-message-format-errors
title: Telegram Message Format Errors
impact: MEDIUM
impactDescription: "Messages fail to send or display incorrectly"
category: telegram
tags: [telegram, message, formatting, html, markdown]
relatedRules: [telegram-002-bot-token-invalid, flow-003-error-handling]
---

## Symptom

- 400 Bad Request when sending messages
- HTML/Markdown not rendering
- Error: "Can't parse entities"
- Message truncated or garbled

## Root Cause

1. Invalid HTML entities
2. Unescaped special characters
3. Nested or unclosed tags
4. Wrong parse_mode
5. Message too long (4096 chars)

## Diagnosis

### Quick Check

```bash
# Test message sending
curl -X POST "https://api.telegram.org/bot{TOKEN}/sendMessage" \
  -d "chat_id={CHAT_ID}" \
  -d "text=<b>Test</b>" \
  -d "parse_mode=HTML"

# Check error response for specific issue
```

### Detailed Analysis

```php
// Log message before sending
Log::debug('Sending Telegram message', [
    'text' => $text,
    'length' => mb_strlen($text),
    'parse_mode' => $parseMode,
]);
```

## Solution

### Fix Steps

1. **Escape HTML Entities**
```php
function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Usage
$safeText = escapeHtml($userInput);
$message = "<b>User said:</b> {$safeText}";
```

2. **Validate Parse Mode**
```php
// HTML mode - use these tags only:
// <b>, <i>, <u>, <s>, <code>, <pre>, <a href="">

// MarkdownV2 - escape these characters:
// _ * [ ] ( ) ~ ` > # + - = | { } . !
```

3. **Handle Long Messages**
```php
function splitMessage(string $text, int $maxLength = 4096): array
{
    if (mb_strlen($text) <= $maxLength) {
        return [$text];
    }

    $parts = [];
    while (mb_strlen($text) > 0) {
        $parts[] = mb_substr($text, 0, $maxLength);
        $text = mb_substr($text, $maxLength);
    }

    return $parts;
}
```

### Code Example

```php
// Good: Safe message formatting
class TelegramMessageFormatter
{
    public function formatMessage(string $text, string $parseMode = 'HTML'): string
    {
        if ($parseMode === 'HTML') {
            return $this->formatHtml($text);
        }

        return $this->formatMarkdownV2($text);
    }

    private function formatHtml(string $text): string
    {
        // Escape user content but preserve our formatting
        $patterns = [
            '/\*\*(.*?)\*\*/' => '<b>$1</b>',      // **bold**
            '/\*(.*?)\*/' => '<i>$1</i>',          // *italic*
            '/`(.*?)`/' => '<code>$1</code>',      // `code`
            '/```(.*?)```/s' => '<pre>$1</pre>',   // ```code block```
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    private function formatMarkdownV2(string $text): string
    {
        // Escape special characters for MarkdownV2
        $special = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];

        foreach ($special as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }

        return $text;
    }

    public function sendLongMessage(Bot $bot, int $chatId, string $text): void
    {
        $parts = $this->splitMessage($text, 4000); // Leave buffer

        foreach ($parts as $i => $part) {
            if ($i > 0) {
                usleep(100000); // 100ms delay between parts
            }

            $this->telegramService->sendMessage($bot, $chatId, $part);
        }
    }

    private function splitMessage(string $text, int $maxLength): array
    {
        if (mb_strlen($text) <= $maxLength) {
            return [$text];
        }

        // Try to split at newlines
        $parts = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($current . "\n" . $line) > $maxLength) {
                if ($current) {
                    $parts[] = trim($current);
                }
                $current = $line;
            } else {
                $current .= ($current ? "\n" : '') . $line;
            }
        }

        if ($current) {
            $parts[] = trim($current);
        }

        return $parts;
    }
}
```

## Prevention

- Always escape user input
- Use consistent parse_mode
- Validate message length before sending
- Test with special characters
- Use library wrappers for formatting

## Debug Commands

```bash
# Test different parse modes
TOKEN="your_token"
CHAT_ID="your_chat_id"

# Plain text
curl -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
  -d "chat_id=$CHAT_ID" \
  -d "text=Plain text message"

# HTML
curl -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
  -d "chat_id=$CHAT_ID" \
  -d "text=<b>Bold</b> and <i>italic</i>" \
  -d "parse_mode=HTML"

# MarkdownV2
curl -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
  -d "chat_id=$CHAT_ID" \
  -d "text=*Bold* and _italic_" \
  -d "parse_mode=MarkdownV2"
```

## Project-Specific Notes

**BotFacebook Context:**
- Formatter: `app/Services/Telegram/MessageFormatter.php`
- Default parse mode: HTML
- AI responses auto-formatted before sending
- Code blocks converted to `<pre>` tags
