# Research: Bots Page Refactoring

**Date**: 2026-01-09
**Feature**: 006-bots-refactor

## Research Summary

This document captures research findings for technical decisions in the Bots Page refactoring project.

---

## 1. Credential Security (FR-001, FR-002, FR-023)

### Decision: Conditional Credential Visibility + At-Rest Encryption

**Rationale**:
- BotResource should conditionally hide credentials based on requesting user
- Use Laravel's `Crypt` facade for at-rest encryption in database
- Owner can reveal credentials through explicit API endpoint

**Implementation Approach**:
```php
// BotResource.php
'channel_access_token' => $this->when(
    $request->user()?->id === $this->user_id,
    fn() => $this->channel_access_token ? '••••••••' : null
),
```

**Alternatives Considered**:
1. Never expose credentials (rejected: owner needs to verify/copy)
2. Always expose to owner (rejected: accidental exposure risk)
3. Separate credential management UI (rejected: over-engineering)

---

## 2. N+1 Query Fix (FR-004)

### Decision: Use withCount() for Active Conversations

**Rationale**:
- Laravel's `withCount()` generates efficient subquery
- Single query instead of N+1
- Works with existing AdminBotAssignment model

**Implementation Approach**:
```php
// AdminController.php
$admins = $bot->adminAssignments()
    ->with('user')
    ->withCount(['user.conversations as active_conversations_count' => function ($q) use ($bot) {
        $q->where('bot_id', $bot->id)->where('status', 'active');
    }])
    ->paginate();
```

**Alternatives Considered**:
1. Raw SQL (rejected: loses Eloquent benefits)
2. Eager loading all conversations (rejected: memory overhead)
3. Cache counts (rejected: stale data concerns)

---

## 3. Race Condition Prevention (FR-003)

### Decision: Database Transaction + Row Locking

**Rationale**:
- Use `DB::transaction()` with `lockForUpdate()`
- Ensures atomic operation for setDefault()
- PostgreSQL handles concurrent access gracefully

**Implementation Approach**:
```php
DB::transaction(function () use ($bot, $flow) {
    // Lock all flows for this bot
    Flow::where('bot_id', $bot->id)
        ->lockForUpdate()
        ->update(['is_default' => false]);

    $flow->update(['is_default' => true]);
    $bot->update(['default_flow_id' => $flow->id]);
});
```

**Alternatives Considered**:
1. Advisory locks (rejected: more complex, less portable)
2. Optimistic locking with version (rejected: requires schema change)
3. Queue-based serialization (rejected: adds latency)

---

## 4. Facebook Graph API Integration (FR-008, FR-009, FR-010)

### Decision: Use Facebook Graph API v18+ with Webhook Subscription

**Rationale**:
- Graph API v18 is current stable version
- Messenger API supports text, media, templates
- Similar pattern to existing LINE/Telegram implementation

**Key Endpoints**:
- POST `/{page-id}/messages` - Send message
- GET `/{user-id}` - Get user profile
- Webhook events: `messaging`, `messaging_postbacks`

**Required Credentials**:
- Page Access Token (long-lived)
- App Secret (for signature verification)
- Page ID

**Implementation Pattern**:
```php
class FacebookService {
    public function sendMessage(Bot $bot, string $recipientId, array $message);
    public function getProfile(Bot $bot, string $userId);
    public function validateSignature(string $payload, string $signature);
}
```

**Alternatives Considered**:
1. Third-party SDK (rejected: adds dependency, existing HTTP pattern works)
2. Instagram integration (rejected: out of scope per spec)

---

## 5. Frontend Component Split Strategy (FR-015, FR-016)

### Decision: Extract to Separate Files with Shared Types

**Rationale**:
- Each settings section becomes independent component
- Parent page handles form state and submission
- Sections receive formData and onChange callback

**Component Interface Pattern**:
```typescript
interface SectionProps {
  formData: BotSettingsFormData;
  onChange: (key: keyof BotSettingsFormData, value: any) => void;
  disabled?: boolean;
}

// Usage in BotSettingsPage
<RateLimitSection
  formData={formData}
  onChange={handleChange}
  disabled={isSubmitting}
/>
```

**File Organization**:
```
components/bot-settings/
├── index.ts              # Re-exports all sections
├── types.ts              # Shared types
├── RateLimitSection.tsx
├── HITLSection.tsx
└── ...
```

**Alternatives Considered**:
1. Keep in single file with regions (rejected: still 900+ lines)
2. Extract to separate files with local state (rejected: complicates form handling)
3. Use form library (react-hook-form) (rejected: adds learning curve, works with current pattern)

