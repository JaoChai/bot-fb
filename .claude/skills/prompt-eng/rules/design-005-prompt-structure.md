---
id: design-005-prompt-structure
title: Prompt Structure & Organization
impact: HIGH
impactDescription: "Organize prompts for clarity, maintainability, and effectiveness"
category: design
tags: [structure, organization, template, maintainability]
relatedRules: [design-002-system-prompt, design-001-context-window]
---

## Why This Matters

Well-structured prompts are:
- Easier to maintain
- More consistent
- Easier to debug
- More effective

Poorly structured prompts lead to:
- Confusion about what's important
- Inconsistent behavior
- Hard to update
- Debugging nightmares

## The Problem

Common structural issues:
- Instructions scattered throughout
- No clear hierarchy
- Mixed concerns (role + format + constraints)
- Important details buried in text
- No clear sections

## Solution

### Before (Unstructured Prompt)

```
You're an assistant for BotFacebook. Be helpful and friendly.
Don't share secrets. Answer in Thai when they ask in Thai.
You know about chatbots for LINE and Telegram. Keep responses
short. If you don't know, say you don't know. Use bullets for
lists. You can help with setup and troubleshooting.
```

### After (Structured Prompt)

```markdown
# BotFacebook Support Assistant

## Role & Identity
You are Jao, a friendly technical support assistant for BotFacebook.
Your purpose is to help users create and manage chatbots.

## Knowledge Base
**You have access to:**
- Product documentation (provided in context)
- Bot setup guides for LINE and Telegram
- Troubleshooting procedures

**You do NOT have access to:**
- User account details (unless in context)
- Internal company data
- Competitor information

## Behavior Guidelines

### Language
- Respond in the same language as the user's question
- Thai questions → Thai responses
- English questions → English responses

### Tone
- Friendly and approachable
- Patient with beginners
- Technical but not overwhelming

### Response Format
- Keep responses under 200 words
- Use bullet points for lists
- Bold **key terms**
- Include code blocks for commands

## Constraints
1. **NEVER** share API keys or credentials
2. **NEVER** make promises about unreleased features
3. **ALWAYS** say "I don't know" rather than guessing
4. **ALWAYS** offer human support for complex issues

## Examples

### Good Response
User: "วิธี setup bot ยังไง"
Assistant: "สวัสดีครับ! 👋 การ setup bot มีขั้นตอนง่ายๆ ดังนี้:

1. ไปที่หน้า **Bots** แล้วกด 'สร้าง Bot ใหม่'
2. เลือก platform (LINE หรือ Telegram)
3. ใส่ credentials จาก platform
4. ตั้งค่า webhook

ต้องการความช่วยเหลือเพิ่มเติมไหมครับ?"

### Handling Unknown
User: "ราคา enterprise เท่าไหร่"
Assistant: "ขอโทษครับ ผมไม่มีข้อมูลราคา enterprise โดยตรง แนะนำให้ติดต่อทีมขายที่ sales@botjao.com เพื่อรับใบเสนอราคาครับ 📧"

---
[Context will be provided below]
```

### Recommended Structure

```
┌─────────────────────────────────────┐
│ 1. IDENTITY (Who)                   │
│    - Name, role, purpose            │
├─────────────────────────────────────┤
│ 2. KNOWLEDGE (What)                 │
│    - What you know                  │
│    - What you don't know            │
├─────────────────────────────────────┤
│ 3. BEHAVIOR (How)                   │
│    - Language rules                 │
│    - Tone guidelines                │
│    - Response format                │
├─────────────────────────────────────┤
│ 4. CONSTRAINTS (Limits)             │
│    - Hard restrictions              │
│    - Soft preferences               │
├─────────────────────────────────────┤
│ 5. EXAMPLES (Show)                  │
│    - Good responses                 │
│    - Edge case handling             │
├─────────────────────────────────────┤
│ 6. CONTEXT SECTION                  │
│    [Dynamic content goes here]      │
└─────────────────────────────────────┘
```

## Implementation

### Template System

```php
// PromptBuilder.php
class PromptBuilder
{
    private array $sections = [];

    public function identity(string $name, string $role, string $purpose): self
    {
        $this->sections['identity'] = <<<EOT
## Role & Identity
You are {$name}, a {$role}.
Your purpose is to {$purpose}.
EOT;
        return $this;
    }

    public function knowledge(array $has, array $lacks): self
    {
        $hasStr = implode("\n- ", $has);
        $lacksStr = implode("\n- ", $lacks);

        $this->sections['knowledge'] = <<<EOT
## Knowledge Base
**You have access to:**
- {$hasStr}

**You do NOT have access to:**
- {$lacksStr}
EOT;
        return $this;
    }

    public function behavior(array $guidelines): self
    {
        $content = "## Behavior Guidelines\n\n";
        foreach ($guidelines as $category => $rules) {
            $content .= "### {$category}\n";
            foreach ($rules as $rule) {
                $content .= "- {$rule}\n";
            }
            $content .= "\n";
        }
        $this->sections['behavior'] = $content;
        return $this;
    }

    public function constraints(array $hard, array $soft = []): self
    {
        $content = "## Constraints\n\n";

        foreach ($hard as $i => $constraint) {
            $num = $i + 1;
            $content .= "{$num}. **NEVER** {$constraint}\n";
        }

        if (!empty($soft)) {
            $content .= "\n**Preferences:**\n";
            foreach ($soft as $pref) {
                $content .= "- {$pref}\n";
            }
        }

        $this->sections['constraints'] = $content;
        return $this;
    }

    public function examples(array $examples): self
    {
        $content = "## Examples\n\n";
        foreach ($examples as $example) {
            $content .= "### {$example['title']}\n";
            $content .= "User: \"{$example['user']}\"\n";
            $content .= "Assistant: \"{$example['assistant']}\"\n\n";
        }
        $this->sections['examples'] = $content;
        return $this;
    }

    public function build(): string
    {
        $order = ['identity', 'knowledge', 'behavior', 'constraints', 'examples'];

        $prompt = "# System Instructions\n\n";

        foreach ($order as $section) {
            if (isset($this->sections[$section])) {
                $prompt .= $this->sections[$section] . "\n\n";
            }
        }

        $prompt .= "---\n[Context will be provided below]\n";

        return $prompt;
    }
}
```

### Usage

```php
$prompt = (new PromptBuilder())
    ->identity('Jao', 'customer support assistant', 'help users with chatbot setup')
    ->knowledge(
        has: ['Product documentation', 'Setup guides', 'FAQ'],
        lacks: ['User account data', 'Internal company info']
    )
    ->behavior([
        'Language' => ['Match user language', 'Use casual Thai'],
        'Tone' => ['Friendly', 'Patient', 'Technical but clear'],
    ])
    ->constraints(
        hard: ['share credentials', 'make promises'],
        soft: ['Keep responses under 200 words']
    )
    ->examples([
        ['title' => 'Good Response', 'user' => 'How do I...', 'assistant' => '...'],
    ])
    ->build();
```

## Testing

```php
public function test_prompt_structure_has_required_sections(): void
{
    $prompt = $this->builder->build();

    $this->assertStringContainsString('## Role & Identity', $prompt);
    $this->assertStringContainsString('## Knowledge Base', $prompt);
    $this->assertStringContainsString('## Behavior Guidelines', $prompt);
    $this->assertStringContainsString('## Constraints', $prompt);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- Base templates in resources/prompts/
- User customization via bot_settings.system_prompt
- PromptBuilder for programmatic generation
- Max prompt size: 8000 tokens
