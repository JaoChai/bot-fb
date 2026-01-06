---
description: Review recent learnings and create new rules from patterns
user-invocable: true
---

# Review Learnings

วิเคราะห์ observations ล่าสุดและสร้าง rules ใหม่จาก patterns ที่พบ

## Instructions

1. **ดึง Observations ล่าสุด**

ใช้คำสั่ง SQL query observations จาก memory database:

```bash
sqlite3 ~/.claude-mem/claude-mem.db "
  SELECT id, title, text, type, created_at
  FROM observations
  WHERE project LIKE '%BotFacebook%'
    AND created_at_epoch > strftime('%s', 'now', '-7 days')
    AND text IS NOT NULL
    AND length(text) > 50
  ORDER BY created_at_epoch DESC
  LIMIT 50
"
```

2. **วิเคราะห์หา Patterns**

จาก observations ที่ได้ ให้หา:

- **Repeated Actions**: สิ่งที่ทำซ้ำ 3+ ครั้ง → ควรเป็น [PATTERN]
- **Mistakes Made**: ข้อผิดพลาดที่เกิดขึ้น → ควรเป็น [MISTAKE]
- **Gotchas Found**: กับดักที่เจอ → ควรเป็น [GOTCHA]
- **New Methods**: วิธีการใหม่ที่ดี → ควรเป็น [PATTERN] หรือ [RULE:xxx]

3. **แสดงผลให้ User**

แสดงรายการ patterns ที่พบ:

```
╔══════════════════════════════════════════════════════════════╗
║  📊 LEARNING ANALYSIS RESULTS                                ║
╠══════════════════════════════════════════════════════════════╣
║  Analyzed: XX observations (last 7 days)                     ║
╚══════════════════════════════════════════════════════════════╝

พบ Patterns ที่น่าสนใจ:

[1] [PATTERN] ใช้ Playwright test แทน manual test
    พบ: 5 ครั้ง
    เหตุผล: เร็วกว่า, reliable กว่า

[2] [MISTAKE] ลืม manual test ก่อน mark verified
    พบ: 3 ครั้ง
    เหตุผล: รีบ commit ไป

[3] [GOTCHA] API response ถูก wrap ด้วย {data: X}
    พบ: 2 ครั้ง
    เหตุผล: frontend ใช้ response ตรงๆ ไม่ได้

ต้องการสร้างเป็น Rule ข้อไหนบ้าง?
```

4. **รับ Input จาก User**

ใช้ AskUserQuestion ถามว่าต้องการสร้าง rule ข้อไหน

5. **Seed Rules ที่ถูกเลือก**

สำหรับแต่ละ pattern ที่ user เลือก:

```bash
sqlite3 ~/.claude-mem/claude-mem.db "
  INSERT INTO observations (sdk_session_id, project, title, text, type, created_at, created_at_epoch)
  VALUES (
    'review-learning-session',
    'BotFacebook',
    '[RULE:xxx] Title here',
    '[RULE:xxx][SCOPE] Full description...',
    'decision',
    datetime('now'),
    strftime('%s', 'now')
  )
"
```

6. **อัพเดท Learning State**

```javascript
const state = {
  last_review: new Date().toISOString(),
  rules_created: state.rules_created + newRulesCount,
  total_reviews: state.total_reviews + 1,
  last_updated: new Date().toISOString()
};
// Write to .claude/learning-state.json
```

7. **แสดงผลสรุป**

```
╔══════════════════════════════════════════════════════════════╗
║  ✅ LEARNING REVIEW COMPLETE                                 ║
╠══════════════════════════════════════════════════════════════╣
║  Created: X new rules                                        ║
║  Total rules: XX                                             ║
║  Next review: 7 days                                         ║
╚══════════════════════════════════════════════════════════════╝
```

## Example Output

เมื่อรัน `/review-learnings` ควรได้:

1. รายการ patterns ที่พบจาก observations
2. ให้ user เลือกว่าจะสร้าง rule ข้อไหน
3. Seed rules ที่เลือกเข้า memory
4. อัพเดท learning-state.json
5. แสดงผลสรุป
