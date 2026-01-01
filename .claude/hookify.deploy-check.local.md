---
name: deploy-check
enabled: true
event: prompt
conditions:
  - field: user_prompt
    operator: regex_match
    pattern: (?i)(deploy|railway|production|push.*prod|release|ship)
---

## Auto-Trigger: Deployment Safety Check

Detected deployment-related keywords in user's message.

**MANDATORY ACTION - Check status FIRST:**

```javascript
mcp__botfacebook__execute({ action: "railway_status" })
```

**Before any deployment:**
1. Check current Railway deployment status
2. Verify no active deployments in progress
3. Confirm build succeeds locally (`npm run build`)
4. Only then proceed with deployment actions

**Railway commands available:**
- `railway_status` - Check deployment status
- `railway_logs` - View service logs
- `deploy_backend` / `deploy_frontend` - Deploy services (requires confirm)
