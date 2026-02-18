# Security Best Practices

มาตรฐานความปลอดภัยสำหรับโปรเจกต์ BotFacebook

## Security Checklist

### 🔒 Authentication & Authorization
- [ ] JWT tokens มี expiration time
- [ ] Refresh tokens rotated หลัง use
- [ ] Password hashing ด้วย bcrypt (Laravel default)
- [ ] Session timeout configured (15-30 นาที)
- [ ] 2FA enabled (ถ้ามี)

### 🛡️ Input Validation
- [ ] ทุก input validated ด้วย FormRequest
- [ ] XSS prevention (escape output)
- [ ] SQL injection prevention (ใช้ Eloquent/Query Builder)
- [ ] File upload validation (type, size, extension)
- [ ] Rate limiting enabled

### 🌐 API Security
- [ ] HTTPS only (ห้าม HTTP)
- [ ] CORS configured properly
- [ ] API keys ไม่ expose ใน client
- [ ] Sensitive data ไม่อยู่ใน URL/query params
- [ ] Request signing (ถ้าจำเป็น)

### 📦 Data Protection
- [ ] Sensitive data encrypted (at rest & in transit)
- [ ] PII handled according to privacy policy
- [ ] Database credentials in .env (ไม่ commit)
- [ ] API keys in environment variables
- [ ] Logs ไม่มี sensitive data

---

## Input Validation

### ใช้ FormRequest Validation
```php
// app/Http/Requests/StoreBotRequest.php
class StoreBotRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check();
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'channel_type' => 'required|in:line,telegram',
            'webhook_url' => 'required|url|max:500',
            'api_key' => 'required|string|size:32',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'Bot name is required',
            'channel_type.in' => 'Invalid channel type',
        ];
    }
}

// Controller
public function store(StoreBotRequest $request)
{
    // $request->validated() already safe
    $bot = Bot::create($request->validated());

    return response()->json(['data' => $bot], 201);
}
```

### XSS Prevention
```php
// Blade template (auto-escaped)
{{ $user->name }}  // ✅ Safe

// Raw output (dangerous!)
{!! $user->bio !!}  // ❌ Avoid unless necessary

// Manual escape
{{ htmlspecialchars($untrustedInput, ENT_QUOTES, 'UTF-8') }}
```

### SQL Injection Prevention
```php
// ✅ Safe - Eloquent/Query Builder
Bot::where('user_id', $userId)->get();
DB::table('bots')->where('name', $name)->first();

// ✅ Safe - Parameter binding
DB::select('SELECT * FROM bots WHERE user_id = ?', [$userId]);

// ❌ Dangerous - Raw query
DB::select("SELECT * FROM bots WHERE user_id = $userId");
```

---

## Authentication

### JWT Configuration
```php
// config/jwt.php
'ttl' => 60, // Access token: 1 hour
'refresh_ttl' => 20160, // Refresh token: 14 days

// Rotate refresh token on use
'blacklist_grace_period' => 30,
'rotate_refresh_tokens' => true,
```

### Token Storage (Frontend)
```typescript
// ✅ Good - HttpOnly cookie
// Set by backend, inaccessible to JS

// ⚠️ OK - localStorage (with precautions)
localStorage.setItem('access_token', token);

// ❌ Bad - Plain cookies
document.cookie = `token=${token}`; // Vulnerable to XSS
```

### Logout
```php
public function logout(Request $request)
{
    // Invalidate token
    auth()->logout();

    // Clear session
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return response()->json(['message' => 'Logged out']);
}
```

---

## Authorization

### Policy-Based
```php
// app/Policies/BotPolicy.php
class BotPolicy
{
    public function view(User $user, Bot $bot)
    {
        return $user->id === $bot->user_id;
    }

    public function update(User $user, Bot $bot)
    {
        return $user->id === $bot->user_id;
    }

    public function delete(User $user, Bot $bot)
    {
        return $user->id === $bot->user_id;
    }
}

// Controller
public function update(UpdateBotRequest $request, Bot $bot)
{
    $this->authorize('update', $bot);

    $bot->update($request->validated());

    return response()->json(['data' => $bot]);
}
```

