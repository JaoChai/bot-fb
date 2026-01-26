---
name: auth-security
description: |
  Authentication and security specialist for Laravel Sanctum, OAuth, API keys, and platform credentials.
  Triggers: 'auth', 'login', 'token', 'security', 'OWASP', 'rate limit', 'credentials'.
  Use when: implementing auth, managing API keys, securing endpoints, auditing security.
allowed-tools:
  - Bash(grep*)
  - Bash(php artisan route:list*)
  - Read
  - Grep
context:
  - path: config/sanctum.php
  - path: config/cors.php
  - path: app/Http/Middleware/Authenticate.php
---

# Auth & Security

Authentication, authorization, and security for BotFacebook.

## Quick Start

1. **Auth Type:**
   - API Token → Laravel Sanctum
   - OAuth → LINE/Telegram Login
   - Bot Credentials → Platform API keys

2. **Security Check:**
   ```bash
   grep -r "env(" app/ --include="*.php" | grep -v ".env"
   ```

## MCP Tools Available

| Tool | Commands | Use For |
|------|----------|---------|
| **context7** | `query-docs` | Latest Sanctum docs |
| **sentry** | `search_issues` | Auth-related errors |
| **neon** | `run_sql` | Check user sessions |

## Authentication Flow

```
User Login          API Request
    │                    │
    ▼                    ▼
┌────────┐         ┌──────────┐
│ Sanctum│         │ Validate │
│ Login  │         │ Token    │
└────┬───┘         └────┬─────┘
     │                  │
     ▼                  ▼
┌────────┐         ┌──────────┐
│ Create │         │ Allow/   │
│ Token  │         │ Deny     │
└────────┘         └──────────┘
```

## Platform Auth

| Platform | Credentials |
|----------|-------------|
| LINE | Channel Access Token, Channel Secret, LIFF Token |
| Telegram | Bot Token, Webhook Secret |

## Key Patterns

### Rate Limiting

```php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});
```

### Encrypted Credentials

```php
class Bot extends Model
{
    protected $casts = [
        'access_token' => 'encrypted',
        'channel_secret' => 'encrypted',
    ];
}
```

## Key Files

| File | Purpose |
|------|---------|
| `config/sanctum.php` | Sanctum config |
| `config/cors.php` | CORS settings |
| `app/Policies/*.php` | Authorization |
| `routes/api.php` | Protected routes |

## Common Tasks

### Add Protected Endpoint

1. Add route inside `auth:sanctum` middleware
2. Create Policy if needed
3. Check token abilities in controller
4. Test with valid/invalid tokens

### Rotate Credentials

1. Generate new credentials in platform
2. Update encrypted values in database
3. Verify webhook works
4. Document rotation date

## Detailed Guides

- **Code Examples**: See [CODE_EXAMPLES.md](CODE_EXAMPLES.md)
- **Security Checklist**: See [SECURITY_CHECKLIST.md](SECURITY_CHECKLIST.md)
- **Sanctum Setup**: See [SANCTUM_GUIDE.md](SANCTUM_GUIDE.md)
- **OAuth Integration**: See [OAUTH_GUIDE.md](OAUTH_GUIDE.md)

## Gotchas

| Problem | Solution |
|---------|----------|
| 401 on valid token | Check `expiration` in sanctum config |
| CORS error | Add domain to `config/cors.php` |
| Rate limit strict | Adjust `RateLimiter::for()` |
| Cookie not sent | Set `SESSION_DOMAIN` properly |
| Webhook signature fails | Read raw body before parsing |
