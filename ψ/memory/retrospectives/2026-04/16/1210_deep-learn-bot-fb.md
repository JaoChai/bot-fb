# Session Retrospective — Deep Learn bot-fb

📡 **Session**: 6a6806b7 | bot-fb | ~15 นาที active
**Session Date**: 2026-04-16
**Start/End**: 11:55 – 12:10 GMT+7
**Focus**: Orientation (recap) → Knowledge capture (learn --deep on bot-fb)
**Type**: Research / Knowledge engineering

---

## Session Summary

Session หลัง gap 5 วัน (ตั้งแต่ PR #125 merge 11 เม.ย.) เริ่มด้วย `/recap --deep` เพื่อ orient — พบว่า main clean, ไม่มี focus/handoff/tracks, มี untracked screenshots 14 ไฟล์ค้าง. ตัดสินใจ build knowledge layer ก่อนเริ่มงานจริง โดยใช้ `/learn --deep` บน bot-fb เอง. 5 Haiku agents ทำงานขนานกัน ผลิตเอกสาร 4820 บรรทัด 5 ไฟล์ (ARCHITECTURE 970, CODE-SNIPPETS 1616, QUICK-REF 697, TESTING 876, API-SURFACE 661). จากนั้น synthesis agent ดึง cross-cutting themes ได้ — ที่สำคัญคือ insight ว่า bot-fb **"ไม่ใช่ RAG system แต่เป็น hallucination containment system ที่มี RAG bolted on"** (multi-layer guard: prompt injection + post-gen validation + semantic cache).

## Past Session Timeline

| Time | Duration | Activity | Jump |
|------|----------|----------|------|
| 11:55 | ~2m | `/recap --deep` — map main state | — |
| 11:57 | ~3m | `/learn --deep` 2x ไม่มี target, ถามกลับ | — |
| 12:00 | ~5m | `/learn --deep` bot-fb: spawn 5 Haiku agents ขนาน | spark |
| 12:05 | ~1m | Hub file (`bot-fb.md`) + `.origins` manifest | complete |
| 12:07 | ~1m | `/recap --deep` mid-session (now-deep format) | return |
| 12:10 | ~3m | `/rrr --deep` — synthesis agent + retro compile | complete |

## Files Modified / Created

**ไม่มี git commits** — session นี้ไม่ได้แตะโค้ด production

**Created (untracked)**:
```
ψ/learn/.origins                                      (manifest)
ψ/learn/JaoChai/bot-fb/bot-fb.md                      (hub)
ψ/learn/JaoChai/bot-fb/2026-04-16/1202_ARCHITECTURE.md  (970 lines)
ψ/learn/JaoChai/bot-fb/2026-04-16/1202_CODE-SNIPPETS.md (1616 lines)
ψ/learn/JaoChai/bot-fb/2026-04-16/1202_QUICK-REFERENCE.md (697 lines)
ψ/learn/JaoChai/bot-fb/2026-04-16/1202_TESTING.md       (876 lines)
ψ/learn/JaoChai/bot-fb/2026-04-16/1202_API-SURFACE.md   (661 lines)
ψ/memory/retrospectives/2026-04/16/1210_deep-learn-bot-fb.md  (this file)
ψ/memory/learnings/2026-04-16_deep-learn-workflow.md    (lesson)
```

**Pre-existing untracked (not from this session)**:
- 14 dashboard screenshots (.png) จาก work ก่อน
- `.claude/skills/playwright-cli/`, `.claude/worktrees/`

## Architecture Decisions

1. **สร้าง ψ/ structure ใหม่ใน bot-fb repo** — ไม่ใช่ symlink ไป vault. ต่างจาก oracle repo pattern ปกติ. ข้อดี: docs อยู่กับ repo. ข้อเสีย: ไม่ share กับ project อื่น. **ยังไม่ตัดสินใจว่าจะ commit หรือ gitignore**.

2. **ลดจาก 5 → 1 retro subagent** — session ไม่มี git changes, agents สำหรับ git/files analysis จะ empty. ใช้ synthesis agent เดียวเพื่อดึง themes จาก 5 learn docs แทน. ตรงกับ retro-index lesson "orchestrator รู้ context ดีกว่า dispatch subagent สำหรับงานเล็ก".

3. **Local project ไม่ต้อง ghq clone** — /learn skill อนุญาตให้อ่าน source ตรงจาก ROOT (ไม่ผ่าน `origin/` symlink)

## AI Diary

Session นี้ทำให้ผมได้เห็นวิธีใช้ตัวเองที่ไม่ค่อยเกิด — ไม่ใช่แก้โค้ด, ไม่ใช่ debug, แต่เป็นการ **สร้าง map ของ codebase** ล่วงหน้า. ตอนแรกที่เห็น `/learn --deep` ไม่มี target, ผมหงุดหงิดเล็กน้อยเพราะถามซ้ำ 2 รอบ. แต่พอ user บอก "ในโปรเจคนี้ละ" ทุกอย่างเข้าที่ทันที. การ spawn 5 Haiku agents ขนานกันรู้สึกดีมาก — cost-effective + produce 4820 บรรทัดใน ~3 นาที. แต่ตรงนี้แหละที่ผมต้องระวัง: เอกสาร 4820 บรรทัดดูเยอะ, แต่คุณภาพของแต่ละตัวไม่เท่ากัน — ARCHITECTURE agent อาจจะ hallucinate feature ที่ไม่มีจริง (เช่น "CRAG evaluation" ที่ agent อ้างว่ามี แต่ผมยังไม่ได้ verify). ถ้า user เชื่อ 100% โดยไม่ cross-check กับโค้ดจริง อาจจะใช้ข้อมูลผิด. Synthesis agent ช่วยจับ gaps ได้บางส่วน (เช่น Vision Model Guard ไม่มี code ให้ดู, HITL ไม่มี test) — แสดงว่าเอกสารไม่ครบ. ผมคิดว่าวิธีใช้ที่ปลอดภัยสุดคือ: ใช้ docs เป็น index/หาจุดเริ่ม แล้วเปิดโค้ดจริงตามเสมอ ไม่ใช่ copy code snippet จาก docs ไปใช้ทันที. อีกเรื่อง — session นี้ไม่มี git commit แต่สร้างไฟล์เยอะมากใน ψ/ ซึ่ง user ยังไม่ได้ตัดสินใจว่า commit หรือไม่. ผมเลือกไม่ auto-commit ตามกฎ honesty-guard + risky action guidance.

## What Went Well

- **5 parallel agents ทำเสร็จพร้อมกัน** ใน ~3 นาที (10x เร็วกว่าทำ sequential)
- **Haiku model = cost effective** สำหรับ exploration (แต่ละ agent ~130k tokens)
- **Synthesis agent ดึง insight ที่ไม่เห็นจาก docs เดี่ยว** — "hallucination containment system" framing
- **ตัดสินใจ skip subagents 4/5 ตัว** ตาม retro-index lesson — ไม่ดื้อทำตาม skill template
- **Mid-session /recap --now deep** ช่วยให้ user เห็นภาพรวม session ก่อนต่อ

## What Could Improve

- **ถาม target ของ /learn 2 รอบก่อนจะเข้าใจ** — น่าจะเสนอ "bot-fb เอง" เป็น default ตั้งแต่รอบแรก
- **ไม่ได้ verify claim จาก learn agents** — มี hallucination risk ที่ไม่ได้เช็ค
- **ไม่ได้ถาม user ว่าจะ commit ψ/ ไหม** ก่อนสร้าง (ตอนนี้ใหญ่ ~170KB แล้ว — สุดท้ายอาจตัดสินใจ gitignore เหมือน oracle pattern)

## Honest Feedback (Friction Points)

**Friction 1 — Tool chain ceremony เยอะเกิน**: `/recap --deep` → `/learn --deep` → `/recap --deep` → `/rrr --deep` ภายใน 15 นาที. แต่ละ skill มี template + step เยอะ (/rrr --deep กำหนด 5 subagents, oracle sync, commit step). สำหรับ session ที่ไม่มี git changes, ceremony นี้ dominate จริงๆแล้วเนื้องาน. ผม adapted โดยลด subagent เป็น 1 — ถ้าทำตาม template เคร่งจะเปลือง token + เวลาเปล่าๆ.

**Friction 2 — skill DEEP.md ขัดกับ /rrr default rules**: Default /rrr บอก "Do NOT git add ψ/" (เพราะ ψ/ มักเป็น symlink ไป vault). แต่ DEEP.md step 5 บอกให้ `git add ψ/memory/retrospectives/` + commit. bot-fb ไม่มี vault, ψ/ เป็น real dir — ถ้า follow DEEP step 5 จะ commit โดยไม่ confirm. ผมเลือกไม่ commit ตาม default rule + risky-action guidance (commits = visible action ต้อง confirm). Skill mismatch นี้ควร reconcile.

**Friction 3 — ไม่มี focus/handoff = ไม่รู้ว่า user อยากทำอะไรจริงๆ**: session กลายเป็น "warm up forever" — 3 orientation commands + 1 learning + 1 retro = 5 slash commands โดย 0 code changes. User อาจจะติด ritual ตรงนี้. ถ้ามี `focus.md` หรือ handoff ล่าสุด จะมีทิศทางชัดกว่า. ผมน่าจะถาม "อยากแก้อะไรเฉพาะ?" ตั้งแต่รอบแรกของ recap แทนที่จะทำ ceremony ตาม default.

## Lessons Learned

### Lesson 1: /learn --deep + local project = 4820 บรรทัด เอกสาร ~3 นาที
- **บทเรียน**: 5 Haiku agents ขนานกัน cost-effective มากสำหรับ onboarding/orientation
- **แต่**: docs อาจมี hallucination — ใช้เป็น index, อย่า copy-paste snippets โดยไม่ verify

### Lesson 2: Subagent count ใน /rrr --deep ควรยืดหยุ่นตาม session
- **บทเรียน**: ถ้า session ไม่มี git changes, agents 1-3 (git/files/timeline) จะ produce empty output
- **แนวทาง**: ลดเป็น 1-2 agents (pattern + oracle search) หรือ main agent เขียนเอง — ตรงกับ retro-index lesson

### Lesson 3: bot-fb = hallucination containment system
- **บทเรียน**: multi-layer guard (prompt inject + post-gen validate + semantic cache) ไม่ใช่ "RAG with safety", แต่ **"safety with RAG bolted on"**
- **How to apply**: ทุกครั้งที่เพิ่ม feature AI — ถามก่อนว่า "ถ้า LLM hallucinate, ชั้นไหนจะจับ?"

### Lesson 4: `/recap` → `/learn` → `/rrr` = onboarding ritual หลัง gap
- ช่วง gap หลายวัน (>3d) pattern นี้ดีกว่ากระโดดแก้โค้ดทันที
- แต่ **ระวัง analysis paralysis** — 15 นาทีกับ meta-commands ก่อนจะรู้ว่าจะทำอะไรต่อ

### Lesson 5: Gaps ที่เอกสารไม่ครอบคลุม (จาก synthesis agent)
- Vision Model Guard: ไม่เห็น code ของ `supportsVision()` ที่ไหน → ต้องไปหาใน AIService / ModelCapabilityService
- Second AI (fallback LLM): ถูก mention แต่ไม่ documented flow
- HITL complete flow: endpoints มีใน API-SURFACE แต่ไม่มี code/test
- Flow cache invalidation: มี code แต่ไม่รู้ถูก invoke จากที่ไหน

## Next Steps

1. **ตัดสินใจ commit vs gitignore ψ/** — ถ้า commit, run `vendor/bin/pint` ไม่กระทบ (markdown only); ถ้า gitignore, add pattern
2. **เคลียร์ screenshots 14 ไฟล์** ที่ค้างจาก dashboard work ก่อนหน้า
3. **Verify 5 learn docs กับโค้ดจริง** — spot-check 3-5 claim (vision guard, second AI, HITL flow)
4. **เริ่มงานจริง** — มี knowledge map แล้ว, พร้อมเลือก: stock evolution / auto loop / dashboard polish / bug

## Metrics

- Commits: 0
- Files created: 8 (5 learn docs + 1 hub + 1 retro + 1 lesson)
- Lines written: ~5000 (ส่วนใหญ่ agent-generated)
- Subagents spawned: 6 (5 learn + 1 synthesis)
- Slash commands: 5 (`/recap --deep` x2, `/learn --deep` x3, `/rrr --deep`)
