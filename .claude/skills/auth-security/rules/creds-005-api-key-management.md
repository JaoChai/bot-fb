---
id: creds-005-api-key-management
title: Secure API Key Management
impact: HIGH
impactDescription: "Third-party API keys exposed or mismanaged"
category: creds
tags: [credentials, api-keys, openrouter, third-party]
relatedRules: [creds-001-env-secrets, creds-002-encrypt-credentials]
---

## Why This Matters

Third-party API keys (OpenRouter, Sentry, etc.) often have billing attached. Leaked keys can result in massive unexpected charges and service abuse.

## Threat Model

**Attack Vector:** Keys in git, logs, client-side code
**Impact:** Financial loss, service abuse, account suspension
**Likelihood:** High - keys frequently leaked accidentally

## Bad Example

```php
// Key in code
$openrouter = new OpenRouterClient('sk-or-v1-abc123...');

// Key in frontend
// frontend/src/config.ts
export const OPENROUTER_KEY = 'sk-or-v1-abc123...';  // In bundle!

// Key in git history
// .env (accidentally committed)
OPENROUTER_KEY=sk-or-v1-abc123...

// No usage limits
$response = $this->openrouter->complete($prompt);
// Could rack up $1000s in charges
```

**Why it's vulnerable:**
- Keys in git history forever
- Frontend bundles are public
- No spending limits
- No usage monitoring

## Good Example

```php
// Keys from environment
// config/services.php
'openrouter' => [
    'key' => env('OPENROUTER_KEY'),
    'base_url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
],

// Service with key from config
class OpenRouterService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.key')
            ?? throw new \RuntimeException('OpenRouter key not configured');
    }

    public function complete(string $prompt): string
    {
        // Track usage before request
        $this->trackUsage();

        return $this->client
            ->withToken($this->apiKey)
            ->post('/chat/completions', [...]);
    }

    private function trackUsage(): void
    {
        $usage = Cache::get('openrouter_usage_today', 0);
        $limit = config('services.openrouter.daily_limit', 1000);

        if ($usage >= $limit) {
            throw new RateLimitException('Daily API limit reached');
        }

        Cache::increment('openrouter_usage_today');
    }
}

// .env.example (committed, no real values)
OPENROUTER_KEY=your-openrouter-api-key

// .gitignore
.env
.env.local
.env.*.local

// Per-user API keys (for white-label)
class Bot extends Model
{
    protected $casts = [
        'openrouter_key' => 'encrypted',  // User's own key
    ];
}

class OpenRouterService
{
    public function forBot(Bot $bot): self
    {
        // Use bot's key if available, else system key
        $this->apiKey = $bot->openrouter_key
            ?? config('services.openrouter.key');

        return $this;
    }
}
```

**Why it's secure:**
- Keys from environment
- Never in git
- Usage limits
- Per-bot key support
- Encrypted storage

## Audit Command

```bash
# Check for hardcoded keys
grep -rn "sk-or\|sk_live\|sk_test" --include="*.php" --include="*.ts" --include="*.js" .

# Verify .gitignore
grep ".env" .gitignore

# Check git history for secrets
git log -p --all -S 'sk-or' -- '*.php' '*.env'

# Check frontend for keys
grep -rn "OPENROUTER\|API_KEY" frontend/src/ --include="*.ts" --include="*.tsx"
```

## Project-Specific Notes

**BotFacebook API Key Strategy:**

```php
// System-level keys (from Railway environment)
// config/services.php
'openrouter' => [
    'key' => env('OPENROUTER_KEY'),
    'daily_limit' => env('OPENROUTER_DAILY_LIMIT', 10000),
],

'sentry' => [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
],

// Bot-level keys (user brings their own)
class Bot extends Model
{
    protected $casts = [
        'openrouter_key' => 'encrypted',
    ];

    public function getApiKey(): string
    {
        // Bot's key takes priority
        return $this->openrouter_key
            ?? config('services.openrouter.key');
    }
}

// Cost tracking per key
class CostTrackingService
{
    public function trackUsage(Bot $bot, int $tokens): void
    {
        // Track against bot's key or system key
        $keyType = $bot->openrouter_key ? 'bot' : 'system';

        CostLog::create([
            'bot_id' => $bot->id,
            'key_type' => $keyType,
            'tokens' => $tokens,
            'cost' => $this->calculateCost($tokens),
        ]);
    }
}
```
