---
event: UserPromptSubmit
condition: |
  prompt matches "(?i)(error|ล่ม|พัง|500|502|503|504|ช้า|ปัญหา|หยุด|ไม่ทำงาน|connection|timeout|failed|crash|down|bug|issue)"
---
# MCP Auto-Diagnose Hook

พบ keyword เกี่ยวกับปัญหาระบบในข้อความของ user

## Action Required
ให้ใช้ MCP tool `diagnose` จาก botfacebook server โดยอัตโนมัติ:

```
diagnose({ action: "all" })
```

## After Diagnosis
1. วิเคราะห์ผลลัพธ์จาก diagnose
2. ระบุ component ที่มีปัญหา (backend, frontend, database, queue, cache)
3. แนะนำการแก้ไขที่เหมาะสม
4. ถ้าต้องใช้ `fix()` tool ให้ขอ confirm ก่อน (สำหรับ dangerous actions)

## Specific Actions
- ถ้า error เกี่ยวกับ backend: `diagnose({ action: "backend" })`
- ถ้า error เกี่ยวกับ database: `diagnose({ action: "database" })`
- ถ้า error เกี่ยวกับ queue/job: `diagnose({ action: "queue" })`
- ถ้า error เกี่ยวกับ Railway/deploy: `diagnose({ action: "railway" })`
