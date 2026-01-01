# Lessons Learned - BotFacebook

> สร้างจากความจำ claude-mem โดยอัตโนมัติ (Jan 1, 2026)
> ผมวิเคราะห์จาก observations ทั้งหมดแล้วสรุปมาให้

---

## ความผิดพลาดที่ห้ามทำซ้ำ

### 1. Migration & Database

| ผิดพลาด | วิธีแก้ | อ้างอิง |
|---------|--------|---------|
| ลืม import DB facade | ต้อง `use Illuminate\Support\Facades\DB;` เสมอ | #1375 |
| ไม่เช็ค column ก่อน add | ใช้ `Schema::hasColumn()` ก่อน | #11264 |
| Migration ไม่ idempotent | ต้องเช็คว่า table/column มีอยู่แล้วหรือไม่ | #11267 |

### 2. Production & Railway

| ผิดพลาด | วิธีแก้ | อ้างอิง |
|---------|--------|---------|
| ใช้ cURL ตรงๆ → 500 error | ใช้ Laravel HTTP client แทน | #3920 |
| Guess error โดยไม่ดู logs | **ต้องดู actual logs ก่อนเสมอ** | #7220 |
| Redeploy แบบ interactive | ใช้ non-interactive mode | #11258 |

### 3. Frontend & TypeScript

| ผิดพลาด | วิธีแก้ | อ้างอิง |
|---------|--------|---------|
| ไม่ run build ก่อน commit | **ต้อง `npm run build` ทุกครั้ง** | #7767 |
| ไม่ log error ใน catch | เพิ่ม `console.error(err)` ก่อน toast | #2542 |
| Unused variables | ลบหรือใช้งานให้ครบ | #7767 |

### 4. Error Handling

| ผิดพลาด | วิธีแก้ | อ้างอิง |
|---------|--------|---------|
| ไม่มี try-catch | ครอบทุก API call ด้วย try-catch | #6601 |
| Error message ไม่ชัด | Log full error + return meaningful message | #6601 |

---



### Auto-synced (1/1/2569 09:18:17)

| ผิดพลาด | วิธีแก้ | อ้างอิง |
|---------|--------|---------|
| GitHub Issue Creation Failed Due to Missing Labels | Phase 1 Epic issue creation attempted bu | #749 |
| Laravel Server Started Successfully Despite Health | Server running on port 8000 but curl hea | #946 |
| Laravel Routes Listing Failed Due to Missing PHP i | Route inspection command failed with ENO | #949 |
| Laravel-Boost Official Plugin Disabled in Settings | Disabled laravel-boost@claude-plugins-of | #984 |
| Redis Facade Class Not Found Error | Attempted Redis connection test failed d | #1125 |
| Redis Connection Successfully Verified with PONG R | Laravel application successfully connect | #1128 |
| Redis Database Selection Error Detected | Upstash Redis connection fails because L | #1132 |
| Fixed Redis Cache Database Index for Upstash Compa | Changed REDIS_CACHE_DB default from 1 to | #1133 |
| Fixed type assertion in React Query retry logic | Changed error type casting to use option | #1219 |
| Added database driver check for pgvector extension | Migration now only attempts to create pg | #1360 |



### Auto-synced (1/1/2569 09:25:59)

| ผิดพลาด | วิธีแก้ | อ้างอิง |
|---------|--------|---------|
| Added database driver check for pgvector extension | Migration down() method now only attempt | #1362 |
| Added missing DB facade import to messages migrati | Import statement for Illuminate\Support\ | #1367 |
| Wrapped HNSW index creation in PostgreSQL driver c | HNSW vector index creation now only exec | #1369 |
| Added PostgreSQL check to HNSW index drop in migra | Index drop statement in down() method no | #1372 |
| Added missing DB facade import to documents migrat | Import statement for Illuminate\Support\ | #1375 |
| Added PostgreSQL driver check for HNSW index creat | Wrapped HNSW vector index creation in dr | #1379 |
| Added PostgreSQL driver check for HNSW index drop  | Wrapped HNSW index drop statement in dow | #1380 |
| Added AuthorizesRequests trait to base Controller | Imported and applied AuthorizesRequests  | #1381 |
| BotApiTest suite now passes all 12 tests | All bot API feature tests passing after  | #1382 |
| Customer Profile Creation Field Corrections | Fixed field name and added interaction t | #1476 |



### Auto-synced (1/1/2569 09:26:25)

