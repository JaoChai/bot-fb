# Quickstart: Second AI for Improvement

**Feature**: 002-second-ai-improvement
**Time to implement**: ~4-6 hours

## Overview

เพิ่ม AI ตัวที่สองเพื่อตรวจสอบและปรับปรุง response จาก Primary AI ก่อนส่งกลับ user

## Prerequisites

- [ ] Laravel backend running
- [ ] React frontend running
- [ ] PostgreSQL database connected
- [ ] OpenRouter API key configured

## Implementation Order

### Step 1: Database Migration (15 min)

```bash
# Create migration
php artisan make:migration add_second_ai_columns_to_flows_table

# Edit migration file and run
php artisan migrate
```

Migration content:
```php
Schema::table('flows', function (Blueprint $table) {
    $table->boolean('second_ai_enabled')->default(false);
    $table->jsonb('second_ai_options')->nullable();
});
```

### Step 2: Update Flow Model (10 min)

Add to `Flow.php`:
```php
protected $fillable = [
    // ... existing
    'second_ai_enabled',
    'second_ai_options',
];

protected $casts = [
    // ... existing
    'second_ai_enabled' => 'boolean',
    'second_ai_options' => 'array',
];
```

### Step 3: Update Form Requests (10 min)

Add validation rules to `StoreFlowRequest.php` and `UpdateFlowRequest.php`:
```php
'second_ai_enabled' => 'sometimes|boolean',
'second_ai_options' => 'sometimes|array',
'second_ai_options.fact_check' => 'sometimes|boolean',
'second_ai_options.policy' => 'sometimes|boolean',
'second_ai_options.personality' => 'sometimes|boolean',
```

### Step 4: Update FlowResource (5 min)

Add to response in `FlowResource.php`:
```php
'second_ai_enabled' => $this->second_ai_enabled ?? false,
'second_ai_options' => $this->second_ai_options ?? [
    'fact_check' => false,
    'policy' => false,
    'personality' => false,
],
```

### Step 5: Create SecondAI Services (2-3 hours)

Create directory and services:
```bash
mkdir -p app/Services/SecondAI
```

Files to create:
1. `SecondAIService.php` - Main orchestrator
2. `FactCheckService.php` - KB verification
3. `PolicyCheckService.php` - Policy compliance
4. `PersonalityCheckService.php` - Tone/brand check

### Step 6: Integrate into AIService (30 min)

Modify `AIService.php` to call SecondAIService after RAGService.

### Step 7: Update Frontend (30 min)

Update `FlowEditorPage.tsx` to include second_ai fields in save payload:
```typescript
const handleSave = async () => {
  const data = {
    ...formData,
    second_ai_enabled: agenticSecondAIEnabled,
    second_ai_options: secondAIOptions,
  };
  // ... save
};
```

Load from API response:
```typescript
useEffect(() => {
  if (existingFlow) {
    setAgenticSecondAIEnabled(existingFlow.second_ai_enabled ?? false);
    setSecondAIOptions(existingFlow.second_ai_options ?? {
      fact_check: false,
      policy: false,
      personality: false,
    });
  }
}, [existingFlow]);
```

## Testing

### Manual Testing

1. Open Flow Editor
2. Toggle "Second AI for Improvement" ON
3. Select at least one option (Fact Check, Policy, Personality)
4. Save the flow
5. Reload page - verify settings persist
6. Test chat - observe response quality

### Unit Tests

```bash
# Run backend tests
php artisan test --filter=SecondAI

# Run frontend tests
npm test -- --grep="SecondAI"
```

## Rollback

If issues arise:

```bash
# Rollback migration
php artisan migrate:rollback --step=1

# Or disable feature for specific flow
UPDATE flows SET second_ai_enabled = false WHERE id = X;
```

## Success Criteria

- [ ] Toggle saves to database correctly
- [ ] Options persist after page refresh
- [ ] Chat responses are checked when enabled
- [ ] Fallback works when Second AI fails
- [ ] Latency increase ≤3 seconds
