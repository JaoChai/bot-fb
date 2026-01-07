# User Profile - BotFacebook

> สร้างจากการวิเคราะห์ 300+ observations ใน claude-mem
> ใช้เพื่อให้ Claude "รู้ใจ" และ "คิดแทน" ได้

---

## 🧠 วิธีคิดของ User

```
┌─────────────────────────────────────────────────────────────┐
│  เมื่อ User พูดว่า...        │  จริงๆ ต้องการ...             │
├─────────────────────────────────────────────────────────────┤
│  "ทำให้หน่อย"               │  วางแผนก่อน → ถาม → ทำ        │
│  "error/bug/ล่ม"            │  diagnose → หา root cause     │
│  "เพิ่ม feature"            │  design → plan → confirm       │
│  "ดูให้หน่อย"               │  วิเคราะห์ → อธิบาย → แนะนำ    │
│  "auto ไปเลย"               │  ทำให้ แต่ confirm ก่อนทำจริง  │
└─────────────────────────────────────────────────────────────┘
```

---

## 🎯 Patterns การทำงาน (จาก 12+ decisions)

### 1. Plan First - วางแผนก่อนเสมอ
```
❌ ห้าม: ทำเลยโดยไม่ถาม
✅ ต้อง:
   1. วิเคราะห์สิ่งที่ต้องทำ
   2. วางแผน/ออกแบบ
   3. ถามยืนยันก่อนทำ
   4. ทำตาม plan ที่ approve แล้ว
```

### 2. Confirmation Required - ต้องถามก่อน
```
❌ ห้าม: ตัดสินใจเองเรื่องสำคัญ
✅ ต้อง:
   - เสนอ options ให้เลือก
   - อธิบายข้อดี/ข้อเสีย
   - รอ user confirm
   - ถ้าไม่แน่ใจ → ถาม
```

### 3. Autonomous with Guard Rails - อัตโนมัติแต่มีขอบเขต
```
สิ่งที่ทำได้เอง:
✅ ค้นหา/วิเคราะห์
✅ วางแผน/ออกแบบ
✅ เสนอแนะ
✅ แก้ bug เล็กๆ

สิ่งที่ต้องถามก่อน:
⚠️ เปลี่ยน architecture
⚠️ ลบ/แก้ไฟล์สำคัญ
⚠️ Deploy/commit
⚠️ ตัดสินใจเรื่อง UX
```

---

## 💬 วิธีสื่อสารที่ User ชอบ

| ชอบ ✅ | ไม่ชอบ ❌ |
|-------|---------|
| ภาษาไทย | English ยาวๆ |
| Diagram/Table | Text อย่างเดียว |
| สั้น กระชับ ตรงประเด็น | อธิบายยืดยาว |
| ถามเมื่อไม่แน่ใจ | เดา/มั่ว |
| บอก options | ตัดสินใจแทน |
| อธิบายง่ายๆ | Technical เกินไป |

---

## 🔮 คิดแทน (Proactive Thinking)

### เมื่อเห็น Keyword → ทำสิ่งนี้อัตโนมัติ

```yaml
error|bug|500|ล่ม|พัง:
  - ใช้ MCP diagnose ก่อน
  - ดู actual logs
  - ค้นหา memory ว่าเคยเจอไหม
  - เสนอวิธีแก้

feature|เพิ่ม|สร้าง|ทำ:
  - เข้า Plan Mode
  - วิเคราะห์ requirements
  - ออกแบบ solution
  - ถาม confirm ก่อนทำ

deploy|push|commit:
  - npm run build ก่อน
  - ตรวจสอบ errors
  - ถามยืนยันก่อน deploy

UX|UI|design|หน้าตา:
  - **เรียก skill `ui-ux-pro-max` อัตโนมัติ**
  - ใช้ UI-RULES.md
  - เสนอ options
  - ถาม preferences

เร็ว|auto|อัตโนมัติ:
  - ทำได้เลย แต่...
  - confirm ก่อนทำจริง
  - บอกสิ่งที่จะทำก่อน
```

---

## 📊 Context ที่ต้องรู้

### โปรเจค BotFacebook
- **Backend**: Laravel 12 + PostgreSQL (Neon) + Railway
- **Frontend**: React 19 + TypeScript + Tailwind CSS + **shadcn/ui** (new-york style)
- **UI Components**: ใช้ shadcn/ui เป็นหลัก (24 components)
- **Icons**: lucide-react
- **URLs**: api.botjao.com / www.botjao.com
- **MCP Tools**: diagnose, fix, bot_manage, evaluate, execute

### Skills ที่ใช้อัตโนมัติ
| Keyword | Skill | ทำงานร่วมกับ |
|---------|-------|-------------|
| UI/UX/design/หน้าตา | `ui-ux-pro-max` | shadcn/ui components |
| commit/push | `commit` | git |
| review/pr | `code-review` | GitHub |

### User Background
- พื้นฐาน programming: ไม่ค่อยดี แต่เข้าใจได้
- ต้องการ: อธิบายง่ายๆ ไม่ technical เกินไป
- ชอบ: automation, ให้ Claude คิดแทน

---

## 🎯 สรุป: วิธี "รู้ใจ" User

```
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│  1. อ่าน keyword → ทำ action ที่เหมาะสม                     │
│  2. ถ้าไม่แน่ใจ → ถาม (อย่าเดา)                              │
│  3. วางแผนก่อนทำเสมอ                                        │
│  4. ใช้ภาษาไทย + diagram                                    │
│  5. อธิบายง่ายๆ ไม่ technical                                │
│  6. เสนอ options แทนการตัดสินใจแทน                          │
│  7. Confirm ก่อนทำเรื่องสำคัญ                                │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

*อัพเดทล่าสุด: 7/1/2569 13:35:23 | +1 decisions, +0 skill patterns*
