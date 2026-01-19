---
id: safety-003-column-type-change
title: Column Type Changes Risk Data Loss
impact: CRITICAL
impactDescription: "Changing column types can silently truncate or corrupt data"
category: safety
tags: [safety, type-change, data-loss, conversion]
relatedRules: [safety-002-drop-column-production]
---

## Why This Matters

Changing column types in PostgreSQL can cause silent data loss or corruption. Converting `text` to `varchar(50)` truncates longer strings. Converting `decimal` to `integer` loses precision. These changes are difficult to detect until data is already corrupted.

## Bad Example

```php
// Problem: Type change with potential data loss
public function up(): void
{
    Schema::table('products', function (Blueprint $table) {
        // varchar(100) → varchar(50): Truncates strings > 50 chars!
        $table->string('name', 50)->change();

        // decimal → integer: Loses decimal places!
        $table->integer('price')->change();

        // text → string: May truncate large text!
        $table->string('description')->change();
    });
}
```

**Why it's wrong:**
- Silent data truncation
- No warning before data loss
- Cannot recover truncated data

## Good Example

```php
public function up(): void
{
    // Step 1: Validate data fits new type
    $oversized = DB::table('products')
        ->whereRaw('LENGTH(name) > 50')
        ->count();

    if ($oversized > 0) {
        throw new \Exception(
            "{$oversized} products have names > 50 chars. " .
            "Fix data before changing column type."
        );
    }

    // Step 2: Safe type change after validation
    Schema::table('products', function (Blueprint $table) {
        $table->string('name', 50)->change();
    });
}

// OR: Migration pattern for incompatible types
public function up(): void
{
    // 1. Add new column
    Schema::table('products', function (Blueprint $table) {
        $table->integer('price_cents')->nullable();
    });

    // 2. Migrate data with conversion
    DB::statement('UPDATE products SET price_cents = ROUND(price * 100)');

    // 3. In separate migration: drop old, rename new
}
```

**Why it's better:**
- Validation before change
- Explicit data conversion
- No silent data loss

## Project-Specific Notes

**BotFacebook Type Change Patterns:**

| From | To | Safe? | Pattern |
|------|-----|-------|---------|
| varchar(50) → varchar(100) | Yes | Direct change |
| varchar(100) → varchar(50) | No | Validate + truncate handling |
| text → varchar | No | Validate length first |
| decimal → integer | No | Add new column, convert |
| string → json | No | Add new column, parse |

**Safe Widening:**
```php
// Safe: Making column larger
$table->string('name', 255)->change(); // was 100
$table->text('description')->change(); // was varchar
```

**Unsafe Narrowing:**
```php
// Dangerous: Making column smaller
// Always validate first!
$count = DB::table('t')->whereRaw('LENGTH(col) > ?', [50])->count();
if ($count > 0) {
    throw new \Exception("Data would be truncated");
}
```

## MCP Tools

```
# Check data fits new size
mcp__neon__run_sql(
    projectId="your-project",
    sql="SELECT COUNT(*) FROM products WHERE LENGTH(name) > 50"
)
```

## References

- [PostgreSQL Type Conversion](https://www.postgresql.org/docs/current/typeconv.html)
