---
name: auth-security
description: Authentication and security specialist for Laravel Sanctum, OAuth, API keys, and platform credentials. Handles login flows, token management, rate limiting, OWASP security. Use when implementing auth, managing API keys, securing endpoints, or auditing security.
---

# Auth & Security Skill

Authentication, authorization, and security for BotFacebook.

## Quick Start

1. **Auth Type:**
   - API Token → Laravel Sanctum
   - OAuth → LINE/Telegram Login
   - Bot Credentials → Platform API keys

2. **Security Check:**
   ```bash
   # Check for security issues
   grep -r "env(" app/ --include="*.php" | grep -v ".env"
   ```

## MCP Tools Available

- **context7**: `query-docs` - Get latest Laravel Sanctum docs
- **sentry**: `search_issues` - Find auth-related errors
- **neon**: `run_sql` - Check user sessions, tokens

## Authentication Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Authentication Flow                       │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  User Login                    API Request                   │
│      │                              │                        │
│      ▼                              ▼                        │
│  ┌────────┐                   ┌──────────┐                  │
│  │ Sanctum│                   │ Sanctum  │                  │
│  │ Login  │                   │ Token    │                  │
│  └────┬───┘                   └────┬─────┘                  │
│       │                            │                         │
│       ▼                            ▼                         │
│  ┌────────┐                   ┌──────────┐                  │
│  │ Create │                   │ Validate │                  │
│  │ Token  │                   │ Token    │                  │
│  └────────┘                   └──────────┘                  │
│                                                              │
├─────────────────────────────────────────────────────────────┤
│                    Bot Platform Auth                         │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  LINE                          Telegram                      │
│  ├── Channel Access Token      ├── Bot Token                │
│  ├── Channel Secret            └── Webhook Secret           │
│  └── LIFF Token                                             │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## Key Patterns

### Laravel Sanctum Token Auth

```php
// Login and create token
public function login(LoginRequest $request): array
{
    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    // Revoke existing tokens (optional)
    $user->tokens()->delete();

    return [
        'token' => $user->createToken('api-token')->plainTextToken,
        'user' => new UserResource($user),
    ];
}
```

### API Token Validation

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('bots', BotController::class);
});

// Controller with token abilities
public function store(Request $request)
{
    if (!$request->user()->tokenCan('bot:create')) {
        abort(403, 'Token does not have required ability');
    }
    // ...
}
```

### Bot Credentials Management

```php
// Encrypt sensitive credentials
class Bot extends Model
{
    protected $casts = [
        'access_token' => 'encrypted',
        'channel_secret' => 'encrypted',
    ];
}