### Gate-Based
```php
// app/Providers/AuthServiceProvider.php
Gate::define('manage-bots', function (User $user) {
    return $user->role === 'admin';
});

// Usage
if (Gate::allows('manage-bots')) {
    // Allow
}

// In controller
$this->authorize('manage-bots');
```

---

## Rate Limiting

### API Rate Limits
```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'api' => [
        'throttle:60,1', // 60 requests per minute
    ],
];

// Custom rate limit
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/bots/{id}/test', [BotController::class, 'test']);
});
```

### Login Rate Limiting
```php
// routes/api.php
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1'); // 5 attempts per minute
```

### Custom Rate Limiter
```php
// app/Providers/RouteServiceProvider.php
RateLimiter::for('bot-test', function (Request $request) {
    return Limit::perMinute(10)
        ->by($request->user()?->id ?: $request->ip())
        ->response(function () {
            return response()->json([
                'message' => 'Too many requests. Please slow down.',
            ], 429);
        });
});
```

---

## CSRF Protection

### Laravel CSRF (Auto-enabled)
```php
// Auto-protected POST, PUT, PATCH, DELETE
// VerifyCsrfToken middleware enabled by default

// Exclude API routes (using token auth instead)
protected $except = [
    'api/*',
    'webhooks/*',
];
```

### SPA CSRF with Sanctum
```php
// Sanctum middleware provides CSRF protection
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/bots', [BotController::class, 'store']);
});
```

---

## File Upload Security

### Validation
```php
public function rules()
{
    return [
        'avatar' => 'required|image|mimes:jpeg,png,jpg|max:2048', // 2MB
        'document' => 'required|file|mimes:pdf,doc,docx|max:10240', // 10MB
    ];
}
```

### Secure Storage
```php
public function store(Request $request)
{
    $validated = $request->validate([
        'file' => 'required|file|max:5120',
    ]);

    // Generate random filename
    $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();

    // Store in private storage (not public)
    $path = $request->file('file')->storeAs(
        'uploads',
        $filename,
        'private' // Not accessible via URL
    );

    return response()->json(['path' => $path]);
}
```

### Serve Private Files
```php
public function download($id)
{
    $file = File::findOrFail($id);

    // Authorize
    $this->authorize('download', $file);

    // Serve file
    return Storage::download($file->path, $file->original_name);
}
```

---

## Encryption

### Encrypt Sensitive Data
```php
use Illuminate\Support\Facades\Crypt;

// Encrypt
$encrypted = Crypt::encryptString($apiKey);

// Decrypt
$decrypted = Crypt::decryptString($encrypted);

// Model accessor/mutator
class Bot extends Model
{
    protected function apiKey(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Crypt::decryptString($value),
            set: fn ($value) => Crypt::encryptString($value),
        );
    }
}
```

### Hash Passwords
```php
use Illuminate\Support\Facades\Hash;

// Hash (Laravel does this automatically for User model)
$hashed = Hash::make($password);

// Verify
if (Hash::check($plainPassword, $hashedPassword)) {
    // Correct
}
```

---

## Environment Variables

### Never Commit Secrets
```bash
# .gitignore
.env
.env.local
.env.*.local
```

### Use .env
```env
# .env
APP_KEY=base64:generated-by-artisan-key-generate
DB_PASSWORD=super-secret-password
OPENROUTER_API_KEY=sk-or-v1-xxx
JWT_SECRET=generated-secret
```

### Access in Code
```php
// ✅ Good
$apiKey = config('services.openrouter.api_key');

// ❌ Bad
$apiKey = env('OPENROUTER_API_KEY'); // Use config instead
```

---

## CORS Configuration

### Proper CORS Setup
```php
// config/cors.php
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],

    'allowed_origins' => [
        'https://www.botjao.com',
        // Add other allowed origins
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // For cookies
];
```

---

## Logging (Without Sensitive Data)

### Safe Logging
```php
// ✅ Good
Log::info('User logged in', ['user_id' => $user->id]);

// ❌ Bad
Log::info('User logged in', [
    'user' => $user, // May contain password hash, tokens
]);

// ❌ Very bad
Log::info('API request', [
    'api_key' => $apiKey, // Never log secrets!
]);
```

