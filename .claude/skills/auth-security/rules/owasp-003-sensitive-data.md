---
id: owasp-003-sensitive-data
title: OWASP A02 - Sensitive Data Exposure
impact: CRITICAL
impactDescription: "Credentials, tokens, or PII exposed to attackers"
category: owasp
tags: [owasp, data-exposure, encryption, security]
relatedRules: [creds-002-encrypt-credentials]
---

## Why This Matters

Sensitive data (passwords, tokens, PII) must be protected at rest and in transit. Exposure leads to credential theft and privacy violations.

## Threat Model

**Attack Vector:** Database breach, log exposure, man-in-the-middle
**Impact:** Credential theft, identity theft, compliance violations
**Likelihood:** High - data breaches are common

## Bad Example

```php
// Storing passwords in plain text
$user->password = $request->password;

// API keys in plain text
$bot->access_token = $request->access_token;

// Sensitive data in logs
Log::info('User login', ['password' => $request->password]);

// Returning sensitive data in API
return response()->json([
    'user' => $user, // Includes password hash, tokens
]);
```

**Why it's vulnerable:**
- Plain text credentials
- Logs contain secrets
- API exposes sensitive fields
- Database breach = full compromise

## Good Example

```php
// Hash passwords
$user->password = Hash::make($request->password);

// Encrypt sensitive data
class Bot extends Model
{
    protected $casts = [
        'access_token' => 'encrypted',
        'channel_secret' => 'encrypted',
    ];
}

// Sanitize logs
Log::info('User login', [
    'email' => $request->email,
    // Never log password, tokens, etc.
]);

// Use API Resources to control output
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Excludes: password, remember_token, etc.
        ];
    }
}

// Model hidden attributes
class User extends Model
{
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];
}
```

**Why it's secure:**
- Passwords hashed (bcrypt)
- Tokens encrypted (AES-256)
- Logs sanitized
- API responses filtered

## Audit Command

```bash
# Check for encrypted casts
grep -rn "'encrypted'" app/Models/ --include="*.php"

# Check for hidden attributes
grep -rn "protected \$hidden" app/Models/ --include="*.php"

# Check logs for sensitive data
grep -rn "Log::" --include="*.php" app/ | grep -i "password\|token\|secret"
```

## Project-Specific Notes

**BotFacebook Data Protection:**

```php
// Bot model - encrypt all credentials
class Bot extends Model
{
    protected $hidden = [
        'access_token',
        'channel_secret',
        'openrouter_key',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'channel_secret' => 'encrypted',
        'openrouter_key' => 'encrypted',
    ];
}

// User model
class User extends Model
{
    protected $hidden = [
        'password',
        'remember_token',
    ];
}

// config/logging.php - Don't log sensitive fields
'tap' => [App\Logging\SanitizeSecrets::class],
```
