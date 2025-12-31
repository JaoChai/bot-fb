---
event: PostToolUse
trigger:
  - tool: Bash
condition: |
  (command contains "npm run build") AND
  ((output contains "error TS") OR
   (output contains "Error:") OR
   (output contains "ERROR") OR
   (output contains "failed") OR
   (output contains "FAILED") OR
   (output contains "Cannot find module") OR
   (output contains "Module not found") OR
   (output contains "is not assignable to") OR
   (output contains "SyntaxError") OR
   (output contains "TypeError") OR
   (output contains "has no exported member"))
---

# DEPLOYMENT BLOCKED - Build Failure

Frontend build failed. **DO NOT proceed with commit or push.**

**Invoking Deployment Agent** to analyze and help fix the errors.

The agent will:
1. Parse error messages
2. Identify problematic files and lines
3. Explain the errors
4. Suggest specific fixes
5. Re-run build after fixes

**BUILD MUST PASS before committing.**

Analyzing build errors...