### Redact Sensitive Data in Logs
```php
// app/Exceptions/Handler.php
protected function context()
{
    return array_filter([
        'userId' => auth()->user()?->id,
        'ip' => request()->ip(),
        // Don't include: tokens, passwords, API keys
    ]);
}
```

---

## HTTPS Only

### Force HTTPS
```php
// app/Providers/AppServiceProvider.php
public function boot()
{
    if ($this->app->environment('production')) {
        URL::forceScheme('https');
    }
}
```

### Redirect HTTP to HTTPS
```php
// app/Http/Middleware/ForceHttps.php
public function handle($request, Closure $next)
{
    if (!$request->secure() && app()->environment('production')) {
        return redirect()->secure($request->getRequestUri());
    }

    return $next($request);
}
```

---

## Security Headers

### Add Security Headers
```php
// app/Http/Middleware/SecurityHeaders.php
public function handle($request, Closure $next)
{
    $response = $next($request);

    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('X-XSS-Protection', '1; mode=block');
    $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    $response->headers->set('Content-Security-Policy', "default-src 'self'");

    return $response;
}
```

---

## Webhook Security

### Verify Webhook Signatures
```php
public function handleWebhook(Request $request)
{
    $signature = $request->header('X-Line-Signature');

    if (!$this->verifySignature($signature, $request->getContent())) {
        return response()->json(['error' => 'Invalid signature'], 401);
    }

    // Process webhook
}

private function verifySignature($signature, $body)
{
    $hash = hash_hmac('sha256', $body, config('services.line.channel_secret'));

    return hash_equals($signature, base64_encode($hash));
}
```

---

## Dependency Security

### Keep Dependencies Updated
```bash
# Check for vulnerabilities
composer audit

# Update dependencies
composer update
```

### Lock Dependencies
```bash
# Commit composer.lock
git add composer.lock
git commit -m "Lock dependencies"
```

---

## Production Checklist

เมื่อ deploy production:

- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] HTTPS enabled
- [ ] Security headers configured
- [ ] Rate limiting enabled
- [ ] CORS configured properly
- [ ] Secrets in environment variables (not .env file)
- [ ] Database credentials secure
- [ ] Error reporting to Sentry (ไม่ show ใน response)
- [ ] File upload limits set
- [ ] Session timeout configured
- [ ] Log rotation enabled
- [ ] Backup strategy in place

---

## Common Vulnerabilities (OWASP Top 10)

### 1. Injection
```php
// ✅ Safe
DB::table('users')->where('email', $email)->first();

// ❌ Vulnerable
DB::raw("SELECT * FROM users WHERE email = '$email'");
```

### 2. Broken Authentication
```php
// ✅ Safe
Hash::check($password, $user->password);

// ❌ Vulnerable
if ($password === $user->password) { }
```

### 3. Sensitive Data Exposure
```php
// ✅ Safe
return response()->json(['id' => $user->id, 'name' => $user->name]);

// ❌ Vulnerable
return response()->json($user); // May expose password hash, tokens
```

### 4. XML External Entities (XXE)
```php
// ✅ Safe
$xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_DTDLOAD);

// ❌ Vulnerable
$xml = simplexml_load_string($data);
```

### 5. Broken Access Control
```php
// ✅ Safe
$this->authorize('update', $bot);

// ❌ Vulnerable
if ($request->user()->id !== $bot->user_id) {
    abort(403);
}
```

---

## Security Testing

### Test Authentication
```php
public function test_cannot_access_without_auth()
{
    $response = $this->getJson('/api/v1/bots');

    $response->assertUnauthorized();
}
```

### Test Authorization
```php
public function test_cannot_update_others_bot()
{
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $bot = Bot::factory()->create(['user_id' => $user1->id]);

    $response = $this->actingAs($user2)
        ->putJson("/api/v1/bots/{$bot->id}", ['name' => 'Hacked']);

    $response->assertForbidden();
}
```

### Test Rate Limiting
```php
public function test_rate_limit_login()
{
    for ($i = 0; $i < 6; $i++) {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]);
    }

    $response->assertStatus(429); // Too many requests
}
```

---

## Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Laravel Security Best Practices](https://laravel.com/docs/security)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)
