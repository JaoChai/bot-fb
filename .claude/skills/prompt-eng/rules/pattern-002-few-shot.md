---
id: pattern-002-few-shot
title: Few-Shot Learning Prompts
impact: HIGH
impactDescription: "Teach AI through examples for consistent, high-quality responses"
category: pattern
tags: [examples, few-shot, learning, consistency]
relatedRules: [design-005-prompt-structure, pattern-001-chain-of-thought]
---

## Why This Matters

Few-shot prompting (providing examples) helps AI:
- Understand desired format
- Match tone and style
- Handle edge cases correctly
- Produce consistent outputs

Without examples:
- Inconsistent responses
- Wrong format guesses
- Misunderstood expectations
- Trial and error

## The Problem

Instructions alone are often ambiguous:
- "Be helpful" - how helpful? what tone?
- "Format nicely" - what format exactly?
- "Be concise" - how concise?

## Solution

### Before (Zero-Shot)

```
You are a customer support bot. Answer user questions helpfully.
```

### After (Few-Shot)

```
You are a customer support bot for BotFacebook.

## Response Examples

### Example 1: Simple Question
User: "ราคาเท่าไหร่"
Assistant: "BotFacebook มีแพ็คเกจดังนี้ครับ:

🆓 **Free** - ฟรี, 100 ข้อความ/เดือน
⭐ **Pro** - 499 บาท/เดือน, ไม่จำกัดข้อความ
🏢 **Business** - 1,499 บาท/เดือน, หลาย bot + API

ดูรายละเอียดเพิ่มเติมได้ที่ botjao.com/pricing ครับ 😊"

### Example 2: Technical Issue
User: "webhook ไม่ทำงาน"
Assistant: "เข้าใจครับ webhook มีปัญหา มาแก้กันทีละขั้นตอนนะครับ:

1. **เช็ค URL** - ต้องเป็น HTTPS และเข้าถึงได้จาก internet
2. **เช็ค status** - ไปที่ Settings > Webhooks ดูว่าขึ้น ✅ หรือเปล่า
3. **ดู logs** - คลิก 'View Logs' ดูว่ามี error อะไร

ขั้นตอนที่ 1 ผ่านไหมครับ? URL webhook ของคุณคืออะไร?"

### Example 3: Feature Request
User: "อยากได้ feature ส่งรูป"
Assistant: "ขอบคุณสำหรับ feedback ครับ! 🙏

ตอนนี้ BotFacebook รองรับ:
- ข้อความ text
- Buttons และ Quick replies
- Carousel

**การส่งรูปภาพ** อยู่ใน roadmap แล้วครับ คาดว่าจะออกใน Q2

ต้องการให้เราแจ้งเมื่อ feature นี้พร้อมใช้งานไหมครับ?"

### Example 4: Out of Scope
User: "ช่วยเขียนโค้ด Python ให้หน่อย"
Assistant: "ผมเชี่ยวชาญเรื่อง BotFacebook และการสร้าง chatbot ครับ ถ้ามีคำถามเกี่ยวกับ:
- การตั้งค่า bot
- เชื่อมต่อ LINE/Telegram
- ปัญหาการใช้งาน

ยินดีช่วยเลยครับ! 😊"

---
Now respond to the user's message following these patterns.
```

### Implementing Few-Shot in Code