| ผิดพลาด | วิธีแก้ | อ้างอิง |
|---------|--------|---------|
| LINE Webhook Test Fixed for Non-LINE Bot Handling | Test now correctly expects 404 instead o | #1481 |
| Conversation Factory Channel Type Aligned with Sch | Fixed channel_type enum mismatch between | #1541 |
| Fixed Broadcasting Test Configuration | Broadcasting tests now use log driver in | #1545 |
| Added missing socket_id to broadcasting authorizat | Broadcasting authorization test now incl | #1547 |
| Dev server process killed due to memory constraint | Background dev server restart command te | #1557 |
| Fixed SecurityHeadersTest Base Class | Changed SecurityHeadersTest to extend Te | #1608 |
| Added channel_secret validation in LINE webhook ha | Prevents null pointer errors by checking | #1632 |
| Fixed nested api_keys validation in bot creation r | Added support for nested api_keys format | #1633 |
| Webhook Signature Validation Returns 401 Instead o | Invalid webhook signatures now return 40 | #1636 |
| Fixed TypeScript Type for FILE_ICONS Constant | Changed FILE_ICONS type from JSX.Element | #1883 |

## Preferences ของ User (จากความจำ)

### วิธีทำงานที่ User ชอบ

```
✅ วางแผนก่อนทำ (Plan First)
   └─ "งั้นวางแผนพัฒนามาให้ก่อน" - เห็น 12+ ครั้งในความจำ
   └─ ถามก่อน ไม่ใช่ทำเลย

✅ ใช้ภาษาไทยในการสื่อสาร
   └─ อธิบายเป็นไทย
   └─ Code comment เป็น English ได้

✅ อธิบายให้เข้าใจง่าย
   └─ User บอกว่า "พื้นฐานไม่ค่อยดี แต่พอเข้าใจได้"
   └─ ใช้ diagram, table, emoji ช่วย

✅ ถามเมื่อไม่แน่ใจ
   └─ ไม่เดา ไม่มัว
   └─ ให้ options แล้วให้ user เลือก
```

### สิ่งที่ User ไม่ชอบ

```
❌ ทำเลยโดยไม่วางแผน
   └─ ต้องถาม/วางแผนก่อนเสมอ

❌ เดา error โดยไม่ดู logs
   └─ ต้องดู actual error ก่อน

❌ Over-engineer
   └─ ทำแค่ที่จำเป็น

❌ Technical เกินไป
   └─ อธิบายให้เข้าใจง่าย
```

---

## Patterns ที่ใช้ได้ผล

### 1. Debug Flow (จาก #7220, #3920)

```
Error เกิดขึ้น
    │
    ▼
1. ดู actual logs ก่อน (diagnose → logs)
    │
    ▼
2. หา root cause จริงๆ
    │
    ▼
3. ค้นหา memory ว่าเคยเจอไหม
    │
    ▼
4. แก้ไข + test
    │
    ▼
5. Run build ก่อน commit
```

### 2. Feature Flow (จาก decisions)

```
User ขอ feature
    │
    ▼
1. วางแผนก่อน (Plan Mode)
    │
    ▼
2. ให้ options ถ้ามีหลายทาง
    │
    ▼
3. รอ user approve
    │
    ▼
4. Implement ตาม plan
    │
    ▼
5. Run build + test
```

### 3. Communication Style

```
┌─────────────────────────────────────┐
│  การสื่อสารที่ดี                      │
├─────────────────────────────────────┤
│  • ใช้ภาษาไทย                        │
│  • ใช้ table สรุปข้อมูล               │
│  • ใช้ diagram อธิบาย flow           │
│  • ตอบตรงประเด็น ไม่อ้อมค้อม          │
│  • ถ้าไม่แน่ใจ → ถาม                  │
└─────────────────────────────────────┘
```

---

## Architecture Decisions (จากความจำ)

| Decision | ทำไมเลือก | อ้างอิง |
|----------|----------|---------|
| Three-Model Architecture | ยืดหยุ่นสุด | #8469 |
| Laravel HTTP client | ทำงานบน Railway ได้ดีกว่า cURL | #3920 |
| Plan before implement | ป้องกันทำผิดทาง | #4322, #4785 |

---

## Quick Reference

```
ก่อนแก้ bug:     diagnose → logs → ค้นหา memory → แก้
ก่อน implement:  วางแผน → ถาม user → approve → ทำ
ก่อน commit:     npm run build → ต้องผ่าน
ก่อน deploy:     test locally → push → redeploy
```

---

*อัพเดทล่าสุด: Auto-synced 1/1/2569 09:26:25*
