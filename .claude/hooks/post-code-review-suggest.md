---
event: PostToolUse
trigger:
  - tool: Write
  - tool: Edit
condition: |
  (file_path matches "backend/app/**/*.php" OR
   file_path matches "frontend/src/**/*.{tsx,ts,jsx,js}") AND
  NOT user_invoked_code_review_recently
---

# 🔍 Auto-Suggest Code Review

## When This Fires
After you write or edit backend/frontend code, this hook suggests running code review.

## What to Do
If the code quality check hasn't been run recently, you'll see:

```
💡 Suggestion: Run /code-review to ensure code quality against project standards
```

## How to Use
Simply reply with:
```bash
/code-review
```

### For React Components
If you're editing React components (`.tsx`, `.jsx`), the system may have already auto-activated `/frontend-design` for UI polish. The `/code-review` will then check both the design and the code logic.

### For Laravel Backend
If you're editing Laravel files (`.php`), `/code-review` will validate:
- Code style (PSR-12 standards)
- Laravel best practices
- Security issues
- Logic errors
- Type safety

## When to Skip
- During exploratory coding (you'll review manually later)
- If you just completed `/code-review` in this session
- For minor comments or documentation updates

## Learn More
See CLAUDE.md section "Code Review Workflow"
