---
id: sanctum-004-token-expiration
title: Token Expiration Configuration
impact: HIGH
impactDescription: "Non-expiring tokens remain valid forever if stolen"
category: sanctum
tags: [auth, sanctum, token, expiration]
relatedRules: [sanctum-001-token-creation, sanctum-002-token-revocation]
---

## Why This Matters

Tokens without expiration remain valid indefinitely. A stolen token provides permanent access until manually revoked.

## Threat Model

**Attack Vector:** Stolen token used months/years later
**Impact:** Long-term unauthorized access
**Likelihood:** High - tokens get exposed in logs, backups, etc.

## Bad Example

```php
// config/sanctum.php - No expiration
'expiration' => null, // Tokens never expire!

// Never checking token age
public function someAction(Request $request)
{
    // Token could be years old
    return $this->doSomething();
}
```

**Why it's vulnerable:**
- Stolen tokens valid forever
- Old tokens accumulate
- No forced re-authentication

## Good Example

```php
// config/sanctum.php
'expiration' => 60 * 24 * 7, // 7 days in minutes

// For sensitive actions, check token age
public function sensitiveAction(Request $request)
{
    $token = $request->user()->currentAccessToken();

    // Require recent authentication for sensitive actions
    if ($token->created_at->lt(now()->subMinutes(15))) {
        return response()->json([
            'error' => 'Please re-authenticate for this action',
            'code' => 'REAUTHENTICATION_REQUIRED',
        ], 403);
    }

    return $this->doSensitiveAction();
}

// Prune expired tokens regularly
// In console kernel
$schedule->command('sanctum:prune-expired --hours=168')->daily();
```

**Why it's secure:**
- Tokens auto-expire
- Sensitive actions require recent auth
- Old tokens cleaned up

## Audit Command

```bash
# Check expiration config
grep -rn "expiration" config/sanctum.php

# Count old tokens (should be 0 if pruning)
php artisan tinker --execute="DB::table('personal_access_tokens')->where('created_at', '<', now()->subDays(30))->count()"
```

## Project-Specific Notes

**BotFacebook Token Expiration:**

```php
// config/sanctum.php
return [
    'expiration' => 60 * 24 * 7, // 7 days

    // Guard against very old tokens
    'guard' => ['web'],
];

// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Prune expired tokens daily
    $schedule->command('sanctum:prune-expired --hours=168')
        ->daily()
        ->withoutOverlapping();
}

// Frontend handles token refresh
// On 401 response, redirect to login
api.interceptors.response.use(
    response => response,
    error => {
        if (error.response?.status === 401) {
            localStorage.removeItem('token');
            window.location.href = '/login';
        }
        return Promise.reject(error);
    }
);
```
