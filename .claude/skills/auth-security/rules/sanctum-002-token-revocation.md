---
id: sanctum-002-token-revocation
title: Token Revocation on Security Events
impact: MEDIUM
impactDescription: "Stale tokens remain valid after password change"
category: sanctum
tags: [auth, sanctum, token, revocation]
relatedRules: [sanctum-001-token-creation]
---

## Why This Matters

When a user changes their password or reports suspicious activity, all existing tokens should be revoked. Stale tokens could still be used by attackers.

## Threat Model

**Attack Vector:** Attacker uses stolen token after user changes password
**Impact:** Continued unauthorized access
**Likelihood:** Medium - requires prior token theft

## Bad Example

```php
// Password change without token revocation
public function changePassword(Request $request)
{
    $user = $request->user();
    $user->update([
        'password' => Hash::make($request->new_password),
    ]);

    return response()->json(['message' => 'Password changed']);
    // Old tokens still valid!
}

// Logout only revokes current token
public function logout(Request $request)
{
    $request->user()->currentAccessToken()->delete();
    // Other devices still logged in!
}
```

**Why it's vulnerable:**
- Old tokens remain valid
- Compromised sessions persist
- No way to force re-authentication

## Good Example

```php
// Password change revokes all tokens
public function changePassword(ChangePasswordRequest $request)
{
    $user = $request->user();

    // Revoke all tokens first
    $user->tokens()->delete();

    // Update password
    $user->update([
        'password' => Hash::make($request->new_password),
    ]);

    // Create new token for current session
    $token = $user->createToken('web-after-password-change')->plainTextToken;

    return response()->json([
        'message' => 'Password changed. All other sessions logged out.',
        'token' => $token,
    ]);
}

// Logout all devices
public function logoutAll(Request $request)
{
    $request->user()->tokens()->delete();

    return response()->json(['message' => 'Logged out from all devices']);
}
```

**Why it's secure:**
- All sessions terminated on password change
- User can revoke all tokens
- Forces re-authentication

## Audit Command

```bash
# Check password change handlers
grep -rn "password.*Hash::make\|bcrypt" --include="*.php" app/ -A 5 | grep -v "tokens()->delete"
```

## Project-Specific Notes

**BotFacebook Token Revocation Events:**

```php
// Events that should revoke tokens:
// 1. Password change
// 2. Email change
// 3. Account deactivation
// 4. Suspicious activity detected
// 5. Manual "logout all" action

// User model observer
class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged('password') || $user->wasChanged('email')) {
            $user->tokens()->delete();
            Log::info('Tokens revoked for user', ['user_id' => $user->id]);
        }
    }
}
```
