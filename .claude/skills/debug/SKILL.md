---
name: debug
description: Enforced debug workflow with state tracking. BLOCKS Edit/Write until all steps complete. Use when user reports bug, error, 500, crash, or any issue.
---

# Debug Workflow (Enforced)

## Trigger

Use when: error, bug, fix, crash, 500, 404, ล่ม, แก้, ปัญหา

```
┌─────────────────────────────────────────────────────────────────┐
│  ⚠️  ENFORCED WORKFLOW - PreToolUse Hook BLOCKS Edit/Write     │
│      until all steps completed!                                 │
│                                                                 │
│  DISCOVERY → DIAGNOSIS → PROCESS                                │
│  ข้ามขั้นตอนไหนไม่ได้! Hook จะ block!                             │
└─────────────────────────────────────────────────────────────────┘
```

## State Management

```bash
# ดู status ปัจจุบัน
node .claude/scripts/update-debug-state.js status

# Commands ทั้งหมด
node .claude/scripts/update-debug-state.js help
```

---

## Phase 1: DISCOVERY (ค้นหาก่อน)

### Step 1.0: Start Debug Session
```bash
# เริ่ม session ใหม่ (บังคับ!)
node .claude/scripts/update-debug-state.js start "<bug description>"
```

### Step 1.1: Search Memory
```bash
# ค้นหาว่าเคยเจอปัญหานี้ไหม
mem-search "[GOTCHA] <keyword>"
mem-search "[MISTAKE] <keyword>"
search(query="<error>", obs_type="bugfix")

# Mark as done
node .claude/scripts/update-debug-state.js discovery.memory_searched
```

### Step 1.2: Check Timeline (ถ้าเจอ)
```bash
timeline(anchor=<obs_id>, depth_before=3, depth_after=3)
get_observations(ids=[<relevant_ids>])

# ถ้าเจอ similar issue
node .claude/scripts/update-debug-state.js discovery.similar_found <obs_id>
```

### Step 1.3: Decision
| Found? | Action |
|--------|--------|
| Exact match | ใช้ solution เดิม → Adapt for current case |
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

# Mark as done
node .claude/scripts/update-debug-state.js diagnosis.logs_checked

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

### Step 2.3: Set Root Cause (with evidence)
```bash
# Add evidence
node .claude/scripts/update-debug-state.js diagnosis.add_evidence "logs show: XYZ error"
node .claude/scripts/update-debug-state.js diagnosis.add_evidence "config returns null"

# Set root cause
node .claude/scripts/update-debug-state.js diagnosis.root_cause "Config null trap in ServiceX"
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
- [x] Checked logs
- [x] Read current code
- [x] Confirmed root cause"

# Get issue number from output, then:
node .claude/scripts/update-debug-state.js process.issue "#123" "https://github.com/..."
```

### Step 3.2: Set Confidence
```bash
# ต้อง >= 80% ถึงจะ Edit/Write ได้!
node .claude/scripts/update-debug-state.js process.confidence 85
```

### Step 3.3: Ask User Approval
```
ผมจะแก้ bug นี้:
- Issue: #XX
- Root cause: <cause>
- Fix: <plan>
- Confidence: XX%

อนุญาตให้ดำเนินการไหมครับ?
```

```bash
# หลัง user approve
node .claude/scripts/update-debug-state.js process.approved
```

### Step 3.4: Execute Fix
```bash
# ตอนนี้ Edit/Write จะทำงานได้แล้ว!
# แก้ตาม plan ที่ approved เท่านั้น
# ไม่เพิ่ม/ลด scope

# หลังแก้เสร็จ
node .claude/scripts/update-debug-state.js process.fixed

# Commit
git commit -m "fix: <description> (Fixes #XX)"
```

### Step 3.5: Verify Fix Works
```bash
npm run build      # Frontend
php artisan test   # Backend
curl <endpoint>    # Manual test

# หลัง verify
node .claude/scripts/update-debug-state.js process.verified
```

⚠️ **Deploy ≠ Done! ต้อง verify!**

### Step 3.6: Record Learning
```bash
# บันทึกใน memory
[GOTCHA][PROJECT:BotFacebook]
<what was the trap>
<how to avoid next time>

# Mark as done
node .claude/scripts/update-debug-state.js process.learned
```

### Step 3.7: Close Issue & Complete
```bash
gh issue close <number> --comment "Fixed in commit <hash>"
node .claude/scripts/update-debug-state.js process.closed

# Complete session (resets state)
node .claude/scripts/update-debug-state.js complete
```

---

## Safety Rules

```
┌─────────────────────────────────────────────────────────────────┐
│  1. Memory = HINT, ต้อง verify ด้วย realtime                     │
│  2. Confidence >= 80% ก่อน fix (enforced by hook!)               │
│  3. Fix ไม่สำเร็จ 2 ครั้ง → STOP → ถาม user                        │
│  4. ต้อง user approve ก่อนแก้ (enforced by hook!)                 │
│  5. Edit/Write BLOCKED จนกว่าจะผ่านทุกขั้นตอน!                     │
└─────────────────────────────────────────────────────────────────┘
```

---

## Quick Reference

```bash
# Start
node .claude/scripts/update-debug-state.js start "bug description"

# Discovery
node .claude/scripts/update-debug-state.js discovery.memory_searched

# Diagnosis
node .claude/scripts/update-debug-state.js diagnosis.logs_checked
node .claude/scripts/update-debug-state.js diagnosis.root_cause "cause"

# Process
node .claude/scripts/update-debug-state.js process.issue "#123"
node .claude/scripts/update-debug-state.js process.confidence 85
node .claude/scripts/update-debug-state.js process.approved
node .claude/scripts/update-debug-state.js process.fixed
node .claude/scripts/update-debug-state.js process.verified
node .claude/scripts/update-debug-state.js process.learned
node .claude/scripts/update-debug-state.js complete

# Check status anytime
node .claude/scripts/update-debug-state.js status

# Emergency reset
node .claude/scripts/update-debug-state.js reset
```

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
