---
id: owasp-005-security-misconfig
title: OWASP A05 - Security Misconfiguration
impact: HIGH
impactDescription: "Debug mode, default creds, or missing headers expose vulnerabilities"
category: owasp
tags: [owasp, configuration, headers, security]
relatedRules: [creds-001-env-secrets]
---

## Why This Matters

Security misconfigurations include debug mode in production, missing security headers, default credentials, and exposed error messages.

## Threat Model

**Attack Vector:** Scanning for misconfigurations
**Impact:** Information disclosure, bypass security
**Likelihood:** High - common in rushed deployments

## Bad Example

```php
// .env in production
APP_DEBUG=true  # Exposes stack traces!
APP_ENV=local   # Wrong environment

// Missing security headers
// No CSP, HSTS, X-Frame-Options

// Default credentials
MAIL_PASSWORD=password
DB_PASSWORD=root

// Detailed error messages
public function render($request, Throwable $e)
{
    return response()->json([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(), // Exposes internals!
    ]);
}
```

**Why it's vulnerable:**
- Stack traces reveal code paths
- Missing headers enable attacks
- Default creds easily guessed
- Error details help attackers

## Good Example

```php
// .env in production
APP_DEBUG=false
APP_ENV=production

// Security headers middleware
class SecurityHeaders
{
    public function handle($request, $next)
    {
        $response = $next($request);

        return $response
            ->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-XSS-Protection', '1; mode=block')
            ->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->header('Content-Security-Policy', "default-src 'self'");
    }
}

// Generic error messages in production
public function render($request, Throwable $e)
{
    if (app()->environment('production')) {
        return response()->json([
            'error' => 'An error occurred. Please try again.',
        ], 500);
    }

    return parent::render($request, $e);
}
```

**Why it's secure:**
- Debug off in production
- Security headers prevent attacks
- Generic error messages
- Strong credentials required

## Audit Command

```bash
# Check production config
grep -rn "APP_DEBUG\|APP_ENV" .env

# Check for default passwords
grep -rn "password\|secret" .env | grep -v ".example"

# Verify headers
curl -I https://api.botjao.com | grep -i "x-frame\|strict\|content-security"
```

## Project-Specific Notes

**BotFacebook Security Config:**

```php
// app/Http/Middleware/SecurityHeaders.php
class SecurityHeaders
{
    public function handle($request, $next)
    {
        $response = $next($request);

        if (app()->environment('production')) {
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}

// Kernel.php - apply to all requests
protected $middleware = [
    // ...
    \App\Http\Middleware\SecurityHeaders::class,
];
```
