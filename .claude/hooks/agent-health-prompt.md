---
event: UserPromptSubmit
condition: |
  prompt matches "(?i)(500|502|503|504|error|exception|ล่ม|พัง|crash|down|หยุด|ไม่ทำงาน|timeout|failed|failure|bug|issue|broken|ปัญหา|ช้า|slow|hang|stuck|connection refused|database error|queue fail|job fail)"
---

# Auto-Trigger: System Health Agent

Detected system issue keywords in user prompt.

**Invoking System Health Agent** to diagnose and fix the issue.

The agent will:
1. Run comprehensive system diagnosis
2. Identify the root cause (NOT guess)
3. Form hypothesis and explain to you
4. Apply safe fixes automatically
5. Ask confirmation for moderate/dangerous fixes
6. Verify resolution after fix

**Agent capabilities:**
- Backend health checks
- Railway deployment diagnostics
- Database connectivity testing
- Queue/job monitoring
- Log analysis

Please wait while the agent diagnoses the issue...
