---
id: telegram-004-inline-keyboard
title: Telegram Inline Keyboard Issues
impact: MEDIUM
impactDescription: "Interactive buttons don't work or display"
category: telegram
tags: [telegram, inline-keyboard, buttons, callback]
relatedRules: [telegram-003-message-format-errors, flow-002-idempotency]
---

## Symptom

- Buttons not appearing
- Button clicks not triggering
- "Bad Request: BUTTON_DATA_INVALID"
- Callback query not answered (clock spinning)

## Root Cause

1. Invalid keyboard markup structure
2. Callback data too long (>64 bytes)
3. Callback query not answered within 10 seconds
4. Invalid URL in url button
5. Missing or wrong button type

## Diagnosis

### Quick Check

```bash
# Test inline keyboard
curl -X POST "https://api.telegram.org/bot{TOKEN}/sendMessage" \
  -H "Content-Type: application/json" \
  -d '{
    "chat_id": "{CHAT_ID}",
    "text": "Choose an option:",
    "reply_markup": {
      "inline_keyboard": [[
        {"text": "Option 1", "callback_data": "opt1"},
        {"text": "Option 2", "callback_data": "opt2"}
      ]]
    }
  }'
```

### Detailed Analysis

```php
// Log keyboard structure
Log::debug('Sending keyboard', [
    'keyboard' => json_encode($keyboard, JSON_PRETTY_PRINT),
    'callback_data_lengths' => array_map(
        fn($row) => array_map(
            fn($btn) => strlen($btn['callback_data'] ?? ''),
            $row
        ),
        $keyboard
    ),
]);
```

## Solution

### Fix Steps

1. **Answer Callback Queries**
```php
// Must answer within 10 seconds
public function handleCallbackQuery(Request $request): Response
{
    $callbackQuery = $request->input('callback_query');
    $callbackId = $callbackQuery['id'];

    // Answer immediately to stop the clock
    $this->answerCallbackQuery($callbackId);

    // Then process the callback
    dispatch(new ProcessCallback($callbackQuery));

    return response('OK');
}

private function answerCallbackQuery(string $callbackId, string $text = null): void
{
    Http::post("https://api.telegram.org/bot{$this->token}/answerCallbackQuery", [
        'callback_query_id' => $callbackId,
        'text' => $text,
    ]);
}
```

2. **Validate Callback Data Length**
```php
// Max 64 bytes for callback_data
function createCallbackData(string $action, array $params): string
{
    $data = json_encode(['a' => $action, 'p' => $params]);

    if (strlen($data) > 64) {
        // Store full data and use reference
        $ref = CallbackData::create(['data' => $params]);
        $data = json_encode(['a' => $action, 'r' => $ref->id]);
    }

    return $data;
}
```

3. **Build Valid Keyboard Structure**
```php
// Keyboard must be array of arrays (rows of buttons)
$keyboard = [
    // Row 1
    [
        ['text' => 'Button 1', 'callback_data' => 'btn1'],
        ['text' => 'Button 2', 'callback_data' => 'btn2'],
    ],
    // Row 2
    [
        ['text' => 'Button 3', 'callback_data' => 'btn3'],
    ],
];
```

### Code Example

```php
// Good: Complete inline keyboard handling
class TelegramKeyboardService
{
    public function buildInlineKeyboard(array $options): array
    {
        $keyboard = [];
        $row = [];

        foreach ($options as $i => $option) {
            $button = $this->createButton($option);
            $row[] = $button;

            // Max 3 buttons per row, or start new row
            if (count($row) >= 3 || ($option['new_row'] ?? false)) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        return ['inline_keyboard' => $keyboard];
    }

    private function createButton(array $option): array
    {
        $button = ['text' => $option['text']];

        if (isset($option['url'])) {
            // URL button
            $button['url'] = $option['url'];
        } elseif (isset($option['callback_data'])) {
            // Callback button - validate length
            $data = $option['callback_data'];
            if (strlen($data) > 64) {
                $data = $this->storeCallbackData($data);
            }
            $button['callback_data'] = $data;
        } elseif (isset($option['web_app'])) {
            // Web app button
            $button['web_app'] = ['url' => $option['web_app']];
        }

        return $button;
    }

    private function storeCallbackData(string $data): string
    {
        // Store in cache with short key
        $key = Str::random(8);
        Cache::put("tg_cb:{$key}", $data, now()->addHours(24));
        return "ref:{$key}";
    }

    public function handleCallback(array $callbackQuery): void
    {
        $bot = Bot::findByTelegramChatId($callbackQuery['message']['chat']['id']);

        // Answer immediately
        $this->telegramService->answerCallbackQuery(
            $bot,
            $callbackQuery['id'],
            'Processing...'
        );

        // Parse callback data
        $data = $callbackQuery['data'];

        if (str_starts_with($data, 'ref:')) {
            // Retrieve stored data
            $key = substr($data, 4);
            $data = Cache::get("tg_cb:{$key}", $data);
        }

        // Process callback
        $decoded = json_decode($data, true) ?? ['action' => $data];

        match ($decoded['action'] ?? $data) {
            'help' => $this->showHelp($bot, $callbackQuery),
            'settings' => $this->showSettings($bot, $callbackQuery),
            default => Log::warning('Unknown callback', ['data' => $data]),
        };
    }
}
```

## Prevention

- Always answer callback queries
- Keep callback_data under 64 bytes
- Use references for complex data
- Validate keyboard structure
- Test all button types

## Debug Commands

```bash
# Test different button types
TOKEN="your_token"
CHAT_ID="your_chat_id"

# Callback button
curl -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
  -H "Content-Type: application/json" \
  -d '{
    "chat_id": "'$CHAT_ID'",
    "text": "Test buttons",
    "reply_markup": {
      "inline_keyboard": [[
        {"text": "Callback", "callback_data": "test"},
        {"text": "URL", "url": "https://example.com"}
      ]]
    }
  }'

# Check callback data length
echo -n "your_callback_data" | wc -c
```

## Project-Specific Notes

**BotFacebook Context:**
- Keyboard builder: `app/Services/Telegram/KeyboardBuilder.php`
- Callback handler: `TelegramWebhookController::handleCallbackQuery()`
- Callback data stored in `callback_data` cache when >64 bytes
- Common callbacks: `help`, `settings`, `cancel`, `confirm`
