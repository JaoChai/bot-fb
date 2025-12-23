---
event: PostToolUse
trigger:
  - tool: Edit
  - tool: Write
condition: |
  (file_path matches "backend/**" OR
   file_path matches "frontend/**") AND
  user_has_unstaged_changes AND
  NOT recent_commit_created
---

# 📝 Auto-Suggest Structured Commit

## When This Fires
After you make code changes to backend or frontend files, this hook reminds you to create a structured commit.

## What to Do
When you're ready to commit your changes, run:

```bash
/commit
```

This will:
- ✅ Analyze your changes
- ✅ Auto-generate a properly formatted commit message
- ✅ Follow CLAUDE.md git standards
- ✅ Include: What, Why, Impact, and Closes #issue reference

## Commit Format
The generated commit will follow:

```
[type]: brief description

- What: specific changes made
- Why: motivation for the changes
- Impact: what this affects

Closes #issue-number

🤖 Generated with Claude Code
Co-Authored-By: Claude Haiku 4.5 <noreply@anthropic.com>
```

## Commit Types
- `feat` - New feature
- `fix` - Bug fix
- `docs` - Documentation
- `style` - Code style (formatting, etc)
- `refactor` - Code refactoring
- `test` - Adding/updating tests
- `chore` - Dependencies, tooling

## Example Usage
After writing bot API endpoint:
```bash
/commit
# System auto-generates:
# [feat]: Create bot management API endpoints
# - What: POST/GET/PUT/DELETE bot endpoints
# - Why: MVP feature for bot CRUD operations
# - Impact: Enables Phase 1C chat features
# Closes #11
```

## When to Use
✅ After completing a feature
✅ After fixing a bug
✅ Before creating a Pull Request
✅ After code review suggestions are applied

## After Commit
Next step: Create PR using `/commit-push-pr`

## Learn More
See CLAUDE.md section "Git Commit Format"
