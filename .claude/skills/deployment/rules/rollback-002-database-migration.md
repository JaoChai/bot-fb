---
id: rollback-002-database-migration
title: Database Migration Rollback
impact: HIGH
impactDescription: "Migration failed or caused issues, need to rollback schema"
category: rollback
tags: [rollback, database, migration, schema]
relatedRules: [rollback-001-procedure, troubleshoot-001-500-errors]
---

## Symptom

- Application errors after migration
- Database schema inconsistent
- Foreign key constraint failures
- Missing or extra columns
- Migration partially applied

## Root Cause

1. Migration failed midway
2. Migration has bugs
3. Data incompatible with new schema
4. Rollback migration not defined
5. Production data edge cases

## Diagnosis

### Quick Check

```bash
# Check migration status
railway exec "php artisan migrate:status"

# Check for recent migration errors
railway logs --filter "migration|SQLSTATE" --lines 50

# Check current schema
railway exec "php artisan tinker --execute=\"Schema::getColumnListing('table_name');\""
```

### Detailed Analysis

```bash
# Get detailed migration status
railway exec "php artisan migrate:status --pending"

# Check failed migrations table
railway exec "php artisan tinker --execute=\"DB::table('migrations')->get();\""

# Verify specific table structure
railway exec "php artisan tinker --execute=\"\\DB::select('SELECT column_name, data_type FROM information_schema.columns WHERE table_name = \\\"users\\\"');\""
```

## Solution

### Fix Steps

1. **Rollback last migration**
```bash
# Rollback last batch
railway exec "php artisan migrate:rollback"

# Rollback specific number of steps
railway exec "php artisan migrate:rollback --step=1"
```

2. **Fix migration then rerun**
```bash
# 1. Rollback the bad migration
railway exec "php artisan migrate:rollback --step=1"

# 2. Fix the migration file locally
# Edit database/migrations/xxxx_migration.php

# 3. Test locally
php artisan migrate:fresh --seed
php artisan test

# 4. Deploy fix
git add .
git commit -m "fix: migration issue"
railway up

# 5. Run migration again
railway exec "php artisan migrate --force"
```

3. **Emergency: Manual schema fix**
```sql
-- If migration rollback doesn't work
-- Connect to database directly

-- Check current state
SELECT column_name, data_type
FROM information_schema.columns
WHERE table_name = 'affected_table';

-- Manual rollback (example)
ALTER TABLE affected_table DROP COLUMN new_column;
-- Or
ALTER TABLE affected_table ADD COLUMN missing_column VARCHAR(255);

-- Update migrations table
DELETE FROM migrations WHERE migration = '2024_01_15_failed_migration';
```

### Migration Best Practices

```php
// Always define down() method
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('new_column')->nullable();
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('new_column');
    });
}

// For data migrations, handle edge cases
public function up(): void
{
    // Schema change
    Schema::table('orders', function (Blueprint $table) {
        $table->string('status')->default('pending');
    });

    // Data migration (handle large tables carefully)
    DB::table('orders')
        ->whereNull('status')
        ->update(['status' => 'unknown']);
}
```

### Runbook: Migration Recovery

```bash
#!/bin/bash
# Migration Recovery Runbook

echo "=== Migration Recovery ==="

# 1. Check current status
echo "1. Current migration status:"
railway exec "php artisan migrate:status"

# 2. Identify failed migration
echo "2. Recent migration logs:"
railway logs --filter "migration" --lines 30

# 3. Rollback options
echo "3. Rollback options:"
echo "   a) Rollback last batch: railway exec 'php artisan migrate:rollback'"
echo "   b) Rollback N steps: railway exec 'php artisan migrate:rollback --step=N'"
echo "   c) Fresh migration (DANGER): railway exec 'php artisan migrate:fresh'"

# 4. After rollback
echo "4. After rollback, verify:"
echo "   - Check migration status"
echo "   - Test application endpoints"
echo "   - Verify data integrity"
```

## Verification

```bash
# Verify migration status
railway exec "php artisan migrate:status"
# All should show "Ran" or specific pending

# Test affected functionality
curl -s https://api.botjao.com/api/affected-endpoint | jq .

# Check for schema errors
railway logs --filter "SQLSTATE|column|table" --lines 50

# Verify data integrity
railway exec "php artisan tinker --execute=\"User::count();\""
```

## Prevention

- Always define `down()` method
- Test migrations on copy of prod data
- Backup before major migrations
- Use transactions for data migrations
- Deploy migrations separately from code

## Project-Specific Notes

**BotFacebook Context:**
- Database: Neon PostgreSQL
- Backup: Via Neon dashboard before migrations
- Rollback: `php artisan migrate:rollback`
- Test: Fresh migration locally first
