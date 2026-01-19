---
id: owasp-002-broken-auth
title: OWASP A07 - Broken Authentication
impact: CRITICAL
impactDescription: "Weak authentication allows account takeover"
category: owasp
tags: [owasp, authentication, session, security]
relatedRules: [sanctum-001-token-creation, sanctum-006-login-throttling]
---

## Why This Matters

Broken authentication allows attackers to compromise user accounts through weak passwords, session management flaws, or credential exposure.

## Threat Model

**Attack Vector:** Brute force, credential stuffing, session hijacking
**Impact:** Account takeover, data access
**Likelihood:** High - authentication is always targeted

## Bad Example

```php
// Weak password requirements
$request->validate([
    'password' => 'required|min:4', // Too short!
]);

// No rate limiting
Route::post('/login', [AuthController::class, 'login']);

// Session not invalidated on logout
public function logout()
{
    // Doesn't invalidate session
    return response()->json(['message' => 'Logged out']);
}

// Predictable session IDs
ini_set('session.use_strict_mode', 0);
```

**Why it's vulnerable:**
- Weak passwords easily cracked
- Unlimited login attempts
- Sessions persist after logout
- Predictable session tokens

## Good Example

```php
// Strong password requirements
$request->validate([
    'password' => [
        'required',
        'min:8',
        'confirmed',
        Password::min(8)
            ->mixedCase()
            ->numbers()
            ->uncompromised(), // Check against data breaches
    ],
]);

// Rate limited login
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// Proper logout
public function logout(Request $request)
{
    // Revoke current token
    $request->user()->currentAccessToken()->delete();

    // Invalidate session
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return response()->json(['message' => 'Logged out']);
}

// Secure session configuration
// config/session.php
'secure' => env('SESSION_SECURE_COOKIE', true),
'http_only' => true,
'same_site' => 'lax',
```

**Why it's secure:**
- Strong passwords required
- Brute force prevented
- Sessions properly invalidated
- Secure cookie settings

## Audit Command

```bash
# Check password validation rules
grep -rn "password" app/Http/Requests/ --include="*.php"

# Check session config
grep -rn "secure\|http_only\|same_site" config/session.php

# Check logout implementation
grep -rn "logout" --include="*.php" app/Http/Controllers/
```

## Project-Specific Notes

**BotFacebook Auth Hardening:**

```php
// app/Http/Requests/RegisterRequest.php
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
        'password' => [
            'required',
            'confirmed',
            Password::min(8)->mixedCase()->numbers(),
        ],
    ];
}

// config/session.php
return [
    'lifetime' => 120, // 2 hours
    'expire_on_close' => false,
    'encrypt' => true,
    'secure' => env('APP_ENV') === 'production',
    'http_only' => true,
    'same_site' => 'lax',
];
```
