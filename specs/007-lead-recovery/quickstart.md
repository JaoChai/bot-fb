# Quickstart: Lead Recovery

**Feature**: 007-lead-recovery
**Date**: 2026-01-12

## Overview

Lead Recovery ติดตามลูกค้าอัตโนมัติเมื่อบทสนทนาเงียบ รองรับ Static mode (ข้อความกำหนดเอง) และ AI mode (สร้างจาก System Prompt + context)

---

## Quick Setup (Development)

### 1. Run Migrations

```bash
cd backend
php artisan migrate
```

### 2. Enable Lead Recovery for a Bot

```php
// Via API
PATCH /api/bots/{botId}/settings
{
    "hitl_settings": {
        "lead_recovery_enabled": true,
        "lead_recovery_timeout_hours": 4,
        "lead_recovery_mode": "static",
        "lead_recovery_message": "สวัสดีค่ะ ยังสนใจอยู่ไหมคะ?",
        "lead_recovery_max_attempts": 2
    }
}
```

### 3. Test the Scheduler Locally

```bash
# Run the job manually
php artisan schedule:test --name="lead-recovery"

# Or dispatch directly
php artisan tinker
>>> App\Jobs\ProcessLeadRecovery::dispatch();
```

### 4. Check Logs

```bash
tail -f storage/logs/laravel.log | grep LeadRecovery
```

---

## Configuration Options

| Setting | Default | Range | Description |
|---------|---------|-------|-------------|
| `lead_recovery_enabled` | false | - | Master toggle |
| `lead_recovery_timeout_hours` | 4 | 1-72 | Hours before follow-up |
| `lead_recovery_mode` | 'static' | static, ai | Message mode |
| `lead_recovery_message` | null | - | Custom message (static mode) |
| `lead_recovery_max_attempts` | 2 | 1-5 | Max follow-ups |

---

## How It Works

### Flow Diagram

```
┌─────────────────┐
│   Scheduler     │
│  (every hour)   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Find bots with  │
│ lead_recovery   │
│ enabled         │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ For each bot:   │
│ find inactive   │
│ conversations   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Check:          │
│ - Not HITL mode │
│ - Within hours  │
│ - < max attempts│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Generate msg:   │
│ Static or AI    │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Send via        │
│ LINE/TG/FB      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Log attempt     │
│ Update conv     │
└─────────────────┘
```

### Eligibility Criteria

A conversation is eligible for recovery when:
1. Bot has `lead_recovery_enabled = true`
2. Conversation `status = 'active'`
3. Conversation `is_handover = false` (not in HITL mode)
4. `last_message_at < now - timeout_hours`
5. `recovery_attempts < max_attempts`
6. `last_recovery_at` is null OR > 24 hours ago
7. Current time is within Response Hours (if enabled)

---

## Testing

### Unit Test Example

```php
public function test_finds_inactive_conversations()
{
    $bot = Bot::factory()->create();
    $bot->setting->hitlSettings->update([
        'lead_recovery_enabled' => true,
        'lead_recovery_timeout_hours' => 4,
    ]);

    // Active conversation, inactive for 5 hours
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'status' => 'active',
        'is_handover' => false,
        'last_message_at' => now()->subHours(5),
    ]);

    $service = new LeadRecoveryService();
    $eligible = $service->findEligibleConversations($bot);

    $this->assertCount(1, $eligible);
}
```

### Manual Testing

1. Create a test bot with Lead Recovery enabled
2. Start a conversation and wait for timeout
3. Check that follow-up message is sent
4. Reply as customer and verify tracking

---

## Monitoring

### Key Metrics

- `lead_recovery.sent` - Follow-ups sent per hour
- `lead_recovery.failed` - Failed deliveries
- `lead_recovery.response_rate` - Customer response rate

### Common Issues

| Issue | Solution |
|-------|----------|
| Messages not sending | Check scheduler is running, bot has valid credentials |
| AI mode fallback | Verify bot has default flow with system prompt |
| Duplicate messages | Check last_recovery_at, 24-hour cooldown |

---

## API Examples

### Get Statistics

```bash
curl -X GET "https://api.botjao.com/api/bots/123/lead-recovery/stats?period=week" \
  -H "Authorization: Bearer {token}"
```

Response:
```json
{
  "data": {
    "period": "week",
    "total_sent": 45,
    "total_responded": 18,
    "response_rate": 40.0,
    "by_mode": {
      "static": { "sent": 30, "responded": 10 },
      "ai": { "sent": 15, "responded": 8 }
    }
  }
}
```

### Get Logs

```bash
curl -X GET "https://api.botjao.com/api/bots/123/lead-recovery/logs?page=1" \
  -H "Authorization: Bearer {token}"
```

---

## Frontend Integration

### Settings Component

The LeadRecoverySection component is integrated into HITLSettingsSection:

```tsx
// When lead_recovery_enabled is ON, show expanded settings:
<div className="space-y-4 pl-4 border-l-2">
  <Select label="Timeout" options={[4, 6, 12, 24, 48, 72]} />
  <RadioGroup label="Mode" options={['static', 'ai']} />
  {mode === 'static' && <Textarea label="Message" />}
  {mode === 'ai' && <FlowSelector label="Use Flow" />}
  <Select label="Max Attempts" options={[1, 2, 3, 4, 5]} />
</div>
```

---

## Production Checklist

- [ ] Migrations run on production
- [ ] Scheduler configured in Railway
- [ ] OpenRouter API key set for AI mode
- [ ] Rate limits configured appropriately
- [ ] Monitoring alerts set up
- [ ] Cost tracking enabled for AI calls
