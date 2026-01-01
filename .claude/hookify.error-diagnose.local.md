---
name: error-diagnose
enabled: true
event: prompt
conditions:
  - field: user_prompt
    operator: regex_match
    pattern: (?i)(error|500|502|503|504|ล่ม|พัง|crash|down|ไม่ทำงาน|timeout|failed|failure|bug|ปัญหา|exception|ช้า|slow|หยุด|stuck)
---

## Auto-Trigger: System Diagnosis Required

Detected system/error keywords in user's message.

**MANDATORY ACTION - You MUST do this FIRST:**

```javascript
mcp__botfacebook__diagnose({ action: "all" })
```

**Why:** Never guess root cause. Get ACTUAL error data first before forming any hypothesis.

**After diagnosis:**
1. Analyze the real error from diagnostic results
2. Form hypothesis based on ACTUAL data
3. Propose specific fix to user
4. Use `mcp__botfacebook__fix()` if needed
