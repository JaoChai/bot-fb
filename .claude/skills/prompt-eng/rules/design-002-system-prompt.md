---
id: design-002-system-prompt
title: System Prompt Design
impact: HIGH
impactDescription: "Create effective system prompts that define AI behavior and personality"
category: design
tags: [system-prompt, role, personality, instructions]
relatedRules: [design-005-prompt-structure, design-003-constraints]
---

## Why This Matters

The system prompt is the AI's foundation. It defines:
- Personality and tone
- Knowledge boundaries
- Response format
- Behavior constraints

A poor system prompt leads to inconsistent, off-brand, or unhelpful responses.

## The Problem

Common system prompt mistakes:
- Too vague ("Be helpful")
- Too long (overloaded instructions)
- Missing constraints (inappropriate responses)
- No persona (generic responses)
- No format guidance (inconsistent output)

## Solution

### Before (Weak System Prompt)

```
You are a helpful assistant. Answer questions about our products.
```

### After (Effective System Prompt)

```
## Role
You are Jao, a friendly customer support assistant for BotFacebook.
You help users create and manage chatbots for LINE and Telegram.

## Personality
- Friendly and approachable (use casual Thai)
- Patient with beginners
- Technical but not overwhelming
- Use emojis sparingly 🎉

## Knowledge
You have access to:
- Product documentation (provided in context)
- Bot configuration guides
- Pricing information

You do NOT know:
- User's specific bot data unless provided
- Internal company operations
- Competitor products

## Response Guidelines
1. Answer in the same language as the user's question
2. Keep responses concise (under 200 words unless explaining complex topics)
3. Use bullet points for lists
4. Include relevant documentation links when helpful

## Constraints
- Never share API keys or sensitive data
- Don't make promises about unreleased features
- If unsure, say "I don't have that information" rather than guessing
- Redirect billing questions to support@botjao.com

## Examples
User: "วิธีสร้าง bot LINE ยังไง"
You: "สร้าง LINE bot ง่ายๆ เลยครับ 👋

1. ไปที่หน้า Bots แล้วกด "สร้าง Bot ใหม่"
2. เลือก LINE เป็น platform
3. ใส่ Channel Access Token จาก LINE Developers Console
4. ตั้งค่า Webhook URL

ต้องการดูคู่มือเพิ่มเติมไหมครับ?"
```

### Key Components

1. **Role Definition**
   - Clear identity (name, purpose)
   - Specific domain

2. **Personality**
   - Tone (formal/casual)
   - Communication style
   - Cultural considerations

3. **Knowledge Boundaries**
   - What the AI knows
   - What it doesn't know
   - Source of information

4. **Response Guidelines**
   - Format preferences
   - Length constraints
   - Language handling

5. **Constraints**
   - Hard limits
   - Escalation paths
   - Safety guardrails

6. **Examples**
   - Good response patterns
   - Edge case handling

## Implementation

```php
// BotSettingsService.php
public function buildSystemPrompt(Bot $bot): string
{
    $template = $this->loadTemplate($bot->template ?? 'default');

    $variables = [
        'bot_name' => $bot->name,
        'personality' => $bot->settings->personality ?? 'friendly',
        'language' => $bot->settings->language ?? 'th',
        'business_name' => $bot->user->business_name,
        'knowledge_context' => $this->getKnowledgeSummary($bot),
    ];

    return $this->render($template, $variables);
}

// Templates stored in database or files
// resources/prompts/customer-support.md
// resources/prompts/sales-assistant.md
// resources/prompts/faq-bot.md
```

### Template System

```php
// Bot Settings allow user customization
class BotSettings extends Model
{
    protected $casts = [
        'system_prompt' => 'string',
        'personality_traits' => 'array',
        'response_length' => 'string', // short, medium, long
        'language_style' => 'string', // formal, casual
    ];
}

// Merge user settings with base template
public function getEffectiveSystemPrompt(Bot $bot): string
{
    $base = $bot->settings->system_prompt ?? $this->getDefaultPrompt();

    // Inject dynamic context
    $knowledge = $bot->knowledgeBases()
        ->get()
        ->pluck('description')
        ->join("\n- ");

    return str_replace(
        ['{{KNOWLEDGE_SOURCES}}', '{{BOT_NAME}}'],
        [$knowledge, $bot->name],
        $base
    );
}
```

## Testing

```php
public function test_system_prompt_includes_required_sections(): void
{
    $prompt = $this->service->buildSystemPrompt($this->bot);

    $this->assertStringContainsString('Role', $prompt);
    $this->assertStringContainsString('Constraints', $prompt);
    $this->assertStringContainsString($this->bot->name, $prompt);
}

public function test_system_prompt_respects_language_setting(): void
{
    $this->bot->settings->update(['language' => 'en']);

    $prompt = $this->service->buildSystemPrompt($this->bot);

    $this->assertStringContainsString('English', $prompt);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- System prompts stored in bot_settings.system_prompt
- Default templates in resources/prompts/
- Support Thai and English
- Max length: 4000 characters (user-facing limit)
