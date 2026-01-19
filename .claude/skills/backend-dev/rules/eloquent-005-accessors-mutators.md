---
id: eloquent-005-accessors-mutators
title: Accessors and Mutators
impact: MEDIUM
impactDescription: "Ensures consistent data transformation on read/write"
category: eloquent
tags: [eloquent, model, accessor, mutator]
relatedRules: [eloquent-003-model-casts]
---

## Why This Matters

Accessors transform data when reading from model, mutators when writing. They encapsulate transformation logic in the model, ensuring consistency regardless of where data is accessed or modified.

## Bad Example

```php
// Problem: Transformation logic scattered
// In controller
$fullName = ucfirst($user->first_name) . ' ' . ucfirst($user->last_name);

// In view
{{ ucfirst($user->first_name) }} {{ ucfirst($user->last_name) }}

// In API resource
'name' => ucfirst($user->first_name) . ' ' . ucfirst($user->last_name),

// Saving
$user->first_name = strtolower(trim($request->first_name));
```

**Why it's wrong:**
- Logic duplicated
- Inconsistent formatting
- Easy to forget transformation
- Hard to change globally

## Good Example

```php
// Laravel 11+ Attribute syntax
use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends Model
{
    // Accessor only
    protected function fullName(): Attribute
    {
        return Attribute::get(
            fn () => "{$this->first_name} {$this->last_name}"
        );
    }

    // Accessor + Mutator
    protected function firstName(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => ucfirst($value),
            set: fn (string $value) => strtolower(trim($value)),
        );
    }

    // Computed attribute with caching
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->avatar
                ? Storage::url($this->avatar)
                : 'https://ui-avatars.com/api/?name=' . urlencode($this->full_name)
        )->shouldCache();
    }

    // Add to appends for JSON serialization
    protected $appends = ['full_name', 'avatar_url'];
}

// Usage
$user->full_name; // "John Doe"
$user->first_name = '  JOHN  '; // Stored as "john"
$user->first_name; // Retrieved as "John"
$user->avatar_url; // Full URL
```

**Why it's better:**
- Transformation centralized
- Consistent everywhere
- Automatic on access/save
- Can add to JSON output

## Project-Specific Notes

**BotFacebook Common Accessors:**

```php
// Bot model
protected function displayName(): Attribute
{
    return Attribute::get(
        fn () => $this->name ?: "Bot #{$this->id}"
    );
}

protected function platformIcon(): Attribute
{
    return Attribute::get(fn () => match($this->platform) {
        'line' => 'line-icon.svg',
        'telegram' => 'telegram-icon.svg',
        default => 'default-icon.svg',
    });
}

// Message model
protected function formattedContent(): Attribute
{
    return Attribute::get(
        fn () => nl2br(e($this->content))
    );
}
```

**When to Use What:**
- Simple types → `$casts`
- Complex transformation → Accessor/Mutator
- Database stored differently → Both

## References

- [Laravel Accessors & Mutators](https://laravel.com/docs/eloquent-mutators#accessors-and-mutators)
