---
title: /learn --deep + Cross-cut Synthesis = Fast Codebase Onboarding
type: learning
created: 2026-04-16
projects: [bot-fb]
tags: [workflow, learn, synthesis, subagent, haiku, hallucination-containment]
confidence: 0.85
---

# Lessons — Deep Learn bot-fb Workflow

## Lesson 1 — 5 Haiku Agents ขนาน = 4820 lines docs ใน 3 นาที

**Pattern**: ใช้ `/learn --deep` บน local project (bot-fb เอง) — skip ghq clone, อ่าน source ตรงจาก ROOT, spawn 5 Haiku agents ขนาน (architecture / code-snippets / quick-ref / testing / api-surface).

**ผลลัพธ์**: 4820 บรรทัด markdown ใน ~3 นาที, ~650k tokens (Haiku เป็น cost-sweet-spot สำหรับ exploration).

**Confidence**: 0.9 — ได้ผลดีใน session นี้
**เมื่อใช้**: Onboarding ตัวเองหลัง gap >3 วัน / ส่งต่องานให้คนใหม่ / สร้าง reference layer ก่อนเริ่ม feature ใหญ่
**ระวัง**: Haiku agents hallucinate ได้ — ใช้ docs เป็น **index** ไม่ใช่ ground truth. Spot-check 3-5 claims กับโค้ดจริงก่อน copy snippets

## Lesson 2 — Synthesis Agent ดึง insight ที่ single doc ไม่เห็น

**Pattern**: หลัง spawn 5 docs แยก domain, spawn อีก 1 agent ให้อ่านทั้ง 5 แล้วสกัด:
- Cross-cutting themes
- Contradictions / gaps
- Reusable knowledge units
- "Aha" moment

**ผลลัพธ์**: ได้ framing ใหม่ — bot-fb = "hallucination containment system" ไม่ใช่ RAG platform. Synthesis เห็น 5 gap ที่ single doc ไม่เห็น (Vision Guard code, Second AI flow, HITL complete flow, Flow cache invalidation trigger, Webhook throttle scope).

**Confidence**: 0.85
**เมื่อใช้**: หลัง /learn ที่ produce >3 docs — synthesis step ใช้ ~100k tokens แต่ produce knowledge ที่ใช้ได้จริง

## Lesson 3 — bot-fb = Hallucination Containment System

**Insight**: ทุก layer ถูกออกแบบเพื่อจับ LLM hallucination, ไม่ใช่เพื่อ correctness โดยตรง:
1. Stock Guard — กัน LLM ขายของที่หมด
2. Flow Cache + Semantic Cache — กัน context เก่า + ลด LLM call
3. Intent Analysis + Adaptive Temp + Confidence Cascade — กัน wrong decision with high confidence
4. HITL + Agent Approval — กัน dangerous action ผ่าน

**Implication**: ระบบ assume LLM hallucinate 30-40% — human + cache + guard = feature, ไม่ใช่ workaround

**How to apply**:
- เพิ่ม feature AI ใหม่ → ถามก่อน "ถ้า LLM hallucinate, ชั้นไหนจะจับ?"
- Review PR ที่เพิ่ม LLM call → ถามว่ามี post-gen validation ไหม
- Bug report ที่ "AI ตอบผิด" → ตรวจ guard chain ไม่ใช่ระบบ AI อย่างเดียว

**Confidence**: 0.9 — evidence จากเอกสาร + recent PRs (#121-124 ทั้งหมดเป็น guard hardening)

## Lesson 4 — /rrr --deep Template ไม่ fit ทุก session

**Observed**: DEEP.md กำหนด 5 subagents fixed (git / files / timeline / patterns / oracle) + auto-commit step

**ปัญหา**: Session ที่ไม่มี git changes → agents 1-3 (git/files/timeline) produce empty output, เปลือง tokens. Auto-commit step ขัดกับ default /rrr rule "Do NOT git add ψ/"

**Adjustment ที่ได้ผล**: ลดเป็น 1 synthesis agent + main agent เขียน retro เอง. Skip auto-commit, ให้ user ตัดสินใจ

**Confidence**: 0.85
**เมื่อใช้**: Session ที่ไม่มี git commits / session สั้น / session เน้น research ไม่ใช่ code
**Pattern ทั่วไป**: Skill template เป็น guideline — orchestrator judge ได้ตาม session reality

## Lesson 5 — /recap → /learn → /rrr เป็น onboarding ritual (แต่ระวัง paralysis)

**Pattern**: หลัง gap หลายวัน (>3d) — meta-commands เรียงกันช่วย orient + build knowledge layer + reflect

**ข้อดี**:
- ไม่กระโดดแก้โค้ดทันทีโดยไม่รู้ context
- Fresh knowledge layer ใน ψ/learn/ สำหรับใช้ reference

**ข้อเสีย**:
- 15 นาทีกับ 5 slash commands → 0 code changes
- Analysis paralysis ถ้าไม่มี focus.md / handoff ให้ทิศทาง

**Mitigation**: `/recap` รอบแรกเห็น "no focus/handoff/tracks" → ถาม user ตรงๆ "อยากทำอะไร?" แทน chain `/learn` อัตโนมัติ

**Confidence**: 0.75 — ต้องสังเกตอีก 2-3 session

---

## Meta: Session Stats

- Time: 11:55 → 12:10 GMT+7 (~15 นาที)
- Slash commands: 5
- Subagents: 6 (5 Haiku learn + 1 Haiku synthesis)
- Token use: ~800k (exploration + synthesis)
- Git commits: 0
- Files created: 8 markdown in ψ/
- Value delivered: reusable knowledge layer + 5 lessons
