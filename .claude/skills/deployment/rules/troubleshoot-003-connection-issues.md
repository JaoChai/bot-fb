---
id: troubleshoot-003-connection-issues
title: Connection and Network Issues
impact: HIGH
impactDescription: "Service cannot connect to database, cache, or external services"
category: troubleshoot
tags: [connection, database, network, timeout]
relatedRules: [health-002-component-checks, env-001-required-vars]
---

## Symptom

- "Connection refused" errors
- Database connection timeouts
- External API calls failing
- Redis/cache connection errors
- WebSocket connections dropping

## Root Cause

1. Wrong connection string
2. Firewall blocking connections
3. Service down or overloaded
4. DNS resolution failure
5. SSL/TLS issues
6. Connection pool exhausted

## Diagnosis

### Quick Check

```bash
# Check database connection
railway exec "php artisan tinker --execute=\"DB::connection()->getPdo(); echo 'OK';\""

# Check environment variables
railway variables | grep -E "DATABASE_URL|REDIS_URL|API_KEY"

# Check connection errors in logs
railway logs --filter "connection\|refused\|timeout\|SQLSTATE" --lines 50
```

### Detailed Analysis

```bash
# Test database connectivity
railway exec "php artisan db:monitor"

# Test external service
railway exec "php artisan tinker --execute=\"Http::get('https://api.openrouter.ai/v1/models');\""

# Check DNS resolution
railway exec "nslookup ep-xxx.neon.tech"

# Check SSL
railway exec "openssl s_client -connect ep-xxx.neon.tech:5432 -starttls postgres"
```

## Solution

### Fix Steps

1. **Database connection issues**
```bash
# Verify DATABASE_URL format
# postgresql://user:password@host:5432/database?sslmode=require

# Check Neon dashboard for:
# - Project is active (not sleeping)
# - Connection string is correct
# - IP not blocked

# Test with correct SSL mode
railway exec "php artisan tinker --execute=\"
config(['database.connections.pgsql.sslmode' => 'require']);
DB::reconnect();
echo DB::select('SELECT 1')[0]->{'?column?'};
\""
```

2. **External API connection issues**
```php
// Add timeout and retry
$response = Http::timeout(30)
    ->retry(3, 1000)
    ->withHeaders(['Authorization' => 'Bearer ' . $apiKey])
    ->get($url);

// Log failures
if (!$response->successful()) {
    Log::error('API connection failed', [
        'url' => $url,
        'status' => $response->status(),
        'body' => $response->body(),
    ]);
}
```

3. **Connection pool exhaustion**
```php
// config/database.php
'pgsql' => [
    // ... other config
    'pool' => [
        'min' => 2,
        'max' => 10,
    ],
    'options' => [
        PDO::ATTR_PERSISTENT => false,  // Disable persistent connections
    ],
],
```

4. **Redis/cache issues**
```bash
# If Redis not available, use database
railway variables set CACHE_DRIVER=database
railway variables set SESSION_DRIVER=database
railway variables set QUEUE_CONNECTION=database
```

### Connection Testing Script

```php
// app/Console/Commands/TestConnections.php
class TestConnections extends Command
{
    protected $signature = 'test:connections';

    public function handle(): int
    {
        // Database
        try {
            DB::connection()->getPdo();
            $this->info('✓ Database: Connected');
        } catch (\Exception $e) {
            $this->error('✗ Database: ' . $e->getMessage());
        }

        // Cache
        try {
            Cache::put('test', 'value', 10);
            $this->info('✓ Cache: ' . (Cache::get('test') ? 'Working' : 'Failed'));
        } catch (\Exception $e) {
            $this->error('✗ Cache: ' . $e->getMessage());
        }

        // External API
        try {
            $response = Http::timeout(10)->get('https://api.openrouter.ai/v1/models');
            $this->info('✓ OpenRouter: ' . ($response->successful() ? 'Connected' : 'Failed'));
        } catch (\Exception $e) {
            $this->error('✗ OpenRouter: ' . $e->getMessage());
        }

        return 0;
    }
}
```

### Common Connection Issues

| Service | Error | Fix |
|---------|-------|-----|
| Neon DB | "connection refused" | Check Neon project is active |
| Neon DB | "SSL SYSCALL error" | Add `?sslmode=require` |
| Redis | "Connection refused" | Use database driver instead |
| External API | "timeout" | Increase timeout, add retry |
| WebSocket | "handshake failed" | Check Reverb config |

## Verification

```bash
# Run connection test
railway exec "php artisan test:connections"

# Check health endpoint
curl -s https://api.botjao.com/health | jq '.checks'

# Verify no connection errors
railway logs --filter "connection\|refused" --lines 20 --since "5 minutes ago"
# Should be empty
```

## Prevention

- Use connection pooling
- Implement retry logic
- Monitor connection health
- Set appropriate timeouts
- Have fallback configurations

## Project-Specific Notes

**BotFacebook Context:**
- Database: Neon PostgreSQL (may sleep after inactivity)
- Cache: Database driver (not Redis)
- External: OpenRouter, Jina, LINE, Telegram
- WebSocket: Reverb
