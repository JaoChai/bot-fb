# Prompt Eng Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 13:40

## Table of Contents

**Total Rules: 15**

- [Prompt Design](#design) - 5 rules (4 HIGH)
- [Injection Defense](#injection) - 4 rules (2 CRITICAL)
- [A/B Testing](#test) - 3 rules (1 HIGH)
- [Prompt Patterns](#pattern) - 3 rules (2 HIGH)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Prompt injection, security vulnerabilities |
| **HIGH** | AI response quality, consistency |
| **MEDIUM** | Optimization, performance |
| **LOW** | Style, minor improvements |

## Prompt Design
<a name="design"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [design-001-context-window](rules/design-001-context-window.md) | **HIGH** | Context Window Management |
| [design-002-system-prompt](rules/design-002-system-prompt.md) | **HIGH** | System Prompt Design |
| [design-003-constraints](rules/design-003-constraints.md) | **HIGH** | Prompt Constraints & Boundaries |
| [design-005-prompt-structure](rules/design-005-prompt-structure.md) | **HIGH** | Prompt Structure & Organization |
| [design-004-output-format](rules/design-004-output-format.md) | MEDIUM | Output Format Specification |

**design-001-context-window**: Context window is limited and expensive.

**design-002-system-prompt**: The system prompt is the AI's foundation.

**design-003-constraints**: Without clear constraints, AI can:
- Share sensitive information
- Make unauthorized promises
- Generate inappropriate content
- Deviate from inten...

**design-005-prompt-structure**: Well-structured prompts are:
- Easier to maintain
- More consistent
- Easier to debug
- More effective

**design-004-output-format**: Unstructured output leads to:
- Inconsistent UI rendering
- Failed parsing (JSON, markdown)
- Extra post-processing work
- Poor user experience

## Injection Defense
<a name="injection"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [injection-001-input-sanitization](rules/injection-001-input-sanitization.md) | **CRITICAL** | Input Sanitization for Prompts |
| [injection-002-system-prompt-protection](rules/injection-002-system-prompt-protection.md) | **CRITICAL** | System Prompt Protection |
| [injection-003-guardrails](rules/injection-003-guardrails.md) | **HIGH** | Prompt Guardrails |
| [injection-004-output-filtering](rules/injection-004-output-filtering.md) | **HIGH** | Output Filtering & Validation |

**injection-001-input-sanitization**: User input directly inserted into prompts can:
- Override system instructions
- Extract sensitive information
- Manipulate AI behavior
- Bypass saf...

**injection-002-system-prompt-protection**: System prompt leakage can reveal:
- Business logic and competitive advantages
- Security mechanisms and their weaknesses
- API keys or credentials ...

**injection-003-guardrails**: Without guardrails, AI can be manipulated to:
- Generate harmful content
- Discuss off-topic subjects
- Bypass intended limitations
- Provide dange...

**injection-004-output-filtering**: Even with input sanitization and guardrails, AI can still:
- Accidentally leak sensitive data
- Generate hallucinated credentials
- Include interna...

## A/B Testing
<a name="test"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [test-001-ab-testing](rules/test-001-ab-testing.md) | **HIGH** | A/B Testing Prompts |
| [test-002-metrics](rules/test-002-metrics.md) | MEDIUM | Prompt Quality Metrics |
| [test-003-versioning](rules/test-003-versioning.md) | MEDIUM | Prompt Versioning |

**test-001-ab-testing**: Without A/B testing:
- Relying on intuition vs data
- No way to measure improvements
- Risk of regression
- Missing optimization opportunities

**test-002-metrics**: Without metrics:
- No way to know if prompts are working
- Can't detect degradation
- No data for optimization
- Blind to user experience

**test-003-versioning**: Without versioning:
- Can't track what changed when
- No way to rollback bad changes
- Lost history of optimizations
- Can't correlate changes with...

## Prompt Patterns
<a name="pattern"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [pattern-001-chain-of-thought](rules/pattern-001-chain-of-thought.md) | **HIGH** | Chain-of-Thought Prompting |
| [pattern-002-few-shot](rules/pattern-002-few-shot.md) | **HIGH** | Few-Shot Learning Prompts |
| [pattern-003-temperature](rules/pattern-003-temperature.md) | MEDIUM | Temperature & Model Parameters |

**pattern-001-chain-of-thought**: Chain-of-Thought (CoT) prompting helps AI:
- Break down complex problems
- Show reasoning process
- Reduce errors in multi-step tasks
- Provide mor...

**pattern-002-few-shot**: Few-shot prompting (providing examples) helps AI:
- Understand desired format
- Match tone and style
- Handle edge cases correctly
- Produce consis...

**pattern-003-temperature**: Model parameters control:
- Response creativity vs consistency
- Output length
- Stop conditions
- Probability adjustments

## Quick Reference by Tag

- **ab-test**: test-001-ab-testing
- **accuracy**: pattern-001-chain-of-thought
- **analytics**: test-002-metrics
- **audit**: test-003-versioning
- **boundaries**: design-003-constraints
- **complex-tasks**: pattern-001-chain-of-thought
- **consistency**: pattern-002-few-shot, pattern-003-temperature
- **constraints**: design-003-constraints
- **context**: design-001-context-window
- **cot**: pattern-001-chain-of-thought
- **examples**: pattern-002-few-shot
- **few-shot**: pattern-002-few-shot
- **filtering**: injection-004-output-filtering
- **format**: design-004-output-format
- **guardrails**: injection-003-guardrails, design-003-constraints
- **history**: test-003-versioning
- **injection**: injection-001-input-sanitization
- **input**: injection-001-input-sanitization
- **instructions**: design-002-system-prompt
- **jailbreak**: injection-003-guardrails
- **json**: design-004-output-format
- **leakage**: injection-002-system-prompt-protection
- **learning**: pattern-002-few-shot
- **maintainability**: design-005-prompt-structure
- **measurement**: test-002-metrics
- **metrics**: test-001-ab-testing, test-002-metrics
- **moderation**: injection-003-guardrails
- **optimization**: test-001-ab-testing, design-001-context-window
- **organization**: design-005-prompt-structure
- **output**: injection-004-output-filtering, design-004-output-format
- **parameters**: pattern-003-temperature
- **personality**: design-002-system-prompt
- **protection**: injection-002-system-prompt-protection
- **quality**: test-002-metrics
- **rag**: design-001-context-window
- **reasoning**: pattern-001-chain-of-thought
- **role**: design-002-system-prompt
- **rollback**: test-003-versioning
- **safety**: injection-003-guardrails, design-003-constraints
- **sanitization**: injection-001-input-sanitization
- **security**: injection-001-input-sanitization, injection-002-system-prompt-protection, injection-004-output-filtering
- **structure**: design-005-prompt-structure
- **structured**: design-004-output-format
- **system-prompt**: injection-002-system-prompt-protection, design-002-system-prompt
- **temperature**: pattern-003-temperature
- **template**: design-005-prompt-structure
- **testing**: test-001-ab-testing
- **tokens**: design-001-context-window
- **tuning**: pattern-003-temperature
- **validation**: injection-004-output-filtering
- **versioning**: test-003-versioning
