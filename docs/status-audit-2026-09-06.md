# รายงานตรวจสถานะโปรเจกต์ — 6 กันยายน 2026

ตรวจจาก working tree ที่เริ่มต้นสะอาดบน commit `a8b6263` หลังรวม PR #256 (RAG split) และ #255 (webhook v2 parity) โดยตีความคำขอ `/status` เป็นการตรวจภาพรวมโปรเจกต์และปรับปัญหาที่พิสูจน์ได้

ขอบเขต: ชุดทดสอบ Backend/Frontend, production build, lint, CI configuration และการอ่านโค้ด health monitoring โดยเจาะลึก ไม่ใช่การตรวจทุก service ทุกบรรทัดหรือการรับรอง production

## สิ่งที่แก้แล้ว

### 1. Realtime health รายงาน healthy ทั้งที่คิวเกินเกณฑ์

ไฟล์: `backend/app/Http/Controllers/Api/HealthController.php`

เดิมสถานะรวมดูเฉพาะ broadcasting และจำนวน failed jobs แต่ไม่ได้ใช้ queue depth แม้จะมีเกณฑ์ `< 100` อยู่ในสถานะย่อย จึงเกิด response ที่ `status=healthy` แต่ `checks.queue.ok=false`

ปรับให้ใช้เงื่อนไข queue เดียวกันทั้งสถานะรวมและสถานะย่อย:

```php
$queueOk = $queueDepth < 100 && $failedCount < 10;
$status = ($broadcastOk && $queueOk) ? 'healthy' : 'degraded';
```

ผลที่ได้เมื่อ broadcasting เปิด:

| งานพร้อมประมวลผล | งานล้มเหลว | สถานะรวม | queue.ok |
|---:|---:|---|---|
| 99 | 0 | healthy | true |
| 100 | 0 | degraded | false |
| 0 | 9 | healthy | true |
| 0 | 10 | degraded | false |

งานที่กำหนดเวลารันในอนาคตยังไม่ถือเป็น ready backlog และเมื่อ broadcasting ปิด สถานะรวมยังเป็น degraded

เพิ่ม regression tests แล้วรันก่อนแก้: พบ 2 failures ตรงกับ healthy ผิดกรณีคิวล้น และ queue.ok ผิดกรณี failed jobs ถึงเกณฑ์ หลังแก้ผ่านทั้งหมด

คง HTTP 200 ของ realtime endpoint ตามสัญญาเดิม ผู้ใช้ endpoint นี้ต้องอ่าน `status` ใน JSON ส่วน `/api/health` และ `/api/health/detailed` ใช้ HTTP 503 เมื่อ degraded

### 2. Test คิวล้นไม่เคยตรวจเงื่อนไขจริงในการตั้งค่าปกติ

ไฟล์: `backend/tests/Feature/HealthCheckTest.php`

เดิม test ถูกข้ามเมื่อ queue driver ไม่ใช่ database ซึ่งค่าทดสอบปกติเป็น sync และยังคาด HTTP 200 พร้อมยอมรับทั้ง healthy/degraded ทำให้ตรวจจับ regression ไม่ได้

แก้ให้กำหนด database queue ภายใน test และตรวจชัดเจน:

- 1,000 งาน: detailed endpoint healthy
- 1,001 งาน: detailed endpoint degraded, HTTP 503 และ pending_jobs ตรงจำนวน
- public health endpoint: degraded, HTTP 503
- CLI `health:check --json`: exit code 1 และมี degraded ใน output

### 3. ชุด Backend เต็มใช้หน่วยความจำเกินค่า PHP ในเครื่อง

ไฟล์: `backend/phpunit.xml`

`php artisan test --compact` ครั้งแรกหยุดเพราะ memory limit 128 MB เมื่อรันใหม่ด้วย 512 MB ชุดเต็มจบ และ PHPUnit รายงานใช้ 135 MB จึงกำหนด `memory_limit=512M` ใน PHPUnit configuration โดยมีผลเฉพาะการทดสอบ

หลังแก้ รัน `php artisan test --compact` โดยไม่ต้องส่ง memory override เพิ่มได้สำเร็จ

### 4. CI ควรตรวจ production build ของ Frontend ด้วย

ไฟล์: `.github/workflows/ci.yml`

Root `frontend/tsconfig.json` มี `files: []` และอ้างอิง app/node configs ผ่าน project references เดิม CI ใช้ `npx tsc --noEmit` จึงเปลี่ยนเป็น script ที่โปรเจกต์มีอยู่แล้วคือ `npm run build` (`tsc -b && vite build`) เพื่อให้ตรวจตาม references และตรวจ bundling ก่อน merge

คำสั่ง build ผ่านในเครื่องนี้ ส่วน workflow บน GitHub ยังไม่ได้รันจากการแก้ครั้งนี้

## ผลตรวจสอบ

