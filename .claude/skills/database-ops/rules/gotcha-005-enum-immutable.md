---
id: gotcha-005-enum-immutable
title: PostgreSQL Enums Are Immutable
impact: HIGH
impactDescription: "Cannot remove or rename enum values - only add"
category: gotcha
tags: [gotcha, enum, immutable, postgresql]
relatedRules: [safety-007-enum-changes]
---

## Why This Matters

PostgreSQL enum types can only have values added, not removed or renamed. This makes schema evolution painful. When requirements change, you're stuck with complex migrations.

## Bad Example

```php
// Creates enum type
DB::statement("CREATE TYPE status AS ENUM ('draft', 'active', 'deleted')");

// Later: Need to rename 'deleted' to 'archived'
DB::statement("ALTER TYPE status RENAME VALUE 'deleted' TO 'archived'");
// ERROR: Cannot rename enum values!

// Need to remove 'draft'
DB::statement("ALTER TYPE status DROP VALUE 'draft'");
// ERROR: Cannot remove enum values!
```

**Why it's wrong:**
- Enum values permanent
- Schema changes blocked
- Complex workarounds needed

## Good Example

```php
// Option 1: Use string column instead (recommended)
Schema::create('bots', function (Blueprint $table) {
    $table->string('status', 20)->default('draft');
    $table->index('status');
});

// Validation in model
class Bot extends Model
{
    const STATUSES = ['draft', 'active', 'paused', 'archived'];

    protected static function boot()
    {
        parent::boot();
        static::saving(function ($bot) {
            if (!in_array($bot->status, self::STATUSES)) {
                throw new \InvalidArgumentException("Invalid status");
            }
        });
    }
}

// Option 2: If must use enum, plan for changes
// Create new type, migrate data, drop old type
```

**Why it's better:**
- Strings can be changed freely
- Validation in code, not schema
- Easy to evolve

## Project-Specific Notes

**BotFacebook Convention:** Use strings with constants, not enums.

```php
// config/bot.php
return [
    'statuses' => ['draft', 'active', 'paused', 'archived'],
    'platforms' => ['line', 'telegram', 'messenger'],
];

// Validation in FormRequest
public function rules(): array
{
    return [
        'status' => ['required', 'in:' . implode(',', config('bot.statuses'))],
    ];
}
```
