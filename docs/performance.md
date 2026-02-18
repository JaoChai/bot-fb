# Performance Optimization

คู่มือการเพิ่มประสิทธิภาพสำหรับโปรเจกต์ BotFacebook

## Performance Targets

| Metric | Target | Current | Status |
|--------|--------|---------|--------|
| API Response | < 500ms | ~300ms | ✅ Good |
| Page Load (FCP) | < 1.8s | ~1.5s | ✅ Good |
| Page Load (LCP) | < 2.5s | ~2s | ✅ Good |
| Database Query | < 100ms | varies | 🔄 Monitor |
| Bundle Size | < 500KB | ~400KB | ✅ Good |

---

## Database Optimization

### N+1 Query Problem
```php
// ❌ Bad - N+1 queries
$bots = Bot::all(); // 1 query
foreach ($bots as $bot) {
    echo $bot->user->name; // N queries
}

// ✅ Good - 2 queries total
$bots = Bot::with('user')->get();
foreach ($bots as $bot) {
    echo $bot->user->name;
}
```

### Eager Loading
```php
// Load multiple relationships
$bots = Bot::with(['user', 'conversations', 'conversations.messages'])->get();

// Conditional eager loading
$bots = Bot::when($includeStats, function ($query) {
    $query->withCount('conversations');
})->get();
```

### Database Indexes
```php
// migration
Schema::table('conversations', function (Blueprint $table) {
    $table->index('bot_id'); // Single column
    $table->index(['bot_id', 'status']); // Composite
    $table->index('created_at'); // For sorting
});
```

### Query Optimization
```php
// ❌ Bad - Select all columns
$users = DB::table('users')->get();

// ✅ Good - Select only needed
$users = DB::table('users')->select('id', 'name', 'email')->get();

// ❌ Bad - Count with get()
$count = Bot::where('status', 'active')->get()->count();

// ✅ Good - Count directly
$count = Bot::where('status', 'active')->count();
```

### Pagination
```php
// ✅ For UI (with page numbers)
$bots = Bot::paginate(15);

// ✅ For API/infinite scroll (faster)
$bots = Bot::cursorPaginate(15);
```

---

## Caching

### Cache Configuration
```php
// config/cache.php
'default' => env('CACHE_DRIVER', 'redis'),
```

### Basic Caching
```php
// Cache forever (until manually cleared)
Cache::forever('config.site_name', $siteName);

// Cache with TTL
Cache::put('user.' . $userId, $user, now()->addMinutes(60));

// Cache with remember (auto-cache if not exists)
$value = Cache::remember('expensive.operation', 600, function () {
    return DB::table('stats')->get();
});
```

### Model Caching
```php
// Using cache for model queries
public function getActiveBotsCount()
{
    return Cache::remember('bots.active.count', 300, function () {
        return Bot::where('status', 'active')->count();
    });
}
```

### Cache Invalidation
```php
// Clear specific cache
Cache::forget('bots.active.count');

// Clear by tags
Cache::tags(['bots', 'stats'])->flush();

// In model event
protected static function booted()
{
    static::saved(function () {
        Cache::forget('bots.active.count');
    });
}
```

### What to Cache
```
✅ Cache:
- Config values
- Computed values (stats, counts)
- External API responses
- Database query results (rarely changed)
- User sessions

❌ Don't cache:
- Real-time data
- User-specific data (without user key)
- Frequently changing data
```

---

## Frontend Performance

### Code Splitting
```typescript
// Lazy load routes
const Dashboard = lazy(() => import('./pages/Dashboard'));
const BotDetail = lazy(() => import('./pages/BotDetail'));

// Usage
<Suspense fallback={<Loading />}>
  <Routes>
    <Route path="/dashboard" element={<Dashboard />} />
    <Route path="/bots/:id" element={<BotDetail />} />
  </Routes>
</Suspense>
```

### Image Optimization
```typescript
// Use optimized formats
<img
  src="/images/avatar.webp"
  alt="User avatar"
  loading="lazy"  // Native lazy loading
  width={100}
  height={100}
/>

// Responsive images
<img
  srcSet="
    /images/avatar-320.webp 320w,
    /images/avatar-640.webp 640w,
    /images/avatar-1024.webp 1024w
  "
  sizes="(max-width: 768px) 100vw, 50vw"
  src="/images/avatar-640.webp"
  alt="User avatar"
/>
```

### Bundle Size
```bash
# Analyze bundle
npm run build -- --analyze

# Check bundle size
npm run build
ls -lh dist/assets
```

### React Query Optimization
```typescript
// Stale time - don't refetch if data is fresh
useQuery({
  queryKey: ['bots'],
  queryFn: fetchBots,
  staleTime: 5 * 60 * 1000, // 5 minutes
});

// Cache time - keep in cache even when unused
useQuery({
  queryKey: ['bot', id],
  queryFn: () => fetchBot(id),
  cacheTime: 10 * 60 * 1000, // 10 minutes
});

// Prefetch data
queryClient.prefetchQuery({
  queryKey: ['bots'],
  queryFn: fetchBots,
});
```

---

## API Optimization

### Response Caching
```php
// Cache API responses
public function index()
{
    $cacheKey = 'api.bots.' . auth()->id();

    $bots = Cache::remember($cacheKey, 300, function () {
        return Bot::where('user_id', auth()->id())->get();
    });

    return response()->json(['data' => $bots]);
}
```

### Pagination
```php
// Use cursor pagination for large datasets
public function index()
{
    $bots = Bot::cursorPaginate(15);

    return response()->json([
        'data' => $bots->items(),
        'next_cursor' => $bots->nextCursor()?->encode(),
    ]);
}
```

### Partial Responses
```php
// Allow field selection
public function index(Request $request)
{
    $fields = $request->query('fields', '*');

    $bots = Bot::select(explode(',', $fields))->get();

    return response()->json(['data' => $bots]);
}

// GET /api/v1/bots?fields=id,name,status
```

---

## Monitoring

### Neon Database Metrics
- Query duration
- Connection count
- CPU usage
- Storage usage

### Sentry Performance
- API endpoint latency
- Slow queries
- Transaction duration

### Laravel Telescope (Development)
```bash
composer require laravel/telescope --dev
php artisan telescope:install
```

---

## Performance Testing

### Database Query Analysis
```php
DB::enableQueryLog();

// Your code

$queries = DB::getQueryLog();
foreach ($queries as $query) {
    Log::info('Query', [
        'sql' => $query['query'],
        'time' => $query['time'] . 'ms',
    ]);
}
```

### API Load Testing
```bash
# Using Apache Bench
ab -n 1000 -c 10 https://api.botjao.com/api/v1/bots

# Using hey
hey -n 1000 -c 10 https://api.botjao.com/api/v1/bots
```

### Frontend Performance
```typescript
// Measure component render time
const start = performance.now();

// Your component code

console.log('Render time:', performance.now() - start);
```

---

## Quick Wins

### 1. Enable OpCache (PHP)
```ini
; php.ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
```

### 2. Composer Optimize
```bash
composer install --optimize-autoloader --no-dev
```

### 3. Config Cache
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 4. Database Connection Pooling
Already handled by Neon PostgreSQL

### 5. CDN for Static Assets
Use Railway/Vercel CDN

---

## Checklist

เมื่อเจอปัญหาช้า:

- [ ] Check database queries (N+1?)
- [ ] Add database indexes
- [ ] Enable caching
- [ ] Optimize images
- [ ] Code split large pages
- [ ] Check bundle size
- [ ] Profile with Sentry
- [ ] Use performance set
