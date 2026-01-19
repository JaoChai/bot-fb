---
id: gotcha-002-migration-production-fail
title: Migration Works Locally, Fails in Production
impact: CRITICAL
impactDescription: "Empty local tables don't catch NOT NULL and constraint issues"
category: gotcha
tags: [gotcha, migration, production, testing]
relatedRules: [migration-001-nullable-columns, safety-001-not-null-constraint]
---

## Why This Matters

Your local database is often empty or has minimal test data. Migrations that work fine locally can fail spectacularly in production where tables have millions of rows and real data constraints. This causes deployment failures at the worst possible time.

## Bad Example

```php
// Works locally (empty table), fails in production
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // Local: 0 rows, works fine
        // Prod: 50,000 rows with NULL values, FAILS
        $table->string('phone')->nullable(false)->change();
    });

    Schema::table('orders', function (Blueprint $table) {
        // Local: no long strings
        // Prod: some descriptions are 300+ chars, TRUNCATED
        $table->string('description', 255)->change();
    });
}
```

**Why it's wrong:**
- Empty tables pass any constraint
- Test data doesn't match production reality
- Failures only discovered during deployment

## Good Example

```php
public function up(): void
{
    // Step 1: Check data before migration
    $nullCount = DB::table('users')->whereNull('phone')->count();
    if ($nullCount > 0) {
        throw new \Exception(
            "Migration blocked: {$nullCount} users have NULL phone. " .
            "Backfill data first."
        );
    }

    $longDesc = DB::table('orders')
        ->whereRaw('LENGTH(description) > 255')
        ->count();
    if ($longDesc > 0) {
        throw new \Exception(
            "Migration blocked: {$longDesc} orders have descriptions > 255 chars."
        );
    }

    // Step 2: Proceed with migration
    Schema::table('users', function (Blueprint $table) {
        $table->string('phone')->nullable(false)->change();
    });
}
```

**Why it's better:**
- Pre-flight checks before changes
- Clear error messages
- Fails fast with actionable info

## Project-Specific Notes

**BotFacebook Testing Pattern:**

```bash
# 1. Test on Neon branch with production data copy
neon branches create --name test-migration --parent main

# 2. Run migration on branch
php artisan migrate --database=neon-branch

# 3. Verify success
php artisan migrate:status --database=neon-branch

# 4. Delete branch after testing
neon branches delete test-migration
```

**Pre-Migration Checklist:**
```sql
-- Check for NULL values
SELECT COUNT(*) FROM table WHERE column IS NULL;

-- Check string lengths
SELECT MAX(LENGTH(column)) FROM table;

-- Check for orphaned foreign keys
SELECT COUNT(*) FROM child
LEFT JOIN parent ON child.parent_id = parent.id
WHERE parent.id IS NULL;

-- Check for duplicate unique values
SELECT column, COUNT(*) FROM table
GROUP BY column HAVING COUNT(*) > 1;
```

**Laravel Test Migration:**
```php
// In test
public function test_migration_handles_existing_data(): void
{
    // Create problematic data first
    DB::table('users')->insert(['phone' => null, ...]);

    // Migration should fail gracefully
    $this->expectException(\Exception::class);
    $this->artisan('migrate');
}
```

## MCP Tools

```
# Test migration on Neon branch
mcp__neon__prepare_database_migration(
    projectId="your-project",
    migrationSql="ALTER TABLE users ALTER COLUMN phone SET NOT NULL"
)

# Check data before migration
mcp__neon__run_sql(
    projectId="your-project",
    sql="SELECT COUNT(*) FROM users WHERE phone IS NULL"
)
```

## References

- [Neon Branching](https://neon.tech/docs/introduction/branching)
- [Laravel Migration Testing](https://laravel.com/docs/database-testing)
