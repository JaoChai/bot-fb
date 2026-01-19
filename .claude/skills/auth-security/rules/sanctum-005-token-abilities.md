---
id: sanctum-005-token-abilities
title: Token Abilities (Scopes)
impact: MEDIUM
impactDescription: "Tokens with full access can do more damage if stolen"
category: sanctum
tags: [auth, sanctum, abilities, scopes, authorization]
relatedRules: [sanctum-001-token-creation, policy-001-resource-policies]
---

## Why This Matters

Token abilities limit what a stolen token can do. A read-only token can't delete data. A bot-only token can't access user settings.

## Threat Model

**Attack Vector:** Stolen token with excessive permissions
**Impact:** Attacker can perform any action
**Likelihood:** Medium - principle of least privilege

## Bad Example

```php
// Token with no abilities (has all access)
$token = $user->createToken('api')->plainTextToken;

// Not checking abilities
public function deletBot(Bot $bot)
{
    $this->authorize('delete', $bot);
    // Doesn't check token ability
    $bot->delete();
}

// Wildcard abilities everywhere
$token = $user->createToken('api', ['*'])->plainTextToken;
```

**Why it's vulnerable:**
- Full access tokens
- No least-privilege
- Stolen token = full compromise

## Good Example

```php
// Create token with specific abilities
$token = $user->createToken('api', [
    'bot:read',
    'bot:create',
    'conversation:read',
])->plainTextToken;

// Check ability in controller
public function destroy(Bot $bot)
{
    // Check both policy and token ability
    $this->authorize('delete', $bot);

    if (!request()->user()->tokenCan('bot:delete')) {
        abort(403, 'Token does not have delete permission');
    }

    $bot->delete();
}

// Or use middleware
Route::delete('/bots/{bot}', [BotController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'ability:bot:delete']);
```

**Why it's secure:**
- Minimal permissions per token
- Double-check: policy + ability
- Easy to audit token scope

## Audit Command

```bash
# Find tokens created without abilities
grep -rn "createToken(" --include="*.php" app/ | grep -v "\["

# Check ability middleware usage
grep -rn "ability:" routes/api.php
```

## Project-Specific Notes

**BotFacebook Token Abilities:**

```php
// Standard ability sets
class TokenAbilities
{
    // Read-only API access
    public const READ_ONLY = [
        'bot:read',
        'conversation:read',
        'knowledge:read',
        'analytics:read',
    ];

    // Full API access (for web app)
    public const FULL_ACCESS = [
        'bot:*',
        'conversation:*',
        'knowledge:*',
        'analytics:*',
    ];

    // Webhook-only (for platform integrations)
    public const WEBHOOK = [
        'conversation:create',
        'message:create',
    ];
}

// Usage
$token = $user->createToken('web', TokenAbilities::FULL_ACCESS)->plainTextToken;
$apiKey = $user->createToken('external-api', TokenAbilities::READ_ONLY)->plainTextToken;
```
