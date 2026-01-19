---
id: design-003-constraints
title: Prompt Constraints & Boundaries
impact: HIGH
impactDescription: "Define clear boundaries to prevent unwanted AI behavior"
category: design
tags: [constraints, boundaries, safety, guardrails]
relatedRules: [injection-003-guardrails, design-002-system-prompt]
---

## Why This Matters

Without clear constraints, AI can:
- Share sensitive information
- Make unauthorized promises
- Generate inappropriate content
- Deviate from intended purpose
- Hallucinate confidently

## The Problem

Common constraint failures:
- No explicit boundaries → AI oversteps role
- Vague limitations → Inconsistent enforcement
- Missing escalation paths → Stuck conversations
- No fallback behavior → Poor error handling

## Solution

### Before (No Constraints)

```
You are a helpful assistant for our company.
```

### After (Clear Constraints)

```
## Constraints

### Hard Limits (NEVER do these)
- Never share API keys, tokens, or credentials
- Never execute code or database queries
- Never make promises about pricing or features not documented
- Never share personal information about other users
- Never generate content that is:
  - Sexually explicit
  - Violent or harmful
  - Discriminatory
  - Illegal advice

### Soft Limits (Prefer alternatives)
- If asked about competitor products → Redirect to our features
- If asked for contact info → Provide support email only
- If conversation turns personal → Gently redirect to business topics
- If technical question is complex → Offer to escalate to human support

### Knowledge Boundaries
- ONLY use information from:
  - Provided context (knowledge base)
  - General public knowledge
- If information is not available:
  - Say "I don't have that information"
  - NEVER guess or make up data
  - Offer to connect with human support

### Response Boundaries
- Maximum response length: 500 words unless explicitly requested
- Supported languages: Thai, English
- If asked in unsupported language: Respond in English with apology

### Escalation Triggers
Immediately suggest human support when:
- User expresses frustration 3+ times
- Question involves billing disputes
- Technical issue persists after 2 attempts
- Legal or compliance questions

## Fallback Response
If unsure how to respond:
"ขอโทษครับ ผมไม่แน่ใจเกี่ยวกับเรื่องนี้ ให้ผมส่งต่อไปยังทีมซัพพอร์ตช่วยตอบนะครับ

📧 support@botjao.com
💬 หรือกดปุ่ม 'ติดต่อทีมงาน' ด้านล่าง"
```

### Key Constraint Categories

1. **Hard Limits**: Absolute restrictions
2. **Soft Limits**: Preferred behaviors
3. **Knowledge Boundaries**: Information scope
4. **Response Boundaries**: Format limits
5. **Escalation Triggers**: When to involve humans
6. **Fallback Behavior**: Default response

## Implementation

```php
// ConstraintService.php
class ConstraintService
{
    private array $hardLimits = [
        '/api[_-]?key/i',
        '/token[s]?\s*[:=]/i',
        '/password[s]?\s*[:=]/i',
        '/secret[s]?\s*[:=]/i',
    ];

    private array $softLimitTriggers = [
        'competitor' => ['alternative', 'vs', 'compare', 'better than'],
        'personal' => ['your opinion', 'what do you think', 'personally'],
        'pricing' => ['discount', 'negotiate', 'cheaper'],
    ];

    public function checkHardLimits(string $response): bool
    {
        foreach ($this->hardLimits as $pattern) {
            if (preg_match($pattern, $response)) {
                return false; // Hard limit violated
            }
        }
        return true;
    }

    public function detectSoftLimitTrigger(string $input): ?string
    {
        foreach ($this->softLimitTriggers as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($input, $keyword) !== false) {
                    return $type;
                }
            }
        }
        return null;
    }

    public function shouldEscalate(Conversation $conversation): bool
    {
        // Check frustration signals
        $recentMessages = $conversation->messages()
            ->where('role', 'user')
            ->latest()
            ->limit(5)
            ->get();

        $frustrationCount = 0;
        foreach ($recentMessages as $message) {
            if ($this->detectFrustration($message->content)) {
                $frustrationCount++;
            }
        }

        return $frustrationCount >= 3;
    }

    private function detectFrustration(string $content): bool
    {
        $signals = [
            '/ไม่เข้าใจ/u',
            '/still not working/i',
            '/already tried/i',
            '/frustrated/i',
            '/annoyed/i',
        ];

        foreach ($signals as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        return false;
    }
}
```

### Post-processing Filter

```php
// ResponseFilterService.php
public function filter(string $response): string
{
    // Remove any leaked credentials
    $response = preg_replace(
        '/sk-[a-zA-Z0-9]{32,}/',
        '[REDACTED]',
        $response
    );

    // Enforce length limit
    if (strlen($response) > 2000) {
        $response = mb_substr($response, 0, 1950) . "\n\n[ข้อความถูกตัดเนื่องจากยาวเกินไป]";
    }

    return $response;
}
```

## Testing

```php
public function test_hard_limits_block_credential_exposure(): void
{
    $response = "Your API key is sk-abc123...";

    $result = $this->constraintService->checkHardLimits($response);

    $this->assertFalse($result);
}

public function test_escalation_triggered_after_frustration(): void
{
    // Create conversation with frustrated messages
    $conversation = Conversation::factory()->create();
    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'ไม่เข้าใจเลย ทำยังไงก็ไม่ได้',
    ]);

    $result = $this->constraintService->shouldEscalate($conversation);

    $this->assertTrue($result);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- Hard limits enforced in ResponseFilterService
- Soft limits handled via prompt engineering
- Escalation tracked in conversation.escalated_at
- Support handoff via websocket event
