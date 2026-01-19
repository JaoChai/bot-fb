---
id: pattern-001-chain-of-thought
title: Chain-of-Thought Prompting
impact: HIGH
impactDescription: "Guide AI through step-by-step reasoning for better answers"
category: pattern
tags: [reasoning, cot, complex-tasks, accuracy]
relatedRules: [design-005-prompt-structure, pattern-002-few-shot]
---

## Why This Matters

Chain-of-Thought (CoT) prompting helps AI:
- Break down complex problems
- Show reasoning process
- Reduce errors in multi-step tasks
- Provide more accurate answers

Without CoT:
- AI jumps to conclusions
- Missing intermediate steps
- Harder to debug wrong answers
- Inconsistent reasoning

## The Problem

Direct questions get direct (often wrong) answers:
- No visible reasoning
- Easy to miss edge cases
- Hard to verify correctness
- Shallow analysis

## Solution

### Before (Direct Prompting)

```
User: Should this user get a refund?

Context:
- User purchased on January 5
- Today is January 20
- Refund policy: 14 days
- Product: Software subscription
- Reason: "Doesn't work as expected"
```

AI might jump to: "No, it's past 14 days" without considering nuances.

### After (Chain-of-Thought)

```
When analyzing refund requests, think through these steps:

1. **Identify relevant facts**
   - Purchase date
   - Current date
   - Days since purchase
   - Product type
   - Refund policy details

2. **Check policy conditions**
   - Is request within time limit?
   - Does reason qualify for exception?
   - Any special circumstances?

3. **Consider edge cases**
   - Technical issues reported before deadline?
   - Previous interactions?
   - Customer history?

4. **Form recommendation**
   - Based on analysis, recommend approve/deny
   - Explain reasoning clearly

Now analyze this refund request:
[context]
```

### CoT System Prompt Template

```php
$systemPrompt = <<<EOT
You are a support assistant that analyzes issues step by step.

## Reasoning Process
For every question, follow this thinking structure:

1. **Understand the Question**
   - What is the user actually asking?
   - What information do they need?

2. **Gather Relevant Information**
   - What facts from the context apply?
   - What's missing that I should acknowledge?

3. **Analyze**
   - Consider different angles
   - Check for edge cases
   - Identify potential issues

4. **Form Response**
   - Clear, direct answer
   - Supporting reasoning
   - Next steps if applicable

## Response Format
Start with your analysis (can be brief), then provide the answer.
For simple questions, keep reasoning minimal.
For complex questions, show more detailed thinking.

Example:
User: "Can I change my subscription plan mid-cycle?"

Thinking:
- User wants to change plans
- Need to check: timing rules, prorating, feature changes
- Context: They're on monthly plan

From the knowledge base, plan changes take effect:
- Immediately for upgrades (prorated charge)
- At next billing cycle for downgrades

Answer: "Yes, you can change your plan anytime! If upgrading, the change is immediate and you'll be charged the prorated difference. If downgrading, it will take effect at your next billing date."
EOT;
```

### Implementing CoT in Code

```php
// ChainOfThoughtService.php
class ChainOfThoughtService
{
    private array $reasoningTemplates = [
        'troubleshooting' => [
            'Identify the reported issue',
            'Check for known causes',
            'Analyze user\'s setup/context',
            'Determine solution steps',
            'Formulate response with actions',
        ],
        'policy_question' => [
            'Identify what policy applies',
            'Extract relevant policy rules',
            'Apply rules to user situation',
            'Consider exceptions',
            'Provide clear guidance',
        ],
        'recommendation' => [
            'Understand user needs',
            'List relevant options',
            'Compare pros and cons',
            'Consider user context',
            'Make specific recommendation',
        ],
    ];

    public function enhancePrompt(string $basePrompt, string $type): string
    {
        $steps = $this->reasoningTemplates[$type] ?? $this->reasoningTemplates['troubleshooting'];

        $stepsList = collect($steps)
            ->map(fn($step, $i) => ($i + 1) . ". {$step}")
            ->join("\n");

        return $basePrompt . <<<EOT

## Reasoning Process
Before answering, think through these steps:
{$stepsList}

Show brief reasoning, then provide your answer.
EOT;
    }

    public function detectQuestionType(string $query): string
    {
        $patterns = [
            'troubleshooting' => ['not working', 'error', 'problem', 'issue', 'help', 'ไม่ทำงาน', 'ปัญหา'],
            'policy_question' => ['can i', 'allowed', 'policy', 'refund', 'นโยบาย', 'ได้ไหม'],
            'recommendation' => ['should i', 'which', 'recommend', 'best', 'แนะนำ', 'เลือก'],
        ];

        foreach ($patterns as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($query, $keyword) !== false) {
                    return $type;
                }
            }
        }

        return 'troubleshooting'; // default
    }
}
```

### Zero-Shot CoT (Simple Technique)

```php
// Sometimes just adding this phrase works well
$prompt = $userQuestion . "\n\nLet's think step by step.";
```

### Self-Consistency with CoT

```php
// For critical decisions, generate multiple CoT paths and pick consensus
class SelfConsistencyService
{
    public function getConsensusAnswer(string $prompt, int $paths = 3): array
    {
        $answers = [];

        for ($i = 0; $i < $paths; $i++) {
            $response = $this->llmService->chat([
                ['role' => 'system', 'content' => $this->cotSystemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.7, // Higher temp for diversity
            ]);

            $answer = $this->extractFinalAnswer($response['content']);
            $answers[] = $answer;
        }

        // Find consensus
        $frequency = array_count_values($answers);
        arsort($frequency);
        $consensus = array_key_first($frequency);

        return [
            'answer' => $consensus,
            'confidence' => $frequency[$consensus] / $paths,
            'all_answers' => $answers,
        ];
    }
}
```

## When to Use CoT

| Scenario | Use CoT? | Reason |
|----------|----------|--------|
| Simple factual question | No | Adds unnecessary tokens |
| Multi-step reasoning | Yes | Improves accuracy |
| Policy decision | Yes | Shows justification |
| Math/logic problem | Yes | Reduces errors |
| Creative response | No | Can feel robotic |
| Troubleshooting | Yes | Systematic approach |

## Testing

```php
public function test_cot_improves_accuracy_on_complex_questions(): void
{
    $complexQuestion = "User bought premium on Jan 5, downgraded on Jan 10, wants refund of difference. Our policy says no refunds for voluntary downgrades. But they claim the feature they wanted doesn't work. What should we do?";

    // Without CoT
    $directResponse = $this->llm->chat([
        ['role' => 'user', 'content' => $complexQuestion],
    ]);

    // With CoT
    $cotResponse = $this->llm->chat([
        ['role' => 'system', 'content' => $this->cotPrompt],
        ['role' => 'user', 'content' => $complexQuestion],
    ]);

    // CoT response should contain reasoning indicators
    $this->assertStringContainsString('considering', strtolower($cotResponse['content']));
}
```

## Project-Specific Notes

**BotFacebook Context:**
- Use CoT for troubleshooting flows
- Skip CoT for simple FAQ answers
- ChainOfThoughtService in app/Services/AI/
- Log reasoning for debugging
