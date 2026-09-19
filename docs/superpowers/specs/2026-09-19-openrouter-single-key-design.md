# OpenRouter API key — one source, one accessor

Status: design approved section by section in chat on 2026-09-19. Planning only; this document is not deployment approval. Every production-touching step (setting a Railway variable, merging to `main`) needs the owner's approval in the turn it happens.

Planning baseline: `dd30520184a72fbd7237b7a97a8dc371b8743e0b`.

## Problem

The OpenRouter API key has two sources and only one of them works.

- `user_settings.openrouter_api_key` (encrypted, per user) is the key actually used in production.
- `config('services.openrouter.api_key')` ← `env('OPENROUTER_API_KEY')` is **not set in Railway** (checked across all 91 backend variables), so it is always null.

Consequences found on 2026-09-19:

1. 17 call sites in 15 files resolve the key from the bot's user with `$bot->user?->settings?->getOpenRouterApiKey()`; most append `?? config('services.openrouter.api_key')`, which is always null. The key is then threaded through roughly 25 method signatures in roughly 27 files, most of which never use it themselves.
2. Three system-level call sites read the env key only and therefore never get one:
   - `ModelCapabilityService::doFetchAllModels()` returns `[]` silently, so OpenRouter-based capability resolution has been dead since the service was written (2026-01-18, three weeks after the key moved to `user_settings` on 2025-12-31). Every model resolves from the 26-entry `config/llm-models.php` table or from conservative defaults. `openai/gpt-5.6-luna` (bot 26 chat and decision model), `meta/muse-spark-1.2-contributor` and `openai/gpt-5.1` (fallbacks) all resolve to defaults: no JSON mode, no `reasoning.effort`, 4096 context.
   - `PromptEvalRunner` sends `withToken('')`; `prompt:eval` cannot authenticate.
   - `OpenRouterService::$apiKey` is always empty; `isConfigured()` is always false.
3. `user_settings.openrouter_model` has no consumer outside the Settings page itself.

Production is single-tenant in practice: 14 users, 3 `user_settings` rows with a key, and every active bot belongs to user 14.

## Decisions (owner, 2026-09-19)

1. The key lives in **one place: the `OPENROUTER_API_KEY` environment variable** (Railway). The per-user key is removed.
2. All users share that key. Accepted: the system is used by one team. No per-user entitlement is added.
3. The OpenRouter section of the Settings page is removed entirely, including the unused user-level model picker.
4. Code reads the key through **one small class** that fails loudly when the key is missing.

## Architecture

New class `App\Services\OpenRouterCredentials`:

- `isConfigured(): bool` and `key(): string`, both reading `config('services.openrouter.api_key')`.
- `key()` on an empty or null value throws `App\Exceptions\OpenRouterException` whose message names `OPENROUTER_API_KEY`.
- `isConfigured()` exists so the background payment path (`OrderReconstructor`, `LLMOrderItemExtractor`), flow plugins and the three test/preview endpoints keep skipping gracefully when no key is configured.
- Stateless; resolved by the container without registration.

It is the only place in `app/` that reads `services.openrouter.api_key`. Its consumers are the five classes that make HTTP calls to OpenRouter: `OpenRouterService`, `EmbeddingService`, `StreamingResponseOrchestrator` (it makes its own streaming call), `ModelCapabilityService`, `PromptEvalRunner`. (A dedicated class rather than an accessor on `OpenRouterService` avoids the existing `OpenRouterService` → `ModelCapabilityService` dependency becoming circular.)

Before: controller/job resolves the key from the bot's user → passes it through RAGService → HybridSearch → SemanticSearch → EmbeddingService → HTTP.
After: the intermediate layers do not know a key exists; the class that makes the HTTP call asks `OpenRouterCredentials`.

Missing-key behavior:

- `OpenRouterService`, `EmbeddingService`, `PromptEvalRunner`: the exception propagates through the existing error handling (customers get the existing standard error message).
- `ModelCapabilityService`: keeps its designed fallback to the config table, but logs at **error** level. It must never again return `[]` silently.

