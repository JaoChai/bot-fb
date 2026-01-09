# Debugging Guide

คู่มือการแก้ไขปัญหาและ debug สำหรับโปรเจกต์ BotFacebook

## Debug Workflow

```
1. Reproduce ปัญหา
2. Check Logs (Sentry, Railway, Browser)
3. Search Memory (/mem-search)
4. Isolate the Issue
5. Fix & Test
6. Verify in Production
```

---

## Monitoring Services

### Sentry (Error Tracking)
**URL:** [https://sentry.io/...]

**ใช้เมื่อ:**
- Production errors
- Exception stack traces
- Performance issues

**วิธีดู:**
```bash
# CLI (if configured)
sentry-cli issues list

# Or via web dashboard
```

**Key Metrics:**
- Error count
- Affected users
- Error rate trend
- Performance transactions

---

### Railway (Deployment & Logs)
**URL:** [https://railway.app/...]

**ใช้เมื่อ:**
- Deployment failures
- Application logs
- Service health

**วิธีดู:**
```bash
# CLI
railway logs

# Specific service
railway logs --service backend

# Follow logs
railway logs --follow
```

---

### Neon (Database)
**URL:** [https://neon.tech/...]

**ใช้เมื่อ:**
- Slow queries
- Connection issues
- Database errors

**Key Metrics:**
- Query duration
- Connection count
- Storage usage
- CPU usage

---

## Laravel Backend Debugging

### Enable Debug Mode (Local Only!)
```env
# .env
APP_DEBUG=true  # ⚠️ LOCAL ONLY!
APP_ENV=local
```

### Laravel Log
```bash
# Watch logs
tail -f storage/logs/laravel.log

# Search logs
grep "error" storage/logs/laravel.log
```

### Debug SQL Queries
```php
// Enable query log
DB::enableQueryLog();

// Your code here
$bots = Bot::with('conversations')->get();

// Get queries
$queries = DB::getQueryLog();
foreach ($queries as $query) {
    dump($query);
}

// Or log to file
Log::info('Queries', ['queries' => DB::getQueryLog()]);
```

### Laravel Debugbar (Local)
```bash
# Install
composer require barryvdh/laravel-debugbar --dev

# Auto-enabled in local
```

**Features:**
- SQL queries
- Timeline
- Memory usage
- Route info

---

## React Frontend Debugging

### React DevTools
```bash
# Install extension
# Chrome: React Developer Tools
# Firefox: React Developer Tools
```

**Features:**
- Component tree
- Props & state inspection
- Profiler

### Console Debugging
```typescript
// Debug renders
useEffect(() => {
  console.log('Component rendered', { props, state });
}, [props, state]);

// Debug API calls
axios.interceptors.request.use(request => {
  console.log('API Request:', request);
  return request;
});

axios.interceptors.response.use(response => {
  console.log('API Response:', response);
  return response;
});
```

### React Query Devtools
```typescript
// Add to main.tsx
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';

function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <YourApp />
      <ReactQueryDevtools initialIsOpen={false} />
    </QueryClientProvider>
  );
}
```

**Features:**
- Query cache inspection
- Mutation status
- Invalidation tracking

---

## API Debugging

### Test with curl
```bash
# GET request
curl -X GET https://api.botjao.com/api/v1/bots \
  -H "Authorization: Bearer $TOKEN"

# POST request
curl -X POST https://api.botjao.com/api/v1/bots \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Test Bot","channel_type":"line"}'

# Debug headers
curl -v https://api.botjao.com/api/v1/bots
```

### Test with Postman/Insomnia
1. Import API collection
2. Set environment variables
3. Test endpoints
4. Check response

---

## Database Debugging

### Slow Queries
```sql
-- Find slow queries (Neon Dashboard)
-- Or enable slow query log

-- Laravel
DB::listen(function ($query) {
    if ($query->time > 100) { // 100ms
        Log::warning('Slow query', [
            'sql' => $query->sql,
            'bindings' => $query->bindings,
            'time' => $query->time,
        ]);
    }
});
```

### Query Profiling
```sql
-- PostgreSQL
EXPLAIN ANALYZE
SELECT * FROM bots WHERE user_id = 1;

-- Check indexes
SELECT * FROM pg_indexes WHERE tablename = 'bots';
```

### Connection Issues
```bash
# Test connection
php artisan tinker
>>> DB::connection()->getPdo();

# Or
psql "postgresql://user:pass@host/db?sslmode=require"
```

---

## WebSocket Debugging

### Check Connection
```typescript
// Browser console
Echo.connector.pusher.connection.bind('state_change', (states) => {
  console.log('Connection state changed:', states);
});

Echo.connector.pusher.connection.bind('error', (err) => {
  console.error('Connection error:', err);
});
```

### Debug Broadcast
```php
// Laravel
Log::info('Broadcasting event', [
    'event' => MessageSent::class,
    'channel' => 'conversation.' . $conversationId,
    'data' => $message,
]);

// Check if event was dispatched
broadcast(new MessageSent($message));
```

### Reverb Logs
```bash
# Railway logs for Reverb service
railway logs --service reverb --follow
```

---

## Common Issues & Solutions

### 1. API Returns 500 Error

**Check:**
```bash
# 1. Laravel logs
tail -f storage/logs/laravel.log

# 2. Sentry
# Go to Sentry dashboard

# 3. Railway logs
railway logs --service backend
```

**Common Causes:**
- Database connection failed
- Missing environment variable
- PHP syntax error
- Unhandled exception

---

### 2. Page Not Loading / White Screen

**Check:**
```bash
# 1. Browser console
# F12 → Console tab

# 2. Network tab
# Check failed requests

# 3. Build logs
npm run build
```

**Common Causes:**
- JavaScript error
- Missing route
- Failed API call
- CORS issue

---

### 3. WebSocket Not Connecting

**Check:**
```typescript
// 1. Connection status
console.log(Echo.connector.pusher.connection.state);

// 2. Test connection
Echo.connector.pusher.connection.connect();

// 3. Check config
console.log(import.meta.env.VITE_REVERB_APP_KEY);
```

**Common Causes:**
- Wrong Reverb credentials
- CORS misconfigured
- Network firewall
- Reverb service down

---

### 4. Database Query Slow

**Check:**
```php
// Enable query log
DB::enableQueryLog();

// Your slow code
$bots = Bot::all();
foreach ($bots as $bot) {
    echo $bot->user->name; // N+1!
}

// Check queries
dd(DB::getQueryLog());
```

**Common Causes:**
- N+1 query problem
- Missing index
- Large dataset
- Complex join

---

## Performance Debugging

### Backend Profiling
```php
// Measure execution time
$start = microtime(true);

// Your code
$result = $this->heavyOperation();

$duration = microtime(true) - $start;
Log::info('Operation took ' . $duration . 's');
```

### Frontend Profiling
```typescript
// React Profiler
import { Profiler } from 'react';

function onRenderCallback(
  id, phase, actualDuration, baseDuration, startTime, commitTime
) {
  console.log(`${id} took ${actualDuration}ms`);
}

<Profiler id="Dashboard" onRender={onRenderCallback}>
  <Dashboard />
</Profiler>
```

### API Latency
```bash
# Measure API response time
time curl https://api.botjao.com/api/v1/bots
```

---

## Memory Leaks

### Backend
```php
// Check memory usage
Log::info('Memory usage', [
    'current' => memory_get_usage(true) / 1024 / 1024 . 'MB',
    'peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB',
]);

// Or in code
if (memory_get_usage(true) > 100 * 1024 * 1024) { // 100MB
    Log::warning('High memory usage!');
}
```

### Frontend
```typescript
// Chrome DevTools → Memory → Take Heap Snapshot

// Or use Performance tab
// Start recording → Interact → Stop → Analyze
```

---

## Agent Sets for Debugging

| Problem | Set | Command |
|---------|-----|---------|
| Production error | deployment | "debug production error" |
| Slow API | performance | "API endpoint ช้า" |
| WebSocket issue | webhook-debug | "WebSocket ไม่ทำงาน" |
| Database slow | database | "query ช้า" |
| Search ไม่เจอ | rag-debug | "semantic search ไม่เจอ" |

---

## Debugging Checklist

### เมื่อเจอ Bug:

- [ ] Can you reproduce?
- [ ] Check error logs (Sentry, Railway)
- [ ] Search memory (`/mem-search "bug description"`)
- [ ] Isolate the issue (minimal reproduction)
- [ ] Check recent changes (git log)
- [ ] Test in different environment
- [ ] Fix & verify
- [ ] Add test to prevent regression

---

## Tools Reference

### Laravel
```bash
php artisan tinker          # REPL
php artisan route:list      # List routes
php artisan queue:work      # Process queue jobs
php artisan cache:clear     # Clear cache
php artisan config:clear    # Clear config cache
```

### Database
```bash
php artisan db:show         # Show database info
php artisan db:table users  # Show table structure
php artisan migrate:status  # Migration status
```

### Git
```bash
git log --oneline -10       # Recent commits
git diff HEAD~1             # Last change
git blame file.php          # Who changed what
```

---

## Remote Debugging

### SSH to Railway (if available)
```bash
railway run bash
```

### Run Commands on Railway
```bash
railway run php artisan tinker
railway run php artisan migrate:status
```

---

## Best Practices

### 1. Always Check Logs First
```
Sentry → Railway → Browser Console
```

### 2. Reproduce Locally
```
Can't fix what you can't reproduce
```

### 3. Isolate the Issue
```
Binary search: disable half → test → repeat
```

### 4. Search Memory
```
/mem-search "similar bug description"
```

### 5. Use Appropriate Agent Set
```
Don't debug manually when agent can help
```

---

## Resources

- [Laravel Debugging Docs](https://laravel.com/docs/logging)
- [React DevTools Guide](https://react.dev/learn/react-developer-tools)
- [Chrome DevTools](https://developer.chrome.com/docs/devtools/)
