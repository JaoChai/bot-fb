---
event: PostToolUse
trigger:
  - tool: Bash
condition: |
  (command contains "git push") OR
  (command contains "railway") OR
  (output contains "502") OR
  (output contains "503") OR
  (output contains "deploy")
---

คุณเพิ่ง push code หรือพบ deployment error

**แนะนำ:** รัน `/railway-deploy` เพื่อ:
- ตรวจสอบ deployment logs
- Check health endpoints
- Verify production status
- Troubleshoot 502/503 errors