```php
// FewShotService.php
class FewShotService
{
    public function getExamplesForIntent(string $intent): array
    {
        $examples = [
            'pricing' => [
                [
                    'user' => 'ราคาเท่าไหร่',
                    'assistant' => "BotFacebook มีแพ็คเกจดังนี้ครับ:\n\n🆓 **Free** - ฟรี, 100 ข้อความ/เดือน\n⭐ **Pro** - 499 บาท/เดือน\n\nดูรายละเอียดเพิ่มเติมได้ที่ botjao.com/pricing ครับ 😊",
                ],
                [
                    'user' => 'Pro กับ Business ต่างกันยังไง',
                    'assistant' => "ความแตกต่างหลักๆ ครับ:\n\n| Feature | Pro | Business |\n|---------|-----|----------|\n| Bots | 3 | ไม่จำกัด |\n| API Access | ❌ | ✅ |\n| Support | Email | Priority |\n\nถ้าต้องการหลาย bot หรือ API แนะนำ Business ครับ",
                ],
            ],
            'troubleshooting' => [
                [
                    'user' => 'bot ไม่ตอบ',
                    'assistant' => "มาแก้ไขกันครับ! 🔧\n\n**เช็คสิ่งเหล่านี้:**\n1. Bot status เปิดอยู่ไหม? (Settings > General)\n2. Webhook connected? (ดู status ที่ Dashboard)\n3. มี error ใน logs ไหม?\n\nบอกผลการเช็คได้เลยครับ จะช่วยหาสาเหตุต่อ",
                ],
            ],
            'feature_request' => [
                [
                    'user' => 'อยากได้ feature X',
                    'assistant' => "ขอบคุณสำหรับ feedback ครับ! 🙏\n\nผมจะส่งต่อให้ทีมพัฒนาครับ หากต้องการติดตามความคืบหน้า สามารถดูได้ที่ roadmap: botjao.com/roadmap",
                ],
            ],
        ];

        return $examples[$intent] ?? $examples['troubleshooting'];
    }

    public function formatExamplesForPrompt(array $examples): string
    {
        $formatted = "## Response Examples\n\n";

        foreach ($examples as $i => $example) {
            $num = $i + 1;
            $formatted .= "### Example {$num}\n";
            $formatted .= "User: \"{$example['user']}\"\n";
            $formatted .= "Assistant: \"{$example['assistant']}\"\n\n";
        }

        return $formatted;
    }

    public function detectIntent(string $query): string
    {
        $patterns = [
            'pricing' => ['ราคา', 'price', 'cost', 'แพง', 'ถูก', 'plan', 'subscription'],
            'troubleshooting' => ['ไม่ทำงาน', 'error', 'ปัญหา', 'ไม่ได้', 'help', 'issue'],
            'feature_request' => ['อยากได้', 'feature', 'เพิ่ม', 'request', 'suggest'],
        ];

        foreach ($patterns as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($query, $keyword) !== false) {
                    return $intent;
                }
            }
        }

        return 'general';
    }
}
```

### Dynamic Few-Shot Selection

```php
// DynamicFewShotService.php
class DynamicFewShotService
{
    public function selectRelevantExamples(
        string $query,
        int $maxExamples = 3
    ): array {
        // Get all examples from database
        $allExamples = FewShotExample::where('is_active', true)->get();

        // Calculate similarity to query using embeddings
        $queryEmbedding = $this->embeddingService->embed($query);

        $scored = $allExamples->map(function ($example) use ($queryEmbedding) {
            $similarity = $this->cosineSimilarity(
                $queryEmbedding,
                $example->user_embedding
            );

            return [
                'example' => $example,
                'score' => $similarity,
            ];
        });

        // Return top N most similar
        return $scored
            ->sortByDesc('score')
            ->take($maxExamples)
            ->map(fn($item) => [
                'user' => $item['example']->user_message,
                'assistant' => $item['example']->assistant_message,
            ])
            ->values()
            ->toArray();
    }

    // Store good responses as examples
    public function addExample(
        string $userMessage,
        string $assistantMessage,
        string $category
    ): FewShotExample {
        return FewShotExample::create([
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'category' => $category,
            'user_embedding' => $this->embeddingService->embed($userMessage),
            'is_active' => true,
        ]);
    }
}
```

### Few-Shot Best Practices

```
1. **Number of Examples**
   - 2-5 examples usually sufficient
   - More isn't always better (token cost)
   - Quality > Quantity

2. **Diversity**
   - Cover different scenarios
   - Include edge cases
   - Mix easy and complex

3. **Consistency**
   - Same format across examples
   - Same tone/style
   - Same level of detail

4. **Relevance**
   - Match expected use cases
   - Dynamic selection when possible
   - Update examples regularly
```

## Testing

```php
public function test_few_shot_produces_consistent_format(): void
{
    $fewShot = new FewShotService();
    $examples = $fewShot->getExamplesForIntent('pricing');

    // All examples should have emoji
    foreach ($examples as $example) {
        $this->assertMatchesRegularExpression(
            '/[\x{1F300}-\x{1F9FF}]/u',
            $example['assistant']
        );
    }
}

public function test_dynamic_selection_returns_relevant_examples(): void
{
    $service = new DynamicFewShotService();

    // Create some examples
    FewShotExample::factory()->create([
        'user_message' => 'ราคา Pro เท่าไหร่',
        'category' => 'pricing',
    ]);

    $examples = $service->selectRelevantExamples('ราคาแพ็คเกจ');

    $this->assertNotEmpty($examples);
    $this->assertStringContainsString('ราคา', $examples[0]['user']);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- Examples stored in few_shot_examples table
- Dynamic selection based on similarity
- Admins can add/edit examples in dashboard
- Quality examples marked manually
