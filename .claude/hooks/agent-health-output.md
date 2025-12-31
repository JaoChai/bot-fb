---
event: PostToolUse
trigger:
  - tool: Bash
  - tool: WebFetch
  - tool: mcp__botfacebook__execute
condition: |
  (output contains "500 Internal Server Error") OR
  (output contains "502 Bad Gateway") OR
  (output contains "503 Service Unavailable") OR
  (output contains "504 Gateway Timeout") OR
  (output contains "Connection refused") OR
  (output contains "SQLSTATE") OR
  (output contains "Connection timed out") OR
  (output contains "curl: (7)") OR
  (output contains "curl: (28)") OR
  (output contains "ECONNREFUSED") OR
  (output contains "ETIMEDOUT") OR
  (output contains "500,") OR
  (output contains "\"status\": 500") OR
  (output contains "\"error\":") OR
  (output contains "QueryException") OR
  (output contains "PDOException")
---

# Auto-Trigger: System Health Agent

HTTP or database error detected in command output.

**Invoking System Health Agent** to diagnose the issue.

The agent will:
1. Analyze the error pattern
2. Run targeted diagnostics
3. Identify root cause
4. Suggest and apply appropriate fix

**Error detected - initiating diagnosis...**
