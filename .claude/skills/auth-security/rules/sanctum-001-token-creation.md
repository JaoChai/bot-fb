---
id: sanctum-001-token-creation
title: Secure Token Creation
impact: HIGH
impactDescription: "Weak tokens enable session hijacking"
category: sanctum
tags: [auth, sanctum, token, security]
relatedRules: [sanctum-004-token-expiration, sanctum-005-token-abilities]
---

## Why This Matters

API tokens are the keys to user accounts. Improperly created or managed tokens can be stolen, guessed, or misused to impersonate users.

## Threat Model

**Attack Vector:** Token theft via XSS, logging, or interception
**Impact:** Full account takeover
**Likelihood:** High if tokens are exposed or predictable

## Bad Example

```php
// Creating token without name (hard to audit)
$token = $user->createToken('')->plainTextToken;

// Storing token in response headers
return response()
    ->json(['user' => $user])
    ->header('X-Auth-Token', $token); // Logged by proxies!

// Using short/predictable token names
$token = $user->createToken('t')->plainTextToken;
```

**Why it's vulnerable:**
- Unnamed tokens can't be audited
- Headers are logged by proxies/CDNs
- No way to identify token purpose

## Good Example

```php
// Named token with purpose and timestamp
$token = $user->createToken(
    'web-' . now()->format('Y-m-d-His')
)->plainTextToken;

// Token with abilities
$token = $user->createToken('api-client', [
    'bot:read',
    'bot:create',
])->plainTextToken;

// Return token in response body only
return response()->json([
    'token' => $token,
    'token_type' => 'Bearer',
    'expires_at' => now()->addDays(7)->toIso8601String(),
    'user' => new UserResource($user),
]);
```

**Why it's secure:**
- Named tokens for audit trail
- Scoped abilities limit damage
- Token in body, not headers

## Audit Command

```bash
# Find token creation without abilities
grep -rn "createToken(" --include="*.php" app/ | grep -v "\["
```

## Project-Specific Notes

**BotFacebook Token Pattern:**

```php
// AuthController.php
public function login(LoginRequest $request): JsonResponse
{
    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    // Revoke old tokens (security best practice)
    $user->tokens()->delete();

    // Create named token with standard abilities
    $token = $user->createToken(
        'web-' . $request->userAgent(),
        ['bot:*', 'conversation:*', 'knowledge:*']
    )->plainTextToken;

    return response()->json([
        'token' => $token,
        'user' => new UserResource($user),
    ]);
}
```