Not touched: `JinaRerankerService` (Jina's own key), LINE and EasySlip credentials in `user_settings` (remain per user), `StoreBotRequest` (`api_keys` there are LINE tokens), historical migrations, `.env.example` (already has `OPENROUTER_API_KEY=`).

## Removal inventory

**A. Key resolution and threading (backend)**
- The 17 `getOpenRouterApiKey()` call sites: `StreamController`, `KnowledgeBaseController`, `BotController`, `FlowController`, `StickerReplyService`, `EntityExtractionService`, `FlowPluginService`, `IntentAnalysisService`, `VisionHandler`, `LineWebhookResponseService` (×2), `OrderReconstructor`, `LLMOrderItemExtractor`, `StreamingResponseOrchestrator`, `RAGKnowledgeBase`, `ProcessDocument` (×2, including its owner-lookup logic).
- The three duplicate `getApiKeyForBot()` helpers: `RAGService`, `IntentAnalysisService`, `RAGKnowledgeBase`.
- `?string $apiKey` / `$apiKeyOverride` parameters from: `OpenRouterService` (four public methods and `client()`), `EmbeddingService` (constructor and `withApiKey()`), `HybridSearchService`, `SemanticSearchService`, `SemanticCacheService`, `ContextualRetrievalService`, `CRAGService`, `RAGService`, `RAGKnowledgeBase`, `StreamingResponseOrchestrator`; and every argument passed to them.
- `OpenRouterService::$apiKey`; `isConfigured()` asks `OpenRouterCredentials` instead.

**B. Per-user key feature (backend)**
- `UserSettingController`: `updateOpenRouter()`, `testOpenRouter()`, `clearOpenRouter()`; fields `openrouter_configured`, `openrouter_api_key_masked`, `openrouter_model` in `show()`.
- Routes `PUT /settings/openrouter`, `DELETE /settings/openrouter`, `POST /settings/test-openrouter`.
- `UserSetting`: `getOpenRouterApiKey()`, `hasOpenRouterKey()`, `getMaskedOpenRouterKeyAttribute()`, and the `openrouter_api_key` / `openrouter_model` entries in `$fillable` and `$casts`. The `$hidden` entry stays until PR 2 drops the column, so the stored ciphertext can never be serialized in the meantime.
- `User.php` default `openrouter_model`.
- New migration dropping `user_settings.openrouter_api_key` and `user_settings.openrouter_model` (**PR 2 only**, see rollout).

**C. Frontend**
- The OpenRouter section of `SettingsPage.tsx`.
- `useUpdateOpenRouterSettings`, `useTestOpenRouterConnection`, `useClearOpenRouterKey` in `useUserSettings.ts`.
- `openrouter_configured`, `openrouter_api_key_masked`, `openrouter_model`, `UpdateOpenRouterSettings` in `types/api.ts`.
- The sentence in `AIModelsSection.tsx` that tells users to set the key on the Settings page.

## Rollout

One rule: **the key must exist in Railway before the new code deploys.** Reversed, every bot stops answering.

**Step 0 — set `OPENROUTER_API_KEY` in Railway. No code change.**
- Use a new, dedicated OpenRouter key (e.g. "bot-fb production") rather than reusing user 14's: separate usage reporting, its own spend limit, and no need to decrypt an existing key out of the database.
- Safe for current code, which prefers the user key and treats env as fallback.
- Immediate side effect, within about 30 minutes (the unknown-model cache TTL is 1800 s): `ModelCapabilityService` starts resolving from OpenRouter, so bot 26's luna gains JSON mode, `reasoning.effort = low`, and the enhanced intent prompt whose `search_query` feeds KB retrieval (`RAGService`). **This is the fix for the original luna problem, and it changes live retrieval behavior.**
- Verify: `prompt:eval --bot=26 --runs=2` before (run locally with the key supplied for that process only) and after; compare. Three BM cases fail regardless while "Nolimit Level Up+ BM" is out of stock.
- Rollback: delete the variable.

**Step 1 — PR 1: all code changes, no migration.**
- The two columns stay in the database, unread.
- Before merge: confirm the Step 0 variable is present.
- After merge: wait until the new build is actually serving (the old instance keeps serving for several minutes), send one real message to bot 26, check logs.
- Rollback: revert the PR; old code finds the user keys again because the columns are intact.

**Step 2 — PR 2: migration dropping both columns, at least 3 days after Step 1 is stable.**
- The only irreversible step: the three stored keys are destroyed. They are unused by then and can be reissued at OpenRouter.

## Testing

New:
- `OpenRouterCredentialsTest`: configured key is returned; empty and null throw with a message containing `OPENROUTER_API_KEY`.
- Guard test scanning `app/`: `services.openrouter.api_key` appears only in `OpenRouterCredentials.php`; `getOpenRouterApiKey` and `apiKeyOverride` appear nowhere.

Changed:
- Six tests that seed a per-user key switch to `config(['services.openrouter.api_key' => ...])`: `OrderReconstructorTest`, `ConfirmMessageFallbackTest`, `LLMOrderItemFallbackTest`, `OrderChecksumGuardTest`, `SlipVerificationServiceTest`, `VisionHandlerTest`.
- `ModelCapabilityServiceTest` null-key cases: still fall back to the config table, and now assert the error log.
- `OpenRouterServiceTest` empty-key case: expects the `OpenRouterCredentials` exception.
- No existing test exercises the three removed `UserSettingController` endpoints (checked), so none is deleted.
- The five tests that already set the config key need no change.

Gate before merging PR 1:
1. `php artisan test` passes; no test that passes on `main` fails.
2. Frontend `tsc`, lint and vitest pass.
3. `prompt:eval --bot=26 --runs=2` is no worse than the Step 0 "after" baseline (BM cases excepted).
4. CI Backend Tests and Frontend Checks are green.

After deploy: a real message to bot 26 gets a normal reply; logs contain no `OPENROUTER_API_KEY` exception; `php artisan model:warm-cache --model=openai/gpt-5.6-luna` reports a source other than `default+heuristic`.

## Out of scope

- `require_parameters: true` in provider preferences. OpenRouter determines structured-output support per endpoint, and luna's Amazon Bedrock endpoint does not list `response_format`; but the setting affects routing for every request and model, so it is a separate change.
- Adding luna, muse-spark or gpt-5.1 to `config/llm-models.php`. Step 0 makes the table a true fallback again.
- Thai chunking in `ChunkingService` and any Jev (TypeSafe decisions model) integration.
