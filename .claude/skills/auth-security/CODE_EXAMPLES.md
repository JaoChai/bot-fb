# Auth Security Code Examples

## Laravel Sanctum Token Auth

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

## API Token Validation

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

## Bot Credentials Management

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

## Rate Limiting

```php
// app/Providers/AppServiceProvider.php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('webhook', function (Request $request) {
    return Limit::perMinute(1000)->by($request->route('bot_id'));
});
```

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