// Validate credentials before saving
class StoreBotRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'access_token' => ['required', 'string', 'min:50'],
            'channel_secret' => ['required', 'string', 'min:32'],
        ];
    }
}
```

### Rate Limiting

```php
// app/Providers/AppServiceProvider.php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('webhook', function (Request $request) {
    return Limit::perMinute(1000)->by($request->route('bot_id'));
});
```

## Security Checklist

### Authentication

- [ ] Passwords hashed with bcrypt (Laravel default)
- [ ] Token expiration configured
- [ ] Session timeout configured
- [ ] Failed login attempts limited
- [ ] Password reset tokens expire

### Authorization

- [ ] Policies defined for all resources
- [ ] Middleware applied to protected routes
- [ ] Token abilities/scopes used
- [ ] Admin routes separated

### API Security

- [ ] Rate limiting on all endpoints
- [ ] CORS properly configured
- [ ] Input validation on all endpoints
- [ ] No sensitive data in responses

### Credentials

- [ ] API keys encrypted in database
- [ ] Environment variables for secrets
- [ ] No credentials in git
- [ ] Credentials rotated periodically

## Detailed Guides

- **Sanctum Setup**: See [SANCTUM_GUIDE.md](SANCTUM_GUIDE.md)
- **OAuth Integration**: See [OAUTH_GUIDE.md](OAUTH_GUIDE.md)
- **Security Audit**: See [SECURITY_AUDIT.md](SECURITY_AUDIT.md)

## Key Files

| File | Purpose |
|------|---------|
| `config/sanctum.php` | Sanctum configuration |
| `config/cors.php` | CORS settings |
| `app/Http/Middleware/Authenticate.php` | Auth middleware |
| `app/Policies/*.php` | Authorization policies |
| `routes/api.php` | Protected routes |

## Common Tasks

### Add New Protected Endpoint

1. Add route inside `auth:sanctum` middleware group
2. Create Policy if needed
3. Check token abilities in controller
4. Test with valid/invalid tokens

### Rotate Bot Credentials

1. Generate new credentials in platform console
2. Update encrypted values in database
3. Verify webhook still works
4. Document rotation date

### Audit Security

```bash
# Check for hardcoded secrets
grep -rn "sk_live\|pk_live\|api_key\|secret" app/ config/

# Check for unsafe queries
grep -rn "DB::raw\|whereRaw" app/

# Check middleware coverage
php artisan route:list --columns=uri,middleware
```

## OWASP Top 10 Prevention

| Vulnerability | Prevention |
|--------------|------------|
| Injection | Use Eloquent, validate input |
| Broken Auth | Sanctum + rate limiting |
| Sensitive Data | Encrypt credentials, HTTPS |
| XXE | Disable XML parsing |
| Broken Access | Policies + middleware |
| Security Misconfig | Review config files |
| XSS | Blade escaping, CSP headers |
| Insecure Deserialization | Validate serialized data |
| Vulnerable Components | `composer audit` |
| Insufficient Logging | Log auth events |

## Webhook Signature Validation

### LINE

```php
public function validateLineSignature(Request $request, string $channelSecret): bool
{
    $signature = $request->header('X-Line-Signature');
    $body = $request->getContent();

    $hash = base64_encode(
        hash_hmac('sha256', $body, $channelSecret, true)
    );

    return hash_equals($hash, $signature);
}
```

### Telegram

```php
public function validateTelegramUpdate(array $update, string $token): bool
{
    // Telegram doesn't use signature, but IP whitelist
    $telegramIps = ['149.154.160.0/20', '91.108.4.0/22'];

    return $this->ipInRange(request()->ip(), $telegramIps);
}
```

## Common Vulnerabilities to Check

| Issue | How to Find | Fix |
|-------|-------------|-----|
| Missing auth | Routes without middleware | Add `auth:sanctum` |
| Weak tokens | Short or predictable tokens | Use Sanctum defaults |
| Token leakage | Tokens in logs/responses | Filter sensitive data |
| IDOR | Direct object reference | Use policies |
| Mass assignment | Unguarded models | Use `$fillable` |

## Testing Auth

```bash
# Test login
curl -X POST https://api.botjao.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"password"}'

# Test protected route
curl https://api.botjao.com/api/v1/bots \
  -H "Authorization: Bearer {token}"

# Test rate limiting
for i in {1..100}; do curl -s -o /dev/null -w "%{http_code}\n" \
  https://api.botjao.com/api/v1/bots; done
```

## Gotchas

| Problem | Cause | Solution |
|---------|-------|----------|
| 401 on valid token | Token expired | Check `expiration` in sanctum config |
| CORS error | Missing origin | Add domain to `config/cors.php` |
| Rate limit too strict | Low limit | Adjust `RateLimiter::for()` |
| Cookie not sent | SameSite issue | Set `SESSION_DOMAIN` properly |
| Webhook signature fails | Body modified | Read raw body before any parsing |
| Token abilities not working | Wrong middleware | Use `auth:sanctum` not `auth` |

## Utility Scripts

- `scripts/security_audit.sh` - Run security checks
- `scripts/rotate_tokens.php` - Rotate expired tokens
