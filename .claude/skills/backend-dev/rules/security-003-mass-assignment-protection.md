---
id: security-003-mass-assignment-protection
title: Mass Assignment Protection
impact: HIGH
impactDescription: "Prevents attackers from modifying sensitive fields through request data"
category: security
tags: [security, mass-assignment, model, validation]
relatedRules: [gotcha-005-mass-assignment, security-001-input-validation]
---

## Why This Matters

Mass assignment vulnerabilities allow attackers to modify fields they shouldn't access by adding extra fields to requests. Without protection, attackers can escalate privileges, change ownership, or modify sensitive settings.

## Bad Example

```php
// Problem: No protection - any field can be set
class User extends Model
{
    // No $fillable or $guarded!
}

public function update(Request $request)
{
    // Attacker adds: { "is_admin": true, "role": "superuser" }
    auth()->user()->update($request->all()); // Sets is_admin!
}
```

**Why it's wrong:**
- Any field modifiable
- Privilege escalation
- Data tampering
- Silent vulnerability

## Good Example

```php
// Model protection
class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'avatar',
        'preferences',
    ];

    // Explicitly non-fillable (for documentation)
    // 'is_admin', 'role', 'subscription_tier' - not in fillable
}

// OR use $guarded (blacklist approach)
class User extends Model
{
    protected $guarded = [
        'id',
        'is_admin',
        'role',
        'email_verified_at',
        'password', // Only settable via specific method
    ];
}

// Controller with validated data only
public function update(UpdateUserRequest $request)
{
    // Only validated fields, only fillable fields
    auth()->user()->update($request->validated());

    return new UserResource(auth()->user());
}

// For sensitive field updates, use dedicated methods
public function promoteToAdmin(User $user)
{
    $this->authorize('promote', $user);

    // Explicitly set, bypassing mass assignment
    $user->is_admin = true;
    $user->save();
}
```

**Why it's better:**
- Whitelist approach
- Combined with FormRequest
- Sensitive fields protected
- Explicit updates for special fields

## Project-Specific Notes

**BotFacebook Sensitive Fields:**

```php
// User model - never mass assignable
// - is_admin
// - role
// - subscription_tier
// - email_verified_at
// - api_key (generated)

// Bot model - never mass assignable
// - user_id (set via relationship)
// - api_key (generated)
// - webhook_secret (generated)

// Safe pattern for ownership
$bot = auth()->user()->bots()->create($request->validated());
// user_id set via relationship, not request
```

**Audit Command:**
```bash
# Check models without $fillable or $guarded
grep -rL "fillable\|guarded" app/Models --include="*.php"
```

## References

- [Laravel Mass Assignment](https://laravel.com/docs/eloquent#mass-assignment)