| การตรวจ | ผล |
|---|---|
| Backend: `php artisan test --compact` หลังแก้ | 1,265 passed, 16 skipped, 3,334 assertions; 24.42 วินาที |
| Health tests เฉพาะสองไฟล์ | 14 passed, 72 assertions |
| Frontend: `npm test -- --reporter=dot` | 32 ไฟล์, 152 tests ผ่าน |
| Frontend: `npm run build` | TypeScript และ Vite build ผ่าน |
| Frontend: `npm run lint` | 0 errors, 24 warnings |
| Backend: `vendor/bin/pint --test` | ผ่านทั้งโปรเจกต์ |
| `git diff --check` | ผ่าน |

การรัน PHPUnit โดยตรงก่อนเพิ่มสอง test สุดท้ายรายงาน 94 PHPUnit notices โดยไม่มี failures/errors ยังไม่ได้วิเคราะห์ notices แต่ละรายการ จึงไม่ถือว่าชุดทดสอบปราศจาก notices

## ประเด็นที่ยังเหลือและควรติดตาม

### ลำดับสูง: ความครอบคลุมฐานข้อมูลจริง

16 tests ถูกข้ามในการรันบน SQLite พบการข้ามเพราะต้องใช้ PostgreSQL เช่น ConversationService, TagService และ Facebook postback/message type ที่ SQLite แก้ CHECK constraint ไม่ได้ การผ่านชุดนี้จึงยังไม่ยืนยันพฤติกรรม PostgreSQL ทั้งหมด

CI ปัจจุบันมี SQLite job ควรเพิ่ม PostgreSQL test job ในงานแยก พร้อมตรวจ migrations/extension ที่ต้องใช้ก่อนกำหนดเป็น required check

### ลำดับสูง: ขอบเขตของ health monitoring

- Realtime endpoint อ่านตาราง `jobs`/`failed_jobs` โดยตรง จึงไม่ได้ยืนยัน Redis queue หากเปลี่ยน driver
- CLI และ general health check นับ backlog จริงเฉพาะ connection ชื่อ database; driver อื่นยังมีเส้นทางรายงาน up โดยไม่ได้ probe queue
- Broadcasting health ดูจากชื่อ driver ไม่ได้ยืนยันว่าเชื่อมต่อ Reverb หรือส่ง event สำเร็จ
- Failed jobs เป็นจำนวนสะสม ไม่ใช่อัตราความผิดพลาดล่าสุด เกณฑ์ 10 อาจทำให้ degraded ค้างจนจัดการงานเก่า

ข้อจำกัดเหล่านี้มีอยู่เดิม การแก้ครั้งนี้ทำให้ผลรายงานสอดคล้องตามเกณฑ์เดิม ยังไม่เปลี่ยนระบบตรวจเป็น worker heartbeat หรือ end-to-end probe

### ลำดับกลาง: React warnings 24 จุดใน 14 ไฟล์

| กฎ | จำนวน | สิ่งที่ต้องตรวจต่อ |
|---|---:|---|
| `react-hooks/set-state-in-effect` | 12 | state ที่คำนวณจากข้อมูลเดิม และ effect ที่ทำให้ render เพิ่ม |
| `react-hooks/refs` | 6 | การอ่าน/เขียน ref ระหว่าง render และผลต่อ React Compiler |
| `react-hooks/exhaustive-deps` | 5 | dependency ที่ขาดหรือเปลี่ยนทุก render |
| `react-hooks/incompatible-library` | 1 | `useVirtualizer` ใน MessageList ถูก compiler ข้าม |

ไฟล์ที่มี warnings: MessageList, QuickReplyAutocomplete, PluginSection, avatar, useChannelInfo, useConnectionForm, useEcho, useStreamingChat, BotSettingsPage, BotsPage, ChatPage, FlowEditorPage, SettingsPage, VipManagementPage

ยังไม่ได้เปลี่ยน React lifecycle ในรอบนี้ เพราะต้องตรวจพฤติกรรมแชต, streaming, form reset และการเปลี่ยน bot รายจุด ไม่ควรแก้เพียงเพื่อซ่อนคำเตือน

### Webhook v2 rollout

มี runbook กำหนดเริ่ม bot 26, สังเกต 7 วัน แล้วขยายทุก bot อีก 48 ชั่วโมงก่อนลบ legacy paths ดู `docs/superpowers/runbooks/2026-09-05-webhook-v2-rollout.md`

การตรวจนี้ไม่ได้ยืนยันว่า production เปิด flag แล้วหรือผ่านช่วงสังเกตแล้ว จึงยังไม่มีหลักฐานให้ลบ legacy ตามเงื่อนไข runbook

## ข้อจำกัดการส่งมอบ

ผลทั้งหมดเป็น local verification ไม่ได้ smoke test ผ่าน browser, ตรวจ Railway/Sentry, ยิงข้อความหาลูกค้า หรือวัด load/latency ใน production ไม่ได้อัปเดต dependencies หรือทำ security audit ทั้งระบบ ไม่มีการ deploy, push หรือ commit จากการตรวจครั้งนี้
