---
id: neon-001-branching
title: Use Neon Branches for Safe Testing
impact: HIGH
impactDescription: "Test migrations and changes on branches before production"
category: neon
tags: [neon, branch, testing, migration]
relatedRules: [migration-009-testing-migrations]
---

## Why This Matters

Neon branches are instant, copy-on-write clones of your database. You can test migrations, debug issues, or experiment without affecting production. If something goes wrong, just delete the branch.

## Bad Example

```bash
# Testing directly on production
php artisan migrate
# Oops, migration failed - production affected!

# Or testing on empty local database
# Doesn't catch real data issues
```

**Why it's wrong:**
- Production at risk
- No real data to test against
- Mistakes are hard to undo

## Good Example

```bash
# 1. Create branch from production
neon branches create --name test-migration --parent main

# 2. Get branch connection string
neon connection-string test-migration

# 3. Test migration on branch
DATABASE_URL="postgres://...@test-migration.neon.tech/db" \
php artisan migrate

# 4. Verify data integrity
DATABASE_URL="postgres://...@test-migration.neon.tech/db" \
php artisan tinker --execute="Bot::count()"

# 5. If successful, run on production
php artisan migrate

# 6. Delete test branch
neon branches delete test-migration
```

**Why it's better:**
- Test against real data copy
- Production never touched during testing
- Instant rollback (delete branch)

## Project-Specific Notes

**BotFacebook Branch Workflow:**

```bash
# Quick branch for testing
neon branches create --name $(whoami)-test

# List branches
neon branches list

# Reset branch to parent state
neon branches reset test-migration --parent main

# Delete all test branches
neon branches list | grep test- | xargs -I {} neon branches delete {}
```

**MCP Tool:**
```
# Create branch
mcp__neon__create_branch(
    projectId="your-project",
    branchName="test-migration"
)

# Test migration
mcp__neon__prepare_database_migration(
    projectId="your-project",
    branchId="branch-id",
    migrationSql="ALTER TABLE bots ADD COLUMN test VARCHAR(50)"
)
```

**Branch Naming:**
- `test-{feature}` - Feature testing
- `debug-{issue}` - Issue debugging
- `dev-{username}` - Developer sandbox
