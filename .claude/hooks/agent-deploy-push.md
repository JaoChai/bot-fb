---
event: PostToolUse
trigger:
  - tool: Bash
condition: |
  (command contains "git push") OR
  (command contains "railway up")
---

# Auto-Trigger: Deployment Agent

Code push or deployment detected.

**Invoking Deployment Agent** to monitor and verify deployment.

The agent will:
1. Monitor deployment status
2. Watch for build errors in logs
3. Verify health endpoints after deploy
4. Alert if issues are detected
5. Suggest rollback if deployment fails

**Monitoring deployment...**