---

## 6. Database Table Split Strategy (FR-021)

### Decision: Create Related Tables with HasOne Relationships

**Rationale**:
- Split 50+ columns into domain-focused tables
- BotSetting keeps common fields
- Sub-tables: bot_limits, bot_hitl_settings, bot_aggregation_settings, bot_response_hours
- Laravel HasOne relationships for seamless access

**Migration Strategy**:
1. Create new tables with foreign keys
2. Migrate data from existing columns
3. Add relationships to BotSetting model
4. Update BotSettingController to handle sub-relations
5. Eventually drop old columns (separate migration)

**Relationships**:
```php
class BotSetting extends Model {
    public function limits(): HasOne { return $this->hasOne(BotLimits::class); }
    public function hitlSettings(): HasOne { return $this->hasOne(BotHITLSettings::class); }
    // ...
}
```

**Alternatives Considered**:
1. JSON columns (rejected: loses query/index capability)
2. Keep single table (rejected: already 50+ columns, unmaintainable)
3. Separate services per domain (rejected: over-engineering for current scale)

---

## 7. OpenAPI Documentation (FR-026)

### Decision: Use L5-Swagger (darkaonline/l5-swagger)

**Rationale**:
- Well-maintained Laravel package
- Annotation-based documentation
- Auto-generates Swagger UI
- Already common in Laravel ecosystem

**Implementation Approach**:
1. Install via composer
2. Add annotations to controllers
3. Generate spec with `php artisan l5-swagger:generate`
4. Serve at `/api/documentation`

**Example Annotation**:
```php
/**
 * @OA\Get(
 *     path="/api/bots",
 *     summary="List all bots",
 *     @OA\Response(response=200, description="Success")
 * )
 */
```

**Alternatives Considered**:
1. Manual OpenAPI YAML (rejected: drift from implementation)
2. API Platform (rejected: overkill for this project)
3. Scribe (rejected: L5-Swagger more widely used)

---

## 8. LINE Auto Webhook Setup (FR-014)

### Decision: Use LINE Messaging API Webhook Endpoint Management

**Rationale**:
- LINE provides API to set/get webhook URL programmatically
- Similar to current Telegram implementation
- PUT to `https://api.line.me/v2/bot/channel/webhook/endpoint`

**Implementation**:
```php
class LINEService {
    public function setWebhook(Bot $bot, string $webhookUrl): bool {
        return Http::withToken($bot->channel_access_token)
            ->put('https://api.line.me/v2/bot/channel/webhook/endpoint', [
                'endpoint' => $webhookUrl
            ])
            ->successful();
    }
}
```

**Alternatives Considered**:
1. Manual setup only (rejected: UX inconsistency with Telegram)
2. Deep link to LINE console (rejected: extra step for user)

---

## 9. Feature Decisions: Plugins & External Data Sources (FR-011, FR-012, FR-013)

### Decision: Remove UI Until Backend Ready

**Rationale**:
- webhook_forwarder_enabled has no implementation
- Plugins and External Data Sources are UI stubs
- Better to remove broken UI than confuse users

**Action Items**:
1. Remove webhook_forwarder toggle from EditConnectionPage
2. Remove Plugins section from FlowEditorPage
3. Remove External Data Sources section from FlowEditorPage
4. Keep database columns for future implementation
5. Create separate feature spec for these when ready

**Alternatives Considered**:
1. Implement all features now (rejected: scope creep, focus on core refactor)
2. Hide behind feature flag (rejected: adds complexity for unused features)

---

## 10. Testing Strategy (FR-027, FR-028, FR-029)

### Decision: Layered Testing Approach

**Backend Testing (PHPUnit)**:
- Unit: Services, Models, Validators
- Feature: API endpoints, Webhook processing
- Integration: Database transactions, Queue jobs

**Frontend Testing (Vitest)**:
- Unit: Individual settings sections
- Integration: Form submission flows

**E2E Testing (Playwright)**:
- Critical user journeys only
- Create bot → Configure settings → Test connection → Activate

**Coverage Targets**:
- New code: 80%
- Refactored code: 60%
- Critical paths: 100%

---

## Research Completion

All NEEDS CLARIFICATION items resolved:
- [x] Credential security approach
- [x] N+1 query fix strategy
- [x] Race condition prevention
- [x] Facebook API version and endpoints
- [x] Component split pattern
- [x] Table split strategy
- [x] OpenAPI documentation tool
- [x] LINE webhook auto-setup
- [x] Incomplete features handling
- [x] Testing approach

**Next Step**: Proceed to Phase 1 (data-model.md, contracts/)
