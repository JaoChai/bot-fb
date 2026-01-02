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

## บทเรียนจากการวิเคราะห์ตัวเอง (2 ม.ค. 2026)

> วิเคราะห์จาก 50 observations ของวันนี้ + ประวัติ 68+ attempts

### 5. WebSocket & Real-time Issues

| ผิดพลาด | วิธีแก้ | อ้างอิง |
|---------|--------|---------|
| แก้ปัญหาเดิม 68+ ครั้ง | **หยุด! ดู memory ก่อน** - หา pattern ของ failures | #13463 |
| Fix symptoms ไม่ใช่ root cause | วิเคราะห์ว่า "ทำไม" ก่อน "ทำอะไร" | #13464 |
| ไม่มี connection state monitoring | ต้องมี fallback เมื่อ WebSocket หลุด | #13486 |
| **ไม่ส่ง X-Socket-ID header** | **ต้องเพิ่มใน API interceptor** - Laravel `->toOthers()` ต้องการ header นี้ | Jan 2, 2026 |
| Race condition: Optimistic + API + WebSocket | เช็คว่า message มีอยู่แล้วก่อน replace/add | Jan 2, 2026 |

**X-Socket-ID Critical Fix (2 ม.ค. 2026):**
```javascript
// api.ts - ต้องส่ง socket ID ไปกับทุก request
api.interceptors.request.use((config) => {
  const echo = getEcho();
  const socketId = echo.socketId();
  if (socketId) {
    config.headers['X-Socket-ID'] = socketId;
  }
  return config;
});
```
ถ้าไม่ส่ง → Laravel's `->toOthers()` ไม่ทำงาน → sender ได้รับ WebSocket event ของตัวเอง → ข้อความซ้ำ!

### 6. Process & Workflow

| ผิดพลาด | วิธีแก้ | อ้างอิง |
|---------|--------|---------|
| ไม่ใช้ TodoWrite | **ใช้ทันที**เมื่อเริ่ม task ใหญ่ | #13494 |
| Session disconnect แล้วหาย | Save state บ่อยๆ ด้วย TodoWrite | #13461 |
| Re-read files ซ้ำซาก | Trust memory - อ่าน observations แทน | #13462 |
| วน research วนไป | Search memory ก่อน grep/read files | #13460 |

### 8. หา Root Cause จริง ห้ามเดา!

```
┌─────────────────────────────────────────────────────────────┐
│  ❌ สิ่งที่ผมเคยทำผิด                                        │
├─────────────────────────────────────────────────────────────┤
│  • เห็น error → เดาว่าน่าจะเป็นเพราะ X → แก้ X → ไม่หาย      │
│  • ไม่ดู logs จริง → สมมติ root cause → แก้ผิดจุด            │
│  • "น่าจะเป็น..." → ลุยแก้เลย → เสียเวลา                     │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  ✅ สิ่งที่ต้องทำ                                            │
├─────────────────────────────────────────────────────────────┤
│  1. ดู actual error/logs ก่อน (ใช้ diagnose → logs)         │
│  2. reproduce ปัญหาให้ได้                                   │
│  3. trace จาก error → หาจุดที่พังจริง                        │
│  4. ยืนยัน root cause แล้วค่อยแก้                            │
│  5. ถ้าไม่แน่ใจ → ถาม user ไม่ใช่เดา                         │
└─────────────────────────────────────────────────────────────┘
```

| ❌ เดา | ✅ เช็คจริง |
|-------|-----------|
| "น่าจะเป็น WebSocket" | ดู console/network logs |
| "คิดว่า API พัง" | เรียก API ดูจริง |
| "เหมือนจะเป็น cache" | clear cache แล้ว test |
| "อาจจะเป็น state" | log state ดูค่าจริง |

### 9. ไม่มั่นใจ → ค้นหาข้อมูลอ้างอิง!

