---
id: line-003-flex-message-errors
title: LINE Flex Message Format Errors
impact: HIGH
impactDescription: "Rich messages fail to send, users see no response"
category: line
tags: [line, flex-message, json, formatting]
relatedRules: [line-002-reply-token-expiry, flow-003-error-handling]
---

## Symptom

- Text messages work fine
- Flex messages fail with 400 Bad Request
- Error: "The request body has invalid json"
- Partial flex message rendering

## Root Cause

Flex Message JSON structure is strict:
1. Invalid JSON syntax
2. Missing required fields
3. Wrong field types (string vs number)
4. Unsupported components
5. Size exceeds 50KB limit

## Diagnosis

### Quick Check

```bash
# Check for flex message errors
railway logs --filter "flex" | grep -i error

# Validate JSON syntax
cat flex_message.json | jq .
```

### Detailed Analysis

```php
// Log flex message before sending
Log::debug('Sending flex message', [
    'json' => json_encode($flexMessage, JSON_PRETTY_PRINT),
    'size' => strlen(json_encode($flexMessage)),
]);
```

## Solution

### Fix Steps

1. **Validate JSON Structure**
```php
// Validate before sending
$validator = new FlexMessageValidator();
$errors = $validator->validate($flexMessage);
if ($errors) {
    Log::error('Flex message validation failed', ['errors' => $errors]);
}
```

2. **Use Builder Classes**
```php
// Use SDK builders instead of raw JSON
use LINE\LINEBot\MessageBuilder\Flex\ContainerBuilder;
use LINE\LINEBot\MessageBuilder\Flex\ComponentBuilder;

$bubble = ContainerBuilder::builder()
    ->setType('bubble')
    ->setBody(
        ComponentBuilder::builder()
            ->setType('box')
            ->setLayout('vertical')
            ->setContents([
                ComponentBuilder::builder()
                    ->setType('text')
                    ->setText('Hello World')
                    ->build()
            ])
            ->build()
    )
    ->build();
```

3. **Handle Size Limits**
```php
// Check size before sending
$json = json_encode($flexMessage);
if (strlen($json) > 50000) {
    // Split into multiple messages or simplify
    $flexMessage = $this->simplifyFlexMessage($flexMessage);
}
```

### Code Example

```php
// Good: Safe flex message building
class FlexMessageBuilder
{
    public function buildProductCard(Product $product): array
    {
        return [
            'type' => 'bubble',
            'hero' => [
                'type' => 'image',
                'url' => $product->image_url,
                'size' => 'full',
                'aspectRatio' => '20:13',
                'aspectMode' => 'cover',
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $product->name,
                        'weight' => 'bold',
                        'size' => 'xl',
                    ],
                    [
                        'type' => 'text',
                        'text' => '฿' . number_format($product->price),
                        'size' => 'lg',
                        'color' => '#00B900',
                    ],
                ],
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'action' => [
                            'type' => 'postback',
                            'label' => 'Buy Now',
                            'data' => 'action=buy&product_id=' . $product->id,
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

## Prevention

- Use LINE Flex Message Simulator for testing
- Create reusable flex message templates
- Add JSON schema validation
- Log flex messages in development
- Test all edge cases (long text, missing images)

## Debug Commands

```bash
# Validate flex message JSON
curl -X POST https://api.line.me/v2/bot/message/validate/reply \
  -H "Authorization: Bearer {ACCESS_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "messages": [{
      "type": "flex",
      "altText": "Product Card",
      "contents": {...}
    }]
  }'

# Use LINE Flex Message Simulator
# https://developers.line.biz/flex-simulator/
```

## Project-Specific Notes

**BotFacebook Context:**
- Flex templates in `app/Services/Line/FlexTemplates/`
- Validation in `app/Services/Line/FlexValidator.php`
- Common templates: ProductCard, OrderSummary, ConversationHistory
- Alt text required for accessibility
