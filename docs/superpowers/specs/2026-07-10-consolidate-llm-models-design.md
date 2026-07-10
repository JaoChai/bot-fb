# Consolidate LLM Models: Single Primary + Fallback Pair

**Date:** 2026-07-10
**Status:** Approved by owner
**Depends on:** PR "form-only fallback" (must merge first — separate PR per owner decision)

## Problem

The Connection Settings form exposes 4 model fields (chat primary/fallback + decision primary/fallback), plus the DB carries 4 more unused model columns (Smart Routing cascade pair, toggle, legacy `llm_fallback_model`). Two parallel model-selection systems exist in code (chat vs decision), which already caused an emulator-vs-production behavior divergence. The owner wants ONE pair — primary + fallback — used everywhere, configured in one place.

## Decisions (owner-confirmed)

1. **Primary model does everything** — intent analysis (decision step) uses the same primary/fallback pair as answer generation. Accepted cost impact: Bot 26's intent step moves from `google/gemini-3.5-flash` (cheap) to `openai/gpt-5.6-luna` (pricier, slightly slower per message). Bot 28 is unaffected (its pairs are already identical).
2. **Remove everything else** — decision pair, Smart Routing cascade fields (`use_confidence_cascade` is off for both bots), and legacy `llm_fallback_model` (no code reads it). Code + DB columns.
3. **Single PR** for this consolidation (code + migration together), tested on a Neon branch first.

## Design

### 1. UI — Connection Settings

`AIModelsSection` shows 2 fields only: "LLM Model หลัก" + "โมเดลสำรอง (fallback)".

- `ModelSelector.tsx`: drop the decision row and `showDecisionModels` / decision props from `ModelConfiguration` (AIModelsSection is its only consumer).
- `useConnectionForm.ts`, `EditConnectionPage.tsx`, `types/api.ts`: remove `decision_model`, `fallback_decision_model`, and the cascade fields (`use_confidence_cascade`, `cascade_cheap_model`, `cascade_expensive_model`) — grep confirmed all three files reference them.

### 2. Backend — one selection point

All work reads `bots.primary_chat_model` + `bots.fallback_chat_model` under the already-shipped rule: form values only, empty fallback = no fallback, missing primary = clear `OpenRouterException`.

- **IntentAnalysisService**: decision model := primary chat model; fallback := form fallback. Delete `getDecisionModelForBot()` / `getFallbackDecisionModelForBot()`. The "skip when no decision model" branch changes to: intent analysis always runs when a chat model exists (this also removes the emulator-vs-production divergence found in the 2026-07-10 review). Enhanced (reasoning) intent prompt now keys off the primary model's capability.
- **StreamingResponseOrchestrator** (`runDecisionModel`): same rule — use the chat pair; remove `$bot->decision_model` skip branch.
- **RAGService**: delete Smart Routing (`resolveSmartChatModel` cascade branches, keep `detectComplexity` only for its unrelated max-tokens use) and the dead decision-model selector copies.
- **Vision model candidates** (`ProcessLINEWebhook::getVisionModel`, `LineWebhookResponseService`, `StickerReplyService`): candidate list becomes `[primary_chat_model, fallback_chat_model]`.
- **Bot model / API surface**: remove the 6 fields from `Bot::$fillable`, `BotResource`, `StoreBotRequest`, `UpdateBotRequest`, OpenApi annotations.

### 3. Database migration

Drop 6 columns from `bots`: `decision_model`, `fallback_decision_model`, `cascade_cheap_model`, `cascade_expensive_model`, `use_confidence_cascade`, `llm_fallback_model`.

- `down()` re-adds them (nullable / boolean default false).
- Test on a Neon branch before production (safe-migration workflow).
- **Rollback data snapshot (2026-07-10, both live bots):**

| bot | decision_model | fallback_decision_model | llm_fallback_model | use_confidence_cascade | cascade_cheap | cascade_expensive |
|---|---|---|---|---|---|---|
| 26 Line - Adsvance | google/gemini-3.5-flash | openai/gpt-5.4-mini | openai/gpt-4o-mini | false | openai/gpt-4o-mini | openai/gpt-5-mini |
| 28 Line Support Adsvance | google/gemini-3-flash-preview | openai/gpt-5.1 | openai/gpt-4o-mini | false | null | null |

### 4. Error handling

Unchanged from the form-only-fallback PR: missing primary → `OpenRouterException(400)` → friendly bot reply on webhook paths, SSE `error`+`done` on streaming, 422 on flow-test endpoint. Intent-analysis failure stays non-fatal (defaults to knowledge/chat).

### 5. Testing

- Update tests referencing removed fields: `IntentAnalysisServiceTest`, `RAGServiceTest`, `LineWebhookResponseServiceTest`, `StreamControllerTest`.
- Full backend suite + frontend build must pass.
- Manual: emulator chat on bot 26 (intent + answer on one pair), then LINE production check after deploy.

## Impact summary

- Bot 26: intent step cost rises (big model does classification); one config surface; fewer failure modes.
- Bot 28: no behavior change.
- Emulator and production intent behavior become identical.
- ~6 dead DB columns and one dead feature (Smart Routing) removed.

## Out of scope

- Model dropdown/validation against the OpenRouter catalog (free-text stays).
- Thai-language error copy for AI failures (separate backlog item from the 2026-07-10 review).
