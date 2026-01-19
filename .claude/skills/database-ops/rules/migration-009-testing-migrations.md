---
id: migration-009-testing-migrations
title: Testing Migrations Before Production
impact: MEDIUM
impactDescription: "Untested migrations cause deployment failures"
category: migration
tags: [migration, testing, neon, branch]
relatedRules: [gotcha-002-migration-production-fail]
---

## Why This Matters

Migrations that work locally often fail in production due to data differences, constraints, or scale. Testing on a copy of production data catches issues before they cause deployment failures.

## Bad Example

```bash
# Test only on empty local database
php artisan migrate:fresh
php artisan migrate
# "It works!" - but production has 1M rows...
```

**Why it's wrong:**
- Empty tables don't test constraints
- Scale issues not discovered
- Production-specific data not tested

## Good Example

```bash
# 1. Create Neon branch from production
neon branches create --name test-migration --parent main

# 2. Run migration on branch
DATABASE_URL="postgres://...@test-branch.neon.tech/db" \
php artisan migrate

# 3. Verify data integrity
DATABASE_URL="postgres://...@test-branch.neon.tech/db" \
php artisan tinker --execute="App\Models\Bot::count()"

# 4. Delete branch after testing
neon branches delete test-migration
```

**Why it's better:**
- Tests against real data copy
- Catches constraint violations
- Validates performance at scale
- Safe to fail

## Project-Specific Notes

**BotFacebook Testing Workflow:**

```php
// In CI/CD pipeline
- name: Test Migration
  run: |
    # Create branch via Neon API
    BRANCH_ID=$(curl -X POST "https://console.neon.tech/api/v2/projects/$PROJECT/branches" \
      -H "Authorization: Bearer $NEON_API_KEY" \
      -d '{"branch":{"parent_id":"main"}}' | jq -r '.branch.id')

    # Run migration
    DATABASE_URL="postgres://...@$BRANCH_ID.neon.tech/db" php artisan migrate

    # Cleanup
    curl -X DELETE "https://console.neon.tech/api/v2/projects/$PROJECT/branches/$BRANCH_ID"
```

## MCP Tools

```
# Create test branch
mcp__neon__create_branch(
    projectId="your-project",
    branchName="test-migration"
)

# Test migration on branch
mcp__neon__prepare_database_migration(
    projectId="your-project",
    branchId="test-branch-id",
    migrationSql="ALTER TABLE bots ADD COLUMN test VARCHAR(50)"
)
```
