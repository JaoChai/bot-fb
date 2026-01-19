---
id: webhook-002-ip-whitelist
title: Consider IP Whitelisting for Webhooks
impact: MEDIUM
impactDescription: "Additional layer of protection for high-security scenarios"
category: webhook
tags: [webhook, ip-whitelist, security, defense-in-depth]
relatedRules: [webhook-001-signature-validation, webhook-003-replay-prevention]
---

## Why This Matters

IP whitelisting provides defense-in-depth for webhooks. Even if signatures could be forged, requests from unknown IPs are rejected.

## Threat Model

**Attack Vector:** Forged requests from unexpected sources
**Impact:** Reduced attack surface
**Likelihood:** Low - primarily useful as additional layer

## Bad Example

```php
// Only signature validation
public function handleWebhook(Request $request, Bot $bot)
{
    if ($this->validateSignature($request, $bot)) {
        // Process
    }
    // No IP check
}

// Hardcoded IPs (unmaintainable)
private function isAllowedIP(string $ip): bool
{
    return in_array($ip, [
        '147.92.140.1',
        '147.92.140.2',
        // ...50 more IPs
    ]);
}
```

**Why it's vulnerable:**
- Single layer of protection
- Hardcoded IPs become stale
- No logging of blocked IPs

## Good Example

```php
// config/webhooks.php
return [
    'line' => [
        'verify_ip' => env('WEBHOOK_VERIFY_IP', true),
        'allowed_ips' => [
            // LINE IP ranges (update periodically)
            '147.92.140.0/24',
            '147.92.141.0/24',
            '147.92.142.0/24',
            '106.152.46.0/24',
            '106.152.47.0/24',
        ],
    ],
    'telegram' => [
        'verify_ip' => env('WEBHOOK_VERIFY_IP', true),
        'allowed_ips' => [
            // Telegram IP ranges
            '149.154.160.0/20',
            '91.108.4.0/22',
        ],
    ],
];

// Middleware with CIDR support
class ValidateWebhookIP
{
    public function handle(Request $request, Closure $next, string $platform)
    {
        if (!config("webhooks.{$platform}.verify_ip")) {
            return $next($request);
        }

        $clientIP = $request->ip();
        $allowedRanges = config("webhooks.{$platform}.allowed_ips", []);

        if (!$this->isIPInRanges($clientIP, $allowedRanges)) {
            Log::warning('Webhook from unexpected IP', [
                'platform' => $platform,
                'ip' => $clientIP,
                'allowed_ranges' => $allowedRanges,
            ]);

            // Return 200 to not reveal failure
            return response('OK', 200);
        }

        return $next($request);
    }

    private function isIPInRanges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->ipInCIDR($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCIDR(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);

        return ($ip & $mask) === ($subnet & $mask);
    }
}

// Apply to routes
Route::post('/webhook/line/{bot}', [LINEWebhookController::class, 'handle'])
    ->middleware(['webhook.ip:line', 'webhook.line']);
```

**Why it's secure:**
- Defense-in-depth
- CIDR range support
- Configurable per platform
- Logged rejections

## Audit Command

```bash
# Check for IP validation
grep -rn "ip()\|getClientIp\|REMOTE_ADDR" app/Http/Middleware/ --include="*.php"

# Check webhook config
cat config/webhooks.php 2>/dev/null || echo "No webhook config"

# Get current LINE IPs (check LINE docs for updates)
curl -s https://developers.line.biz/en/docs/messaging-api/receiving-messages/ | grep -o '[0-9]\+\.[0-9]\+\.[0-9]\+\.[0-9]\+'
```

## Project-Specific Notes

**BotFacebook IP Verification:**

```php
// config/webhooks.php
return [
    'line' => [
        // Disable in dev, enable in prod
        'verify_ip' => env('WEBHOOK_VERIFY_IP', false),
        'allowed_ips' => [
            // LINE Messaging API IPs (as of 2024)
            '147.92.140.0/24',
            '147.92.141.0/24',
            '106.152.46.0/24',
            '106.152.47.0/24',
        ],
    ],
    'telegram' => [
        'verify_ip' => env('WEBHOOK_VERIFY_IP', false),
        'allowed_ips' => [
            '149.154.160.0/20',
            '91.108.4.0/22',
        ],
    ],
];

// .env.production
WEBHOOK_VERIFY_IP=true

// .env.local (for ngrok testing)
WEBHOOK_VERIFY_IP=false
```

**Note:** IP ranges can change. Check platform documentation periodically:
- LINE: https://developers.line.biz/en/reference/messaging-api/#ip-addresses
- Telegram: https://core.telegram.org/bots/webhooks#the-short-version
