# Laravel Sanctum Guide

## Setup

Sanctum is already installed in this project. Key configuration:

```php
// config/sanctum.php
'expiration' => 60 * 24 * 7, // 7 days
'token_prefix' => 'bot_',
```

## Token Creation

### Basic Token

```php
$token = $user->createToken('api-token')->plainTextToken;
```

### Token with Abilities

```php
$token = $user->createToken('api-token', [
    'bot:read',
    'bot:create',
    'bot:update',
    'bot:delete',
    'conversation:read',
])->plainTextToken;
```

### Check Abilities

```php
// In controller
if ($request->user()->tokenCan('bot:create')) {
    // Can create bot
}

// Using middleware
Route::post('/bots', [BotController::class, 'store'])
    ->middleware('ability:bot:create');
```

## Token Lifecycle

### Revoke Current Token

```php
$request->user()->currentAccessToken()->delete();
```

### Revoke All Tokens

```php
$request->user()->tokens()->delete();
```

### Revoke Specific Token

```php
$user->tokens()->where('id', $tokenId)->delete();
```

## SPA Authentication

For frontend (React) authentication:

```php
// config/sanctum.php
'stateful' => [
    'localhost',
    'localhost:3000',
    'localhost:5173',
    '127.0.0.1',
    '127.0.0.1:8000',
    'botjao.com',
    'www.botjao.com',
],
```

### Frontend Setup

```typescript
// Before login, get CSRF cookie
await axios.get('/sanctum/csrf-cookie');

// Then login
const response = await axios.post('/api/v1/auth/login', {
  email,
  password,
});
```

## Mobile/API Token Flow

```
1. POST /api/v1/auth/login
   Body: { email, password }
   Response: { token: "...", user: {...} }

2. All subsequent requests:
   Header: Authorization: Bearer {token}

3. POST /api/v1/auth/logout
   Header: Authorization: Bearer {token}
   (Revokes current token)
```

## Best Practices

1. **Token Expiration**: Set reasonable expiration (7 days default)
2. **Abilities**: Use fine-grained abilities for different access levels
3. **Revocation**: Revoke tokens on password change
4. **Logging**: Log token creation/revocation events
5. **Rate Limiting**: Apply rate limits per token
