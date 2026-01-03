---
name: debug
description: Unified debug workflow combining discovery, diagnosis, and process. Use when user reports bug, error, 500, crash, or any issue. Enforces DISCOVERY → DIAGNOSIS → PROCESS steps.
---

# Debug Workflow (Unified)

## Trigger

Use when: error, bug, fix, crash, 500, 404, ล่ม, แก้, ปัญหา

```
┌─────────────────────────────────────────────────────────┐
│  DISCOVERY → DIAGNOSIS → PROCESS                       │
│  ข้ามขั้นตอนไหนไม่ได้!                                   │
└─────────────────────────────────────────────────────────┘
```

---

## Phase 1: DISCOVERY (ค้นหาก่อน)

### Step 1.1: Search Memory
```bash
# ค้นหาว่าเคยเจอปัญหานี้ไหม
mem-search "[GOTCHA] <keyword>"
mem-search "[MISTAKE] <keyword>"
search(query="<error>", obs_type="bugfix")
```

### Step 1.2: Check Timeline (ถ้าเจอ)
```bash
timeline(anchor=<obs_id>, depth_before=3, depth_after=3)
get_observations(ids=[<relevant_ids>])
```

### Step 1.3: Decision
| Found? | Action |
|--------|--------|
| Exact match | ใช้ solution เดิม → Skip to Phase 3 |
| Similar | Adapt solution → Continue Phase 2 |
| Not found | Diagnose ใหม่ → Continue Phase 2 |

⚠️ **Memory = HINT only! ต้อง verify ด้วย realtime data!**

---

## Phase 2: DIAGNOSIS (วิเคราะห์จริง)

### Step 2.1: Get ACTUAL Error
```bash
# Check logs first
diagnose logs
diagnose backend

# Laravel specific
curl https://api.botjao.com/api/health
php artisan tinker
>>> app(Service::class)
```

### Step 2.2: Identify Location
| Error Type | Check |
|-----------|-------|
| DI/Service | `app(ServiceClass::class)` |
| Controller | Stack trace |
| Config | `config('key')` returns null? |
| Database | Connection/query error |
| Frontend | Browser console, React Query |

### Step 2.3: Test Hypothesis
```bash
# ไม่เดา! Verify ก่อน
php artisan tinker
>>> config('services.key')  # Check null
>>> app(Service::class)     # Test instantiation
```

**Data Reliability:**
| Source | Reliability |
|--------|-------------|
| diagnose logs | ✅ Source of Truth |
| railway_logs | ✅ Source of Truth |
| Read file | ✅ Source of Truth |
| curl response | ✅ Source of Truth |
| mem-search | ⚠️ HINT only - verify! |

---

## Phase 3: PROCESS (ทำตามขั้นตอน)

### Step 3.1: Create GitHub Issue
```bash
gh issue create \
  --title "Bug: <description>" \
  --body "## Root Cause
<verified cause from logs/code>

## Fix Plan
- File: <path>
- Change: <what>

## Confidence: XX%

## Verified With
- [ ] Checked logs
- [ ] Read current code
- [ ] Confirmed root cause"
```

### Step 3.2: Self-Check
Before asking user:
- [ ] Root cause ถูกต้อง? (มี evidence)
- [ ] Plan สมเหตุสมผล? (ไม่ over-engineer)
- [ ] Confidence >= 80%?

❌ ไม่แน่ใจ → กลับ Phase 2
✅ แน่ใจ → ถาม user

### Step 3.3: Ask User Approval
```
ผมจะแก้ bug นี้:
- Issue: #XX
- Root cause: <cause>
- Fix: <plan>
- Confidence: XX%

อนุญาตให้ดำเนินการไหมครับ?
```

### Step 3.4: Execute Fix
- แก้ตาม plan ที่ approved เท่านั้น
- ไม่เพิ่ม/ลด scope
- Commit: `fix: <description> (Fixes #XX)`

### Step 3.5: Verify Fix Works
```bash
npm run build      # Frontend
php artisan test   # Backend
curl <endpoint>    # Manual test
```
⚠️ **Deploy ≠ Done! ต้อง verify!**

### Step 3.6: Record Learning
```
[GOTCHA][PROJECT:BotFacebook]
<what was the trap>
<how to avoid next time>
```

### Step 3.7: Close Issue
```bash
gh issue close <number> --comment "Fixed in commit <hash>"
```

---

## Safety Rules

```
┌─────────────────────────────────────────────────────────┐
│  1. Memory = HINT, ต้อง verify ด้วย realtime            │
│  2. Confidence >= 80% ก่อน fix                          │
│  3. Fix ไม่สำเร็จ 2 ครั้ง → STOP → ถาม user              │
│  4. ต้อง user approve ก่อนแก้                           │
└─────────────────────────────────────────────────────────┘
```

---

## Quick Checklist

**Before any fix:**
- [ ] Memory searched?
- [ ] Realtime data verified?
- [ ] Issue created? (ID: ___)
- [ ] Confidence >= 80%?
- [ ] User approved?

**After fix:**
- [ ] Verified fix works?
- [ ] Learning recorded?
- [ ] Issue closed?

---

## Common Patterns (Laravel)

### Config null trap
```php
// BAD
$key = config('services.key', '');

// GOOD
$key = config('services.key') ?? '';
```

### React Query cache
```typescript
// Real-time data needs staleTime: 0
staleTime: 0,  // Always refetch
```
