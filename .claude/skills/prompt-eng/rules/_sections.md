# Prompt Engineering Decision Trees & Patterns

## Prompt Quality Decision Tree

```
User wants better AI responses
├── Responses too generic?
│   ├── Add specific context → design-001-context-window
│   ├── Add role definition → design-002-system-prompt
│   └── Add examples (few-shot) → pattern-002-few-shot
│
├── Responses inconsistent?
│   ├── Add format instructions → design-004-output-format
│   ├── Add constraints → design-003-constraints
│   └── Use temperature control → pattern-003-temperature
│
├── Responses miss the point?
│   ├── Restructure prompt → design-005-prompt-structure
│   ├── Add chain-of-thought → pattern-001-chain-of-thought
│   └── Test variations → test-001-ab-testing
│
└── Security concerns?
    ├── User input in prompt → injection-001-input-sanitization
    ├── Prompt leakage → injection-002-system-prompt-protection
    ├── Jailbreak attempts → injection-003-guardrails
    └── Data extraction → injection-004-output-filtering
```

## Prompt Structure Template

```
System Prompt Structure:
┌─────────────────────────────────────────────────┐
│ 1. Role Definition                              │
│    "You are a [role] that [purpose]..."         │
├─────────────────────────────────────────────────┤
│ 2. Context                                      │
│    Background information, knowledge base       │
├─────────────────────────────────────────────────┤
│ 3. Instructions                                 │
│    Step-by-step guidance on how to respond      │
├─────────────────────────────────────────────────┤
│ 4. Constraints                                  │
│    What NOT to do, limitations, boundaries      │
├─────────────────────────────────────────────────┤
│ 5. Output Format                                │
│    How to structure the response                │
├─────────────────────────────────────────────────┤
│ 6. Examples (optional)                          │
│    Few-shot examples of good responses          │
└─────────────────────────────────────────────────┘
```

## Model Selection Guide

| Use Case | Model Tier | Example Models |
|----------|------------|----------------|
| Simple Q&A | Budget | GPT-4o-mini, Claude 3 Haiku |
| Complex reasoning | Standard | GPT-4o, Claude 3.5 Sonnet |
| Critical/Creative | Premium | GPT-4 Turbo, Claude 3 Opus |
| Thai language | Thai-optimized | Typhoon, SeaLLM |

## Temperature Guidelines

| Temperature | Use Case | Example |
|-------------|----------|---------|
| 0.0-0.3 | Factual, consistent | FAQ, data extraction |
| 0.3-0.7 | Balanced | General chat, Q&A |
| 0.7-1.0 | Creative | Brainstorming, stories |

## Prompt Testing Checklist

- [ ] Test with normal inputs
- [ ] Test with edge cases (empty, very long)
- [ ] Test with adversarial inputs (injection attempts)
- [ ] Test with multiple languages
- [ ] Measure token usage
- [ ] Compare with baseline
- [ ] Document results

## Common Prompt Antipatterns

1. **Vague instructions**: "Be helpful" → Use specific guidance
2. **No role definition**: Missing persona → Add role and context
3. **No output format**: Free-form responses → Specify structure
4. **User input unsanitized**: Direct interpolation → Sanitize and validate
5. **Overloaded prompts**: Too many instructions → Split into focused prompts
6. **No examples**: Abstract instructions → Add few-shot examples
