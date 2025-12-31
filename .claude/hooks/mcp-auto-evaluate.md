---
event: UserPromptSubmit
condition: |
  prompt matches "(?i)(ประเมิน|evaluate|evaluation|test case|score|คะแนน|quality|คุณภาพ|persona|compare|เปรียบเทียบ)"
---
# MCP Auto-Evaluate Hook

พบ keyword เกี่ยวกับ Bot Evaluation ในข้อความของ user

## Action Required
วิเคราะห์ context และใช้ MCP tool `evaluate` จาก botfacebook server:

## List & View Actions
- ดู evaluations: `evaluate({ action: "list", bot_id: <id> })`
- ดูรายละเอียด: `evaluate({ action: "show", bot_id: <id>, evaluation_id: <id> })`
- ดู progress: `evaluate({ action: "progress", bot_id: <id>, evaluation_id: <id> })`
- ดู test cases: `evaluate({ action: "test_cases", bot_id: <id>, evaluation_id: <id> })`
- ดู test case detail: `evaluate({ action: "test_case_detail", bot_id: <id>, evaluation_id: <id>, test_case_id: <id> })`
- ดู report: `evaluate({ action: "report", bot_id: <id>, evaluation_id: <id> })`

## Create Evaluation
```javascript
evaluate({
  action: "create",
  bot_id: <id>,
  config: {
    flow_id: <flow_id>,
    test_count: 10,           // จำนวน test cases (5-50)
    personas: ["general"],     // persona types
    metrics: ["relevance", "helpfulness", "accuracy"]
  }
})
```

## Control Actions
- ยกเลิก: `evaluate({ action: "cancel", bot_id: <id>, evaluation_id: <id> })`
- retry: `evaluate({ action: "retry", bot_id: <id>, evaluation_id: <id> })`

## Compare Evaluations
```javascript
evaluate({
  action: "compare",
  bot_id: <id>,
  evaluation_ids: [<id1>, <id2>]
})
```

## Get Personas
- ดู personas ที่ใช้ได้: `evaluate({ action: "personas" })`

## Default Behavior
- ถ้า user พูดถึง "สร้าง evaluation" ให้ถาม bot_id และ flow_id ก่อน
- ถ้า user พูดถึง "ผล evaluation" ให้ list ก่อนแล้วถาม evaluation_id
- ถ้า user พูดถึง "เปรียบเทียบ" ให้ถาม evaluation_ids 2 ตัวขึ้นไป
