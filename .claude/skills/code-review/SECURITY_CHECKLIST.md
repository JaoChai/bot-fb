# Security Checklist

## OWASP Top 10 (2021)

### 1. Broken Access Control

**Check for:**
- [ ] Missing authorization on endpoints
- [ ] IDOR (Insecure Direct Object References)
- [ ] Path traversal vulnerabilities
- [ ] Missing function-level access control

**Laravel Examples:**

```php
// ❌ VULNERABLE - No authorization check
public function show($id)
{
    return Bot::findOrFail($id);
}

// ✅ SECURE - Using Policy
public function show(Bot $bot)
{
    $this->authorize('view', $bot);
    return new BotResource($bot);
}

// ✅ SECURE - Scope to user
public function show($id)
{
    $bot = auth()->user()->bots()->findOrFail($id);
    return new BotResource($bot);
}
```

### 2. Cryptographic Failures

**Check for:**
- [ ] Sensitive data in logs
- [ ] Weak encryption algorithms
- [ ] Hardcoded secrets
- [ ] Sensitive data in URLs

**Examples:**

```php
// ❌ VULNERABLE - Logging sensitive data
Log::info('User login', ['password' => $password]);

// ✅ SECURE - Never log sensitive data
Log::info('User login', ['user_id' => $user->id]);

// ❌ VULNERABLE - Weak hashing
$hash = md5($password);

// ✅ SECURE - Use bcrypt
$hash = Hash::make($password);
```

### 3. Injection

**Check for:**
- [ ] SQL injection
- [ ] NoSQL injection
- [ ] Command injection
- [ ] LDAP injection

**Examples:**

```php
// ❌ VULNERABLE - SQL injection
DB::select("SELECT * FROM users WHERE name = '$name'");

// ✅ SECURE - Parameterized query
DB::select("SELECT * FROM users WHERE name = ?", [$name]);

// ❌ VULNERABLE - Command injection via shell
shell_exec("grep {$userInput} /var/log/app.log");

// ✅ SECURE - Use escapeshellarg or avoid shell entirely
shell_exec("grep " . escapeshellarg($userInput) . " /var/log/app.log");

// ✅ BETTER - Use proc_open or Symfony Process component
use Symfony\Component\Process\Process;
$process = new Process(['grep', $userInput, '/var/log/app.log']);
$process->run();
```

### 4. Insecure Design

**Check for:**
- [ ] Missing rate limiting
- [ ] No brute force protection
- [ ] Missing CAPTCHA where needed
- [ ] Unlimited resource consumption

**Examples:**

```php
// ✅ Rate limiting in routes
Route::middleware('throttle:api.auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

// ✅ Login attempt limiting
if ($this->hasTooManyLoginAttempts($request)) {
    $this->fireLockoutEvent($request);
    return $this->sendLockoutResponse($request);
}
```

### 5. Security Misconfiguration

**Check for:**
- [ ] Debug mode in production
- [ ] Default credentials
- [ ] Unnecessary features enabled
- [ ] Missing security headers

**Examples:**

```php
// .env - Production
APP_DEBUG=false
APP_ENV=production

// Security headers middleware
public function handle($request, $next)
{
    $response = $next($request);

    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-XSS-Protection', '1; mode=block');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

    return $response;
}
```

### 6. Vulnerable Components

**Check for:**
- [ ] Outdated dependencies
- [ ] Known vulnerable packages
- [ ] Abandoned libraries

**Commands:**

```bash
# PHP - Check vulnerabilities
composer audit

# Node.js - Check vulnerabilities
npm audit

# Update dependencies
composer update --with-dependencies
npm update
```

### 7. Authentication Failures

**Check for:**
- [ ] Weak password policy
- [ ] Missing MFA option
- [ ] Session fixation
- [ ] Insecure token storage

**Examples:**

```php
// ✅ Strong password validation
public function rules(): array
{
    return [
        'password' => [
            'required',
            'min:8',
            'regex:/[A-Z]/',      // uppercase
            'regex:/[a-z]/',      // lowercase
            'regex:/[0-9]/',      // number
            'regex:/[@$!%*#?&]/', // special char
        ],
    ];
}

// ✅ Secure session configuration
'secure' => env('SESSION_SECURE_COOKIE', true),
'same_site' => 'lax',
```

### 8. Data Integrity Failures

**Check for:**
- [ ] Missing signature verification
- [ ] Insecure deserialization
- [ ] Missing CSRF protection

**Examples:**

```php
// ✅ CSRF protection (Laravel default)
// Ensure @csrf in forms

// ✅ Webhook signature verification
$signature = $request->header('X-Line-Signature');
$body = $request->getContent();
$hash = base64_encode(hash_hmac('sha256', $body, $secret, true));

if (!hash_equals($signature, $hash)) {
    abort(403, 'Invalid signature');
}
```

### 9. Logging & Monitoring Failures

**Check for:**
- [ ] Missing audit logs
- [ ] No alerting on suspicious activity
- [ ] Logs don't have enough context

**Examples:**

```php
// ✅ Security event logging
Log::channel('security')->warning('Failed login attempt', [
    'email' => $email,
    'ip' => $request->ip(),
    'user_agent' => $request->userAgent(),
]);

// ✅ Alert on suspicious activity
if ($failedAttempts > 5) {
    Notification::send($admins, new SuspiciousActivity($email, $ip));
}
```

### 10. SSRF (Server-Side Request Forgery)

**Check for:**
- [ ] User-controlled URLs
- [ ] Internal network access
- [ ] Cloud metadata access

**Examples:**

```php
// ❌ VULNERABLE - User controls URL
$response = Http::get($request->input('url'));

// ✅ SECURE - Validate URL
$url = $request->input('url');
$host = parse_url($url, PHP_URL_HOST);

$blocked = ['localhost', '127.0.0.1', '169.254.169.254', '10.', '192.168.'];
foreach ($blocked as $pattern) {
    if (str_starts_with($host, $pattern)) {
        abort(400, 'Invalid URL');
    }
}
```

## API Security

### Authentication

```php
// ✅ JWT with short expiry
'ttl' => 60, // 60 minutes

// ✅ Refresh token rotation
'refresh_ttl' => 20160, // 2 weeks

// ✅ Revoke all tokens on password change
auth()->user()->tokens()->delete();
```

### Rate Limiting

```php
// bootstrap/app.php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('auth', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});
```

### Input Validation

```php
// ✅ Always validate
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
        'url' => ['nullable', 'url', 'max:2048'],
        'file' => ['nullable', 'file', 'mimes:pdf,doc', 'max:10240'],
    ];
}
```

## Frontend Security

### XSS Prevention

```tsx
// ❌ VULNERABLE - XSS risk (avoid unless absolutely necessary)
<div dangerouslySetInnerHTML={{ __html: userInput }} />

// ✅ SECURE - Sanitize if HTML needed
import DOMPurify from 'dompurify';
<div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(userInput) }} />

// ✅ SECURE - Just use text (React auto-escapes)
<div>{userInput}</div>
```

### Sensitive Data

```typescript
// ❌ VULNERABLE - Token in localStorage
localStorage.setItem('token', jwt);

// ✅ SECURE - Use httpOnly cookie
// Token set by server in httpOnly cookie

// ✅ Or memory only (lost on refresh)
const [token, setToken] = useState<string | null>(null);
```

## Quick Security Review

```
✅ Authorization on all endpoints
✅ Input validation on all user input
✅ Output encoding for display
✅ HTTPS only
✅ Secure headers set
✅ Rate limiting enabled
✅ CSRF protection active
✅ Sensitive data not logged
✅ Dependencies up to date
✅ Security monitoring active
```