```
┌─────────────────────────────────────────────────────────────┐
│  ❌ ห้ามทำ                                                   │
├─────────────────────────────────────────────────────────────┤
│  • ไม่แน่ใจ → เดาเอา                                        │
│  • จำไม่ได้ → มั่วไป                                         │
│  • ไม่รู้ syntax → ลองดู                                     │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  ✅ ทำแทน                                                    │
├─────────────────────────────────────────────────────────────┤
│  • ไม่แน่ใจ → ค้นหา docs/reference ก่อน                      │
│  • จำไม่ได้ → search memory หรือ WebSearch                  │
│  • ไม่รู้ → ใช้ Context7 ดู docs ล่าสุด                       │
│  • ยังไม่ชัว → ถาม user                                      │
└─────────────────────────────────────────────────────────────┘
```

| เครื่องมือ | ใช้เมื่อ |
|-----------|---------|
| `mem-search` | หาว่าเคยทำอะไรไปแล้ว |
| `Context7` | ดู docs ของ library ล่าสุด |
| `WebSearch` | หาข้อมูลใหม่/best practices |
| `WebFetch` | อ่าน docs จาก URL |
| **ถาม user** | ไม่แน่ใจเรื่อง business logic |

### 10. อย่าแตะสิ่งที่ Work อยู่!

| ❌ ห้ามทำ | ✅ ทำแทน |
|----------|---------|
| แก้ bug A แล้ว refactor B ด้วย | แก้เฉพาะ A จุดเดียว |
| เห็น code เก่า → "clean up" | ปล่อยไว้ถ้ามัน work |
| เปลี่ยน import/structure พ่วง | แก้แค่ที่จำเป็น |
| "ปรับปรุง" code ข้างเคียง | อย่าแตะ! |

```
┌─────────────────────────────────────────────────────────────┐
│  🚨 ก่อนแก้ไขทุกครั้ง ถามตัวเอง:                              │
├─────────────────────────────────────────────────────────────┤
│  1. "จุดนี้พังจริงไหม หรือแค่อยากปรับ?"                       │
│  2. "ถ้าแก้ตรงนี้ จะกระทบอะไรอีก?"                           │
│  3. "จุดอื่นที่จะแตะ มัน work อยู่ไหม?" → ถ้า work อย่าแตะ!   │
│  4. "Scope เล็กที่สุดที่แก้ปัญหาได้คืออะไร?"                  │
└─────────────────────────────────────────────────────────────┘
```

### 7. Self-Improvement Rules

```
┌─────────────────────────────────────────────────────────────┐
│  🔴 ก่อนแก้ Bug ที่เคยแก้มาแล้ว                              │
├─────────────────────────────────────────────────────────────┤
│  1. Search memory: "ปัญหานี้เคยแก้กี่ครั้ง?"                  │
│  2. ดู pattern: "solutions ไหน partial work / fail?"        │
│  3. หา root cause: "ทำไมมันพัง ไม่ใช่ พังยังไง"              │
│  4. ถ้า > 3 attempts → หยุด วิเคราะห์ใหม่ทั้งหมด             │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  🟢 ใช้ Memory อย่างฉลาด                                     │
├─────────────────────────────────────────────────────────────┤
│  • อ่าน observation (300 tokens) แทน read file (1000+ tokens)│
│  • Search memory ก่อน grep codebase                         │
│  • Trust past decisions - ไม่ต้อง re-analyze ทุกครั้ง        │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  🔵 Track Progress ป้องกัน Session Loss                      │
├─────────────────────────────────────────────────────────────┤
│  • TodoWrite ทันทีที่เริ่ม task                              │
│  • Update status ทุก step สำคัญ                              │
│  • ถ้า session หลุด → user สามารถ resume ได้                 │
└─────────────────────────────────────────────────────────────┘
```

### Key Metrics จากการวิเคราะห์

| Metric | ค่า | ความหมาย |
|--------|-----|----------|
| WebSocket fix attempts | 68+ | ⚠️ ซ้ำซากมาก ต้องหา root cause |
| Sessions วันนี้ | 8 | หลาย sessions เพราะ disconnect |
| Tokens saved by memory | 86% | 💰 ดี แต่ยังใช้ได้มากกว่านี้ |

---

*อัพเดทล่าสุด: 2 ม.ค. 2026 - Self-Analysis*
