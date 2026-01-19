---
id: migration-010-change-column-type
title: Safe Column Type Changes
impact: MEDIUM
impactDescription: "Column type changes require careful handling to avoid data loss"
category: migration
tags: [migration, type-change, alter, column]
relatedRules: [safety-003-column-type-change]
---

## Why This Matters

Changing column types in PostgreSQL can be simple (widening) or complex (narrowing/incompatible). Understanding which changes are safe helps avoid data loss and failed migrations.

## Safe vs Unsafe Changes

| Change | Safe? | Notes |
|--------|-------|-------|
| varchar(50) → varchar(100) | Yes | Widening is safe |
| varchar(100) → varchar(50) | No | May truncate |
| integer → bigint | Yes | Widening is safe |
| bigint → integer | No | May overflow |
| varchar → text | Yes | text is unlimited |
| text → varchar | No | May truncate |
| decimal(8,2) → decimal(10,4) | Yes | More precision |
| float → decimal | Maybe | Precision differences |

## Good Example

```php
// Safe: Widening
public function up(): void
{
    Schema::table('products', function (Blueprint $table) {
        $table->string('name', 255)->change(); // was 100
        $table->bigInteger('view_count')->change(); // was integer
    });
}

// Unsafe: Use migration pattern
public function up(): void
{
    // 1. Add new column
    Schema::table('products', function (Blueprint $table) {
        $table->string('short_name', 50)->nullable();
    });

    // 2. Copy with truncation handling
    DB::statement("
        UPDATE products
        SET short_name = LEFT(name, 50)
    ");

    // 3. Log truncated values
    $truncated = DB::table('products')
        ->whereRaw('LENGTH(name) > 50')
        ->count();
    Log::warning("Truncated {$truncated} product names");
}

// Later migration: swap columns
public function up(): void
{
    Schema::table('products', function (Blueprint $table) {
        $table->dropColumn('name');
        $table->renameColumn('short_name', 'name');
    });
}
```

## Project-Specific Notes

**BotFacebook Type Change Checklist:**

```markdown
Before changing type:
- [ ] Identify current max value/length
- [ ] Verify new type can hold all data
- [ ] Test on Neon branch
- [ ] Create backup of affected column
- [ ] Plan rollback strategy
```
