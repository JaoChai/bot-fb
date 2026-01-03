---
name: bug-workflow
description: Mandatory bug fixing workflow. Use when user reports a bug, error, or issue. Enforces ISSUE → FIX → LOG steps. Must complete all steps before proceeding.
---

# Bug Workflow (Mandatory)

## STOP! ทุกครั้งที่เจอ Bug ต้องทำตามนี้

```
┌─────────────────────────────────────────────────────────┐
│  ISSUE → FIX → LOG                                      │
│  ข้ามขั้นตอนไหนไม่ได้!                                   │
└─────────────────────────────────────────────────────────┘
```

---

## Phase 1: ISSUE (ก่อน Fix)

### Step 1.1: Memory Search
```bash
# ค้นหา bug คล้ายกันใน memory
mem-search "[GOTCHA] <keyword>"
mem-search "[MISTAKE] <keyword>"
```
⚠️ **Memory = HINT เท่านั้น ต้อง verify ด้วย realtime data!**

### Step 1.2: Verify with Realtime Data
```bash
# ดู logs จริง
diagnose logs
diagnose backend

# อ่าน code ปัจจุบัน
Read <file>
Grep <pattern>
```

**Data Reliability:**
| Source | Reliability |
|--------|-------------|
| diagnose logs | ✅ Source of Truth |
| railway_logs | ✅ Source of Truth |
| Read file | ✅ Source of Truth |
| mem-search | ⚠️ HINT only - verify! |

### Step 1.3: Create GitHub Issue
```bash
gh issue create \
  --title "Bug: <description>" \
  --body "## Root Cause
<verified cause from logs/code>

## Fix Plan
- File: <path>
- Change: <what to change>

## Confidence: XX%

## Verified With
- [ ] Checked logs
- [ ] Read current code
- [ ] Confirmed root cause"
```

### Step 1.4: Self-Check
ก่อนถาม user ต้องตอบคำถามนี้:
- [ ] Root cause ถูกต้อง? (มี evidence จาก logs/code)
- [ ] Plan สมเหตุสมผล? (ไม่ over-engineer)
- [ ] Confidence >= 80%?

❌ ถ้าไม่แน่ใจ → กลับไป Step 1.2
✅ ถ้าแน่ใจ → ถาม user approve

### Step 1.5: Ask User Approval
```
ผมจะแก้ bug นี้:
- Issue: #XX
- Root cause: <cause>
- Fix: <plan>
- Confidence: XX%

อนุญาตให้ดำเนินการไหมครับ?
```

---

## Phase 2: FIX (หลัง Approve)

### Step 2.1: Execute Fix
- แก้ตาม plan ที่ approved เท่านั้น
- ไม่เพิ่ม/ลด scope
- Commit message: `fix: <description> (Fixes #XX)`

---

## Phase 3: LOG (หลัง Fix)

### Step 3.1: Verify Fix Works
```bash
# Test ว่า fix จริง
npm run build  # ถ้า frontend
php artisan test  # ถ้า backend

# หรือ test manually
curl <endpoint>
```
⚠️ **Deploy ≠ Done! ต้อง verify!**

### Step 3.2: Record Learning
```
[GOTCHA][PROJECT:BotFacebook]
<what was the trap>
<how to avoid next time>
```

### Step 3.3: Close Issue
```bash
gh issue close <number> --comment "Fixed in commit <hash>"
```

---

## Failure Rules

```
Fix ไม่สำเร็จ 1 ครั้ง → กลับไป Step 1.2 (verify ใหม่)
Fix ไม่สำเร็จ 2 ครั้ง → STOP! ถาม user
```

---

## Quick Checklist

Before any Edit:
- [ ] Issue created? (ID: ___)
- [ ] Realtime data verified?
- [ ] Confidence >= 80%?
- [ ] User approved?

After fix:
- [ ] Verified fix works?
- [ ] Learning recorded?
- [ ] Issue closed?
