---
id: creds-003-rotation
title: Implement Credential Rotation
impact: HIGH
impactDescription: "Compromised credentials remain valid indefinitely"
category: creds
tags: [credentials, rotation, tokens, security]
relatedRules: [creds-002-encrypt-credentials, sanctum-002-token-revocation]
---

## Why This Matters

Credentials that never expire remain valid even after compromise. Regular rotation limits the window of exposure from leaked credentials.

## Threat Model

**Attack Vector:** Leaked credentials used months/years later
**Impact:** Unauthorized access with old credentials
**Likelihood:** High - credentials get leaked in logs, screenshots, etc.

## Bad Example

```php
// Token created once, never rotated
public function createBot(Request $request)
{
    $bot = Bot::create([
        'access_token' => $request->access_token,  // Set once
        // Never changes...
    ]);
}

// No expiration on API tokens
$token = $user->createToken('api-token');
// Valid forever
```

**Why it's vulnerable:**
- Leaked token valid forever
- No way to know if compromised
- Old tokens accumulate
- Screenshot in docs = permanent access

## Good Example

```php
// Sanctum token with expiration
class AuthController extends Controller
{
    public function createToken(Request $request)
    {
        // Revoke old tokens first
        $request->user()->tokens()
            ->where('name', 'api-token')
            ->delete();

        // Create with expiration
        $token = $request->user()->createToken(
            'api-token',
            ['*'],
            now()->addDays(30)  // 30-day expiration
        );

        return ['token' => $token->plainTextToken];
    }
}

// Scheduled cleanup of expired tokens
// app/Console/Kernel.php
$schedule->command('sanctum:prune-expired --hours=24')
    ->daily();

// Bot credential rotation
class BotService
{
    public function rotateCredentials(Bot $bot): void
    {
        // Store old token temporarily for graceful transition
        $oldToken = $bot->access_token;

        // Generate new credentials from platform
        $newToken = $this->platformService->refreshToken($bot);

        // Update bot
        $bot->update([
            'access_token' => $newToken,
            'token_rotated_at' => now(),
        ]);

        // Log rotation
        Log::info('Bot credentials rotated', [
            'bot_id' => $bot->id,
        ]);
    }
}
```

**Why it's secure:**
- Tokens expire automatically
- Old tokens pruned regularly
- Rotation logged for audit
- Limited exposure window

## Audit Command

```bash
# Check for token expiration
grep -rn "createToken" app/ --include="*.php" | head -20

# Check for prune command
grep -rn "sanctum:prune" app/Console/ --include="*.php"

# Find tokens without expiration
php artisan tinker --execute="
    \$count = \Laravel\Sanctum\PersonalAccessToken::whereNull('expires_at')->count();
    echo \"Tokens without expiration: \$count\";
"
```

## Project-Specific Notes

**BotFacebook Token Rotation:**

```php
// config/sanctum.php
'expiration' => 60 * 24 * 30, // 30 days

// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Prune expired tokens daily
    $schedule->command('sanctum:prune-expired --hours=48')
        ->daily()
        ->at('03:00');
}

// Bot token refresh (for platforms that support it)
class BotService
{
    public function checkTokenHealth(Bot $bot): void
    {
        // Alert if token is old
        if ($bot->token_rotated_at?->diffInDays(now()) > 90) {
            Log::warning('Bot token is over 90 days old', [
                'bot_id' => $bot->id,
                'last_rotation' => $bot->token_rotated_at,
            ]);
        }
    }
}
```
