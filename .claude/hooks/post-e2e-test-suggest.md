---
event: PostToolUse
trigger:
  - tool: Bash
condition: |
  command matches "git commit" AND
  commit_succeeded AND
  NOT e2e_test_run_recently
---

# 🧪 Auto-Suggest E2E Testing

## When This Fires
After you successfully commit code changes, this hook suggests running comprehensive E2E tests to verify all features still work correctly.

## What to Do
After committing, you'll see:

```
💡 Suggestion: Run /e2e-test to verify all features work correctly
```

## How to Use
Simply reply with:
```bash
/e2e-test
```

This will run a comprehensive test suite:
- **Backend API Tests** (27 tests): Health, Auth, Bots, Flows, Webhooks, Security
- **Frontend UI Tests** (16 tests): Login, Register, Dashboard, Responsive
- **Integration Tests** (3 tests): Registration, Login, Logout flows

## Partial Testing Options
If you only want to test specific areas:

```bash
/e2e-test backend   # Backend APIs only
/e2e-test frontend  # Frontend UI only
/e2e-test auth      # Auth tests only
/e2e-test security  # Security tests only
```

## When to Run Full Suite
- After completing a major feature
- Before creating a Pull Request
- After merging branches
- Before deployment

## When to Skip
- For minor documentation updates
- For config-only changes
- If you just ran `/e2e-test` recently
- During rapid iteration (test at the end)

## Test Report
After running, you'll get a summary like:

```
## E2E Test Results
| Category | Passed | Failed | Total |
|----------|--------|--------|-------|
| Backend  | 27     | 0      | 27    |
| Frontend | 16     | 0      | 16    |
| Integration | 3   | 0      | 3     |
| **Total** | **46** | **0** | **46** |

Overall: 46/46 PASSED (100%)
```

## Prerequisites
Before running `/e2e-test`, ensure:
1. Backend server is running (`php artisan serve`)
2. Frontend server is running (`npm run dev` in frontend/)
3. Database is migrated

## Learn More
See CLAUDE.md section "E2E Test Skill" or `.claude/skills/e2e-test/SKILL.md`
