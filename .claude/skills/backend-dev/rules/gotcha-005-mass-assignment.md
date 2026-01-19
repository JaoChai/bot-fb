---
id: gotcha-005-mass-assignment
title: Model Mass Assignment Protection
impact: HIGH
impactDescription: "Prevents unauthorized field modifications through mass assignment attacks"
category: gotcha
tags: [security, model, mass-assignment, fillable]
relatedRules: [security-001-input-validation]
---

## Why This Matters

Mass assignment allows setting multiple model attributes at once. Without `$fillable` or `$guarded`, attackers can modify unintended fields like `is_admin`, `role`, or `user_id` by adding extra fields to requests.

## Bad Example

```php
// Problem: No mass assignment protection
class Bot extends Model
{
    // No $fillable defined!
}

// Controller
public function store(Request $request)
{
    // Attacker adds: { "user_id": 1, "is_admin": true }
    $bot = Bot::create($request->all()); // Dangerous!
}
```

**Why it's wrong:**
- Any field can be set via request
- Attacker can modify `user_id` to own other users' bots
- Can set admin flags or sensitive fields
- Silent security vulnerability

## Good Example

```php
// Solution: Define $fillable explicitly
class Bot extends Model
{
    protected $fillable = [
        'name',
        'platform',
        'description',
        // Only fields that users should modify
    ];

    // OR use $guarded for blacklist approach
    // protected $guarded = ['id', 'user_id', 'is_admin'];
}

// Controller - use validated data
public function store(StoreBotRequest $request)
{
    $bot = auth()->user()->bots()->create($request->validated());
    return new BotResource($bot);
}
```

**Why it's better:**
- Only listed fields can be mass assigned
- `user_id` set through relationship, not request
- Combined with FormRequest validation
- Defense in depth

## Project-Specific Notes

**BotFacebook Sensitive Fields (never in $fillable):**
- `user_id` - Set via relationship
- `team_id` - Set via relationship
- `api_key` - Generated, not user-provided
- `is_admin` - Never mass assignable
- `email_verified_at` - System managed

**Pattern for User-owned Resources:**
```php
// Always create through relationship
$bot = auth()->user()->bots()->create($validated);

// Never trust user_id from request
$bot = Bot::create(['user_id' => $request->user_id]); // BAD!
```

## References

- [Laravel Mass Assignment](https://laravel.com/docs/eloquent#mass-assignment)
