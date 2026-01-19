---
id: db-004-migration-refactor
title: Migration Refactoring
impact: MEDIUM
impactDescription: "Safely refactor database schema"
category: db
tags: [migration, schema, database, safety]
relatedRules: [db-003-add-index, laravel-002-extract-service]
---

## Code Smell

- Column type needs changing
- Table needs splitting
- Column needs renaming
- Data needs transformation
- Schema needs cleanup

## Root Cause

1. Requirements evolved
2. Poor initial design
3. Performance optimization needed
4. Normalization required
5. Legacy schema cleanup

## When to Apply

**Apply when:**
- Schema change required
- Data needs migrating
- Column type change needed
- Table split/merge needed

**Don't apply when:**
- Can solve at application level
- Risk outweighs benefit
- No rollback strategy

## Solution

### Pattern 1: Safe Column Rename

```php
// DON'T: Direct rename in production
Schema::table('bots', function (Blueprint $table) {
    $table->renameColumn('name', 'title');  // ❌ Breaks running code
});

// DO: Two-phase rename
// Phase 1: Add new column, dual write
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('title')->nullable()->after('name');
    });

    // Copy existing data
    DB::statement('UPDATE bots SET title = name');
}

// Phase 2: (After app code updated) Drop old column
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->dropColumn('name');
    });
}
```

### Pattern 2: Safe Column Type Change

```php
// DON'T: Direct type change can fail/lock
Schema::table('messages', function (Blueprint $table) {
    $table->text('content')->change();  // ❌ May lock table
});

// DO: Create new column, migrate, swap
public function up(): void
{
    // Step 1: Add new column
    Schema::table('messages', function (Blueprint $table) {
        $table->text('content_new')->nullable();
    });

    // Step 2: Copy data (batch for large tables)
    DB::statement('UPDATE messages SET content_new = content');

    // Step 3: Swap columns (in separate migration after verification)
    Schema::table('messages', function (Blueprint $table) {
        $table->dropColumn('content');
        $table->renameColumn('content_new', 'content');
    });
}
```

### Pattern 3: Split Large Table

```php
// Before: Fat table
// messages: id, conversation_id, content, role, tokens, embedding, metadata

// After: Normalized tables
public function up(): void
{
    // Create new table
    Schema::create('message_analytics', function (Blueprint $table) {
        $table->id();
        $table->foreignId('message_id')->constrained()->onDelete('cascade');
        $table->integer('tokens')->default(0);
        $table->vector('embedding', 1536)->nullable();
        $table->jsonb('metadata')->nullable();
        $table->timestamps();
    });

    // Migrate data
    DB::statement('
        INSERT INTO message_analytics (message_id, tokens, embedding, metadata, created_at, updated_at)
        SELECT id, tokens, embedding, metadata, created_at, updated_at FROM messages
    ');

    // Drop from original (separate migration)
    // Schema::table('messages', function (Blueprint $table) {
    //     $table->dropColumn(['tokens', 'embedding', 'metadata']);
    // });
}
```

### Pattern 4: Add NOT NULL with Default

```php
// DON'T: Add NOT NULL to existing table directly
$table->boolean('is_active')->default(true);  // ❌ Fails if rows exist

// DO: Multi-step approach
public function up(): void
{
    // Step 1: Add nullable column with default
    Schema::table('bots', function (Blueprint $table) {
        $table->boolean('is_active')->nullable()->default(true);
    });

    // Step 2: Backfill existing rows
    DB::statement('UPDATE bots SET is_active = true WHERE is_active IS NULL');

    // Step 3: Make NOT NULL (separate migration)
    // Schema::table('bots', function (Blueprint $table) {
    //     $table->boolean('is_active')->default(true)->nullable(false)->change();
    // });
}
```

### Pattern 5: Batch Data Migration

```php
public function up(): void
{
    // For large tables, batch the update
    $batchSize = 1000;
    $processed = 0;

    do {
        $affected = DB::table('messages')
            ->whereNull('processed_at')
            ->limit($batchSize)
            ->update([
                'processed_at' => now(),
                'content_normalized' => DB::raw('LOWER(content)'),
            ]);

        $processed += $affected;

        // Log progress
        $this->command->info("Processed {$processed} records");

    } while ($affected > 0);
}
```

### Step-by-Step for Safe Migration

1. **Assess impact**
   - Table size (rows)
   - Active usage
   - Locking implications
   - Rollback strategy

2. **Plan phases**
   - Phase 1: Additive changes only
   - Phase 2: Data migration
   - Phase 3: Code updates
   - Phase 4: Cleanup (drop old)

3. **Create migration**
   ```bash
   php artisan make:migration refactor_messages_add_content_text
   ```

4. **Always include down()**
   ```php
   public function down(): void
   {
       Schema::table('messages', function (Blueprint $table) {
           $table->dropColumn('content_new');
       });
   }
   ```

5. **Test locally**
   ```bash
   php artisan migrate
   php artisan migrate:rollback
   php artisan migrate
   ```

6. **Test on staging with production data**

7. **Deploy with monitoring**

## Verification

```bash
# Check migration status
php artisan migrate:status

# Rollback last migration
php artisan migrate:rollback

# Check table structure
php artisan tinker
>>> Schema::getColumnListing('messages')
>>> Schema::getColumnType('messages', 'content')
```

## Anti-Patterns

- **No rollback**: Always write down() method
- **One big migration**: Split into atomic changes
- **Ignoring locks**: Use CONCURRENTLY for indexes
- **No data backup**: Always backup before risky migrations
- **Mixing schema + data**: Separate migrations

## Project-Specific Notes

**BotFacebook Context:**
- Use Neon branching for safe testing
- Large tables: messages, knowledge_chunks
- Vector columns need special handling
- Run migrations during low-traffic periods
