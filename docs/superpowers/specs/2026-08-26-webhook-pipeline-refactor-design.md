# Design: Unified Webhook Pipeline Refactor

**Date:** 2026-08-26
**Status:** Draft — awaiting user review
**Path:** Architectural (brainstorming skill)

## Problem

The three channel webhook jobs duplicate the same orchestration logic and have grown into monoliths:

| Job | Lines | Duplicated logic |
|---|---|---|
| `ProcessLINEWebhook` | 1,531 | circuit breaker wrapper, fallback message, DB transaction, createNewConversation, generateAIResponse, failed() handler |
| `ProcessFacebookWebhook` | 728 | same six patterns, independently implemented |
| `ProcessTelegramWebhook` | 561 | same six patterns, independently implemented |

Consequences:
- Bug fixes must be applied three times; they drift (evidence: `450850c` — queue-worker-fast not listening to `low` queue for 5 months went unnoticed).
- `ProcessLINEWebhook` mixes vision analysis (~500 lines), sticker replies, and stats updates in one file.
- Adding a feature touching webhooks requires reading all three jobs.

Existing assets to build on (not start from scratch):
- `app/Services/Channel/ChannelAdapterInterface` + LINE/Telegram adapters (currently send-message only).
- LINE already has an in-progress pipeline behind `LineWebhookPipelineFlag`: `runPipeline()` calls Gating → Context → Response → Output services (`LineWebhook/*Service.php`, each ~470-730 lines).
- `app/Services/Chat/*` services already own conversation/message domain logic.
- Test coverage: 53 Feature + 19 Unit test files including per-channel webhook tests.

## Goal

One shared webhook pipeline owns cross-channel orchestration. Each channel job becomes a thin dispatcher that maps provider payloads onto a common contract and delegates channel-specific handling to handlers.

Non-goals:
- No behavior change in any response path (pure refactor; verified by existing tests).
- No changes to RAGService, FlowController, or frontend (separate future work).
- No new features.

## Architecture

### New namespace: `app/Services/Webhook/`

```
app/Services/Webhook/
├── WebhookContext.php          # DTO: bot, conversation, customer profile,
│                               #   normalized message (type/text/media), raw event
├── IncomingMessage.php         # normalized message value object
├── WebhookPipeline.php         # orchestrator: runs steps in order, owns transaction
├── Steps/                      # shared steps (channel-agnostic)
│   ├── ResolveConversationStep.php    # find-or-create conversation + profile
│   ├── AggregateMessagesStep.php      # aggregation window check (delegates to MessageAggregationService)
│   ├── RateLimitStep.php              # delegates to RateLimitService
│   ├── GenerateResponseStep.php       # AI response via AIService/RAGService
│   └── SendResponseStep.php           # sends via ChannelAdapter
└── CircuitBreakerJobMiddleware.php     # job middleware wrapping handle() with
                                        #   circuit breaker + fallback (Laravel job middleware)
```

### Channel layer

```
app/Services/Webhook/Channels/
├── LINE/
│   ├── LineEventMapper.php       # raw LINE event -> WebhookContext (replaces inline parsing)
│   ├── VisionHandler.php         # image analysis (~500 lines extracted verbatim)
│   ├── StickerHandler.php        # sticker reply logic extracted verbatim
│   └── NonTextHandler.php        # non-text message handling extracted verbatim
├── Facebook/
│   ├── FacebookEventMapper.php   # messaging events + postbacks -> WebhookContext
│   └── PostbackHandler.php
└── Telegram/
    └── TelegramEventMapper.php   # updates + media -> WebhookContext
```

### Contracts

```php
interface WebhookEventMapper {
    public function supports(array $event): bool;
    public function map(array $event, Bot $bot): ?WebhookContext; // null = ignore event
}

interface WebhookStep {
    public function handle(WebhookContext $ctx, \Closure $next): void;
}
```

Each channel registers its ordered step list (LINE prepends Vision/Sticker steps before GenerateResponse). The generic flow every channel gets:

1. middleware: circuit breaker + rate limit + fallback-on-failure
2. ResolveConversation (inside one `DB::transaction`)
3. channel-specific pre-steps (vision/sticker/postback/media)
4. AggregateMessages → GenerateResponse → SendResponse

### Job slimming target

| Job | Before | After |
|---|---|---|
| ProcessLINEWebhook | 1,531 | < 150 — construct WebhookContext via LineEventMapper, dispatch pipeline |
| ProcessFacebookWebhook | 728 | < 150 — same shape |
| ProcessTelegramWebhook | 561 | < 150 — same shape |

## Migration strategy (behavior-preserving)

Phase 0 — Safety net
- Run full backend suite before any change; record baseline pass count.
- No production deploy until full suite passes locally (user preference: quality over speed).

Phase 1 — Extract middleware
- Move circuit breaker/fallback try-catch from all three jobs into `CircuitBreakerJobMiddleware`.
- Jobs keep their process methods unchanged. Suite green.

Phase 2 — Extract LINE channel handlers
- Cut VisionHandler / StickerHandler / NonTextHandler out of ProcessLINEWebhook **verbatim** (no logic edits).
- Existing `LineWebhookPipelineFlag` flag semantics preserved; legacy path calls the same handlers. Suite green.

Phase 3 — Build WebhookPipeline + shared steps
- Introduce DTO, contracts, pipeline, shared steps. Wire LINE through it behind the existing `LineWebhookPipelineFlag` (default off in prod until Phase 5).

Phase 4 — Migrate Facebook & Telegram
- Write EventMappers mapping payloads to WebhookContext; jobs become thin dispatchers.
- Feature flags per channel (`WebhookPipelineV2Flag` style), default off.

Phase 5 — Rollout & cleanup
- Enable flags per channel in staging/prod gradually; monitor error rates + fallback triggers.
- Delete dead code paths once both paths proven identical over a release cycle.
- Final state: no duplicated orchestration across jobs.

## Error handling

- Pipeline preserves current semantics exactly: circuit-open → channel fallback message, no retry; unexpected exception → log with bot_id/event_type (+trace outside prod) then rethrow for queue retry.
- Fallback message sending stays DB-independent as today.

## Testing

- All 72 existing backend test files must stay green throughout — this is the behavior-preservation proof.
- New unit tests: WebhookContext mapping per channel (fixture payloads from existing webhook tests reused), pipeline step ordering, middleware fallback behavior.
- `prompt:eval` unaffected (no prompt changes).
- Per project git-flow: each phase = separate commits, CI green per commit.

## Risks

| Risk | Mitigation |
|---|---|
| Subtle behavior drift between old/new paths | Verbatim extraction first; flags allow A/B; existing tests cover both paths |
| Transaction scope change breaks idempotency | Keep single DB::transaction around resolve+persist steps exactly as today (see IdempotencyTest) |
| Queue worker coverage regression | QueueWorkerCoverageTest exists; keep queue names untouched |
