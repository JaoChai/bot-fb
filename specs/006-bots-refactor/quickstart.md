# Quickstart Guide: Bots Page Refactoring

**Feature**: 006-bots-refactor
**Date**: 2026-01-09

## Overview

This guide provides quick instructions for developers working on the Bots Page refactoring project. It covers local setup, testing, and implementation priorities.

---

## Prerequisites

- PHP 8.2+
- Node.js 20+
- PostgreSQL (Neon)
- Composer 2.x
- npm/pnpm

---

## Local Development Setup

### 1. Clone and Checkout Branch

```bash
git clone <repository-url>
cd bot-fb
git checkout 006-bots-refactor
```

### 2. Backend Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Start server
php artisan serve
```

### 3. Frontend Setup

```bash
cd frontend
npm install
npm run dev
```

---

## Implementation Priority Order

### Phase 1: Critical Security & Bug Fixes (Week 1-2)

**Start Here - These are blocking issues:**

1. **FR-001, FR-002: Credential Security**
   - File: `backend/app/Http/Resources/BotResource.php`
   - Add conditional masking of `channel_access_token` and `channel_secret`
   - Create `/bots/{id}/credentials` endpoint for reveal functionality

2. **FR-003: Race Condition Fix**
   - File: `backend/app/Http/Controllers/Api/FlowController.php`
   - Wrap `setDefault()` in `DB::transaction()` with `lockForUpdate()`

3. **FR-004: N+1 Query Fix**
   - File: `backend/app/Http/Controllers/Api/AdminController.php`
   - Replace loop with `withCount()` subquery

4. **FR-006: Foreign Key Constraint**
   - Create migration for `default_flow_id` with `nullOnDelete`

5. **FR-007: KB Validation**
   - File: `backend/app/Http/Requests/Flow/UpdateFlowRequest.php`
   - Add validation rules for `kb_top_k` and `kb_similarity_threshold`

### Phase 2: Missing Features (Week 2-3)

**After Phase 1 is complete:**

1. **FR-008, FR-009, FR-010: Facebook Integration**
   - Create `FacebookWebhookController.php`
   - Create `FacebookService.php`
   - Create `ProcessFacebookWebhook.php` job

2. **FR-014: LINE Auto Webhook**
   - Update `LINEService.php` with `setWebhook()` method

3. **FR-011, FR-012, FR-013: Clean Up Unused Features**
   - Remove `webhook_forwarder_enabled` toggle from UI
   - Remove Plugins section from FlowEditorPage
   - Remove External Data Sources section

### Phase 3: Frontend Refactor (Week 3-4)

**Can run parallel to Phase 2:**

1. **FR-015: Split BotSettingsPage**
   - Create `frontend/src/components/bot-settings/` directory
   - Extract 11 sections as separate components
   - See component list in `plan.md`

2. **FR-016: Split FlowEditorPage**
   - Create `frontend/src/components/flow-editor/` directory
   - Extract 6 sections as separate components

3. **FR-017, FR-018, FR-019, FR-020: Hook Cleanup**
   - Move `useBots()` to dedicated file
   - Consolidate `BotEditPage` into `EditConnectionPage`
   - Standardize refetch strategies

### Phase 4: Backend Refactor (Week 4-5)

**After Phases 2-3:**

1. **FR-021: Table Split**
   - Create migrations for new tables (see `data-model.md`)
   - Create new models: `BotLimits`, `BotHITLSettings`, etc.
   - Migrate data from `bot_settings`

2. **FR-022: Audit Trail**
   - Create `FlowAuditLog` model and migration
   - Add logging in `FlowController` update/delete methods

3. **FR-023: Credential Encryption**
   - Add `encrypted` cast to Bot model

### Phase 5: Testing & Documentation (Week 5-6)

**Final phase:**

1. **FR-026: OpenAPI Documentation**
   - Install `darkaonline/l5-swagger`
   - Add annotations to controllers
   - Generate at `/api/documentation`

2. **FR-027, FR-028, FR-029: Tests**
   - Write integration tests for webhooks
   - Write unit tests for new components
   - Write E2E tests for critical flows

---

## Key Files Reference

### Backend

| Purpose | File Path |
|---------|-----------|
| Bot CRUD | `backend/app/Http/Controllers/Api/BotController.php` |
| Bot Settings | `backend/app/Http/Controllers/Api/BotSettingController.php` |
| Flows | `backend/app/Http/Controllers/Api/FlowController.php` |
| Admins | `backend/app/Http/Controllers/Api/AdminController.php` |
| LINE Webhook | `backend/app/Http/Controllers/Webhook/LINEWebhookController.php` |
| Telegram Webhook | `backend/app/Http/Controllers/Webhook/TelegramWebhookController.php` |
| Bot Resource | `backend/app/Http/Resources/BotResource.php` |
| Bot Model | `backend/app/Models/Bot.php` |
| BotSetting Model | `backend/app/Models/BotSetting.php` |
| Flow Model | `backend/app/Models/Flow.php` |

### Frontend

| Purpose | File Path |
|---------|-----------|
| Bots List | `frontend/src/pages/BotsPage.tsx` |
| Bot Settings | `frontend/src/pages/BotSettingsPage.tsx` |
| Flow Editor | `frontend/src/pages/FlowEditorPage.tsx` |
| Connection Edit | `frontend/src/pages/EditConnectionPage.tsx` |
| Connections Hook | `frontend/src/hooks/useConnections.ts` |
| Bot Settings Hook | `frontend/src/hooks/useBotSettings.ts` |
| Flows Hook | `frontend/src/hooks/useFlows.ts` |

---

## Testing Commands

```bash
# Backend tests
cd backend
php artisan test                           # All tests
php artisan test --filter BotController    # Specific test
php artisan test --filter Feature          # Feature tests only

# Frontend tests (when configured)
cd frontend
npm run test                               # All tests
npm run test:coverage                      # With coverage

# E2E tests (when configured)
npx playwright test
```

---

## API Contracts

OpenAPI specifications are in `specs/006-bots-refactor/contracts/`:

- `bots-api.yaml` - Bot CRUD, credentials, status
- `bot-settings-api.yaml` - Settings, flows, admins
- `webhooks-api.yaml` - LINE, Telegram, Facebook webhooks

---

## Common Issues & Solutions

### 1. N+1 Query Still Occurring

**Check**: Use Laravel Debugbar or `DB::enableQueryLog()` to verify query count.

```php
// Bad
foreach ($admins as $admin) {
    $count = $admin->conversations()->count();
}

// Good
$admins = AdminBotAssignment::withCount(['conversations' => fn($q) =>
    $q->where('status', 'active')
])->get();
```

### 2. Credential Leaking in Response

**Check**: Verify BotResource masks credentials for non-owners.

```php
'channel_access_token' => $this->when(
    $request->user()?->id === $this->user_id,
    fn() => $this->channel_access_token ? '••••••••' : null
),
```

### 3. Race Condition on setDefault

**Check**: Ensure transaction with locking is used.

```php
DB::transaction(function () use ($bot, $flow) {
    Flow::where('bot_id', $bot->id)
        ->lockForUpdate()
        ->update(['is_default' => false]);

    $flow->update(['is_default' => true]);
    $bot->update(['default_flow_id' => $flow->id]);
});
```

---

## Next Steps

1. Review `spec.md` for full requirements
2. Check `research.md` for technical decisions
3. Reference `data-model.md` for database changes
4. Use `contracts/` for API implementation

For questions, refer to the main project documentation or contact the team lead.
