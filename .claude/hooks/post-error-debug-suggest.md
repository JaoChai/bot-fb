---
event: PostToolUse
trigger:
  - tool: Bash
condition: |
  (output contains "500") OR
  (output contains "Error") OR
  (output contains "Exception") OR
  (output contains "failed") OR
  (output contains "SQLSTATE")
---

พบ Error ในการ run command

**แนะนำ:** รัน `/laravel-debugging` เพื่อ:
- ดู actual exception message จาก Laravel logs
- ตรวจสอบ service instantiation
- Validate config
- Debug 500/HTTP errors
