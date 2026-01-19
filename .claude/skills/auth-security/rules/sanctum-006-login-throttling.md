---
id: sanctum-006-login-throttling
title: Login Attempt Throttling
impact: HIGH
impactDescription: "Unlimited login attempts enable brute force attacks"
category: sanctum
tags: [auth, sanctum, throttle, brute-force]
relatedRules: [rate-001-api-rate-limiting]
---

## Why This Matters

Without rate limiting, attackers can try millions of password combinations. Even with strong passwords, brute force eventually succeeds.

## Threat Model

**Attack Vector:** Automated brute force password guessing
**Impact:** Account takeover
**Likelihood:** High - login endpoints are always targeted

## Bad Example

```php
// No throttling on login
Route::post('/login', [AuthController::class, 'login']);

// Controller without throttling
public function login(Request $request)
{
    // Can be called unlimited times
    $user = User::where('email', $request->email)->first();
    if ($user && Hash::check($request->password, $user->password)) {
        return $this->issueToken($user);
    }
    return response()->json(['error' => 'Invalid credentials'], 401);
}
```

**Why it's vulnerable:**
- Unlimited attempts
- No lockout
- Easy to automate

## Good Example

```php
// Apply throttle middleware
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// Define the throttle in AppServiceProvider
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)
        ->by($request->email . '|' . $request->ip())
        ->response(function () {
            return response()->json([
                'error' => 'Too many login attempts. Please try again later.',
                'retry_after' => 60,
            ], 429);
        });
});

// Controller with additional protection
public function login(LoginRequest $request)
{
    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        // Log failed attempt
        Log::warning('Failed login attempt', [
            'email' => $request->email,
            'ip' => $request->ip(),
        ]);

        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    // Clear rate limit on success
    RateLimiter::clear('login:' . $request->email . '|' . $request->ip());

    return $this->issueToken($user);
}
```

**Why it's secure:**
- Limited attempts per minute
- IP + email combination
- Logged for monitoring
- Clear on success

## Audit Command

```bash
# Check login route for throttle
grep -A 2 "login" routes/api.php | grep throttle

# Check rate limiter definitions
grep -rn "RateLimiter::for" app/Providers/
```

## Project-Specific Notes

**BotFacebook Login Protection:**

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    // Login: 5 attempts per minute per email+IP
    RateLimiter::for('login', function (Request $request) {
        return Limit::perMinute(5)
            ->by(Str::lower($request->email) . '|' . $request->ip());
    });

    // Password reset: 3 attempts per minute per email
    RateLimiter::for('password-reset', function (Request $request) {
        return Limit::perMinute(3)
            ->by(Str::lower($request->email));
    });
}

// routes/api.php
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::post('forgot-password', [PasswordResetController::class, 'send'])
        ->middleware('throttle:password-reset');
});
```
