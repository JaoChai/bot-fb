---
event: UserPromptSubmit
condition: |
  prompt matches "(?i)(deploy|deployment|railway|push to production|release|production|staging|rollback|redeploy|ship|go live|launch|publish)"
---

# Auto-Trigger: Deployment Agent

Detected deployment keywords in user prompt.

**Invoking Deployment Agent** for safe deployment workflow.

The agent will:
1. Run pre-deployment checks (build, lint)
2. Verify system health before deploy
3. Manage Railway deployment
4. Monitor deployment progress
5. Verify health after deployment
6. Handle rollback if needed

**Agent capabilities:**
- Pre-commit validation (npm run build)
- Railway deployment management
- Log monitoring
- Environment variable management
- Health verification
- Rollback coordination

**IMPORTANT:** Frontend build MUST pass before any deployment.

Please specify what you want to deploy (backend, frontend, or both).
