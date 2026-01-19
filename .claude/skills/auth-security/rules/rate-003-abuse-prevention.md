---
id: rate-003-abuse-prevention
title: Prevent API Abuse Patterns
impact: HIGH
impactDescription: "Sophisticated abuse bypasses simple rate limits"
category: rate
tags: [rate-limiting, abuse, security, ddos]
relatedRules: [rate-001-api-rate-limiting, sanctum-006-login-throttling]
---

## Why This Matters

Simple rate limits can be bypassed by distributed attacks, credential stuffing, or enumeration attacks. Additional abuse prevention measures are needed.

## Threat Model

**Attack Vector:** Distributed attacks, credential stuffing, enumeration
**Impact:** Account compromise, data scraping, service abuse
**Likelihood:** Medium - automated tools make this easy

## Bad Example

```php
// Only IP-based limiting
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->ip());
});
// Attacker uses multiple IPs

// No enumeration protection
public function checkEmail(Request $request)
{
    $exists = User::where('email', $request->email)->exists();
    return ['exists' => $exists];  // Allows email enumeration
}

// No failed attempt tracking
public function login(Request $request)
{
    if (!Auth::attempt($request->only('email', 'password'))) {
        return response()->json(['error' => 'Invalid credentials'], 401);
    }
    // Attacker can try unlimited passwords from different IPs
}
```

**Why it's vulnerable:**
- Single factor limiting
- Enumeration possible
- No persistent tracking
- Easy to bypass

## Good Example

```php
// Multi-factor rate limiting
RateLimiter::for('login', function (Request $request) {
    // Combine IP + email for limit key
    $key = $request->ip() . '|' . Str::lower($request->input('email'));

    return [
        // Per IP: 10 attempts/minute
        Limit::perMinute(10)->by($request->ip()),
        // Per email: 5 attempts/minute (regardless of IP)
        Limit::perMinute(5)->by(Str::lower($request->input('email'))),
        // Combined: stricter
        Limit::perMinute(3)->by($key),
    ];
});

// Enumeration-safe responses
public function checkEmail(Request $request)
{
    // Always return same response
    return ['message' => 'If this email exists, you will receive a reset link.'];
}

public function register(Request $request)
{
    // Same error for existing email
    if (User::where('email', $request->email)->exists()) {
        // Don't reveal that email exists
        // Just "process" and send no email
        return ['message' => 'Check your email for verification link.'];
    }

    // Actually create user...
}

// Failed attempt tracking
class LoginController extends Controller
{
    public function login(Request $request)
    {
        $email = $request->email;

        // Check if locked out
        if ($this->isLockedOut($email)) {
            return response()->json([
                'error' => 'Too many attempts. Try again later.',
                'retry_after' => $this->getLockoutTime($email),
            ], 429);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            $this->incrementFailedAttempts($email);
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $this->clearFailedAttempts($email);
        return $this->respondWithToken();
    }

    private function isLockedOut(string $email): bool
    {
        $attempts = Cache::get("login_attempts:{$email}", 0);
        return $attempts >= 5;
    }

    private function incrementFailedAttempts(string $email): void
    {
        $key = "login_attempts:{$email}";
        $attempts = Cache::increment($key);

        if ($attempts === 1) {
            Cache::put($key, 1, now()->addMinutes(15));
        }

        // Log suspicious activity
        if ($attempts >= 3) {
            Log::warning('Multiple failed login attempts', [
                'email' => $email,
                'attempts' => $attempts,
                'ip' => request()->ip(),
            ]);
        }
    }
}

// Honeypot fields
public function register(RegisterRequest $request)
{
    // Hidden field should be empty
    if ($request->filled('website')) {  // Honeypot
        Log::warning('Bot detected', ['ip' => $request->ip()]);
        // Return fake success
        return ['message' => 'Registration successful'];
    }

    // Real registration...
}
```

**Why it's secure:**
- Multi-factor limiting
- Enumeration-safe responses
- Persistent attempt tracking
- Honeypot detection

## Audit Command

```bash
# Check for enumeration vulnerabilities
grep -rn "exists()\|->count()" app/Http/Controllers/Auth/ --include="*.php"

# Check login attempt tracking
grep -rn "failed\|attempts\|lockout" app/ --include="*.php"

# Check for honeypot
grep -rn "honeypot\|website" app/Http/Requests/ --include="*.php"
```

## Project-Specific Notes

**BotFacebook Abuse Prevention:**

```php
// Multi-factor login limiting
RateLimiter::for('login', function (Request $request) {
    return [
        Limit::perMinute(10)->by($request->ip()),
        Limit::perMinute(5)->by(Str::lower($request->input('email'))),
    ];
});

// Webhook abuse prevention
class WebhookController extends Controller
{
    public function handle(Request $request, Bot $bot)
    {
        // Verify signature first (no processing without valid sig)
        if (!$this->verifySignature($request, $bot)) {
            // Don't reveal it's signature failure
            return response('OK', 200);
        }

        // Track webhook volume
        $key = "webhook_volume:{$bot->id}";
        $count = Cache::increment($key);

        if ($count === 1) {
            Cache::put($key, 1, now()->addMinutes(5));
        }

        // Alert on unusual volume
        if ($count > 100) {
            Log::warning('High webhook volume', [
                'bot_id' => $bot->id,
                'count' => $count,
            ]);
        }

        // Process webhook...
    }
}
```
