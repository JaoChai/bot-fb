---
id: cache-002-http-caching
title: HTTP Caching Headers
impact: MEDIUM
impactDescription: "Missing or incorrect HTTP cache headers"
category: cache
tags: [http, cache, headers, cdn]
relatedRules: [cache-001-query-caching, frontend-004-asset-loading]
---

## Symptom

- Browser re-fetching unchanged resources
- No caching at CDN level
- Slow repeat visits
- High bandwidth usage

## Root Cause

1. Missing Cache-Control headers
2. Wrong cache directives
3. No ETag/Last-Modified
4. Private data cached publicly
5. Static assets not cached

## Diagnosis

### Quick Check

```bash
# Check cache headers
curl -I https://api.botjao.com/api/models

# Look for:
# Cache-Control: ...
# ETag: "..."
# Last-Modified: ...
```

### Detailed Analysis

```bash
# Check all response headers
curl -v https://api.botjao.com/api/bots 2>&1 | grep -i cache

# Chrome DevTools
# Network tab > Click request > Headers
```

## Measurement

```
Before: No cache headers, always re-fetch
Target: Proper cache headers, CDN caching
```

## Solution

### Fix Steps

1. **Add cache headers to API responses**
```php
// Middleware for cacheable responses
class CacheResponseMiddleware
{
    public function handle($request, Closure $next, $maxAge = 60)
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->isSuccessful()) {
            $response->header('Cache-Control', "public, max-age={$maxAge}");
        }

        return $response;
    }
}

// Usage in routes
Route::get('/api/models', [ModelController::class, 'index'])
    ->middleware('cache.response:3600');
```

2. **ETag for conditional requests**
```php
// Controller with ETag
public function show(Bot $bot): JsonResponse
{
    $etag = md5($bot->updated_at->timestamp);

    if (request()->header('If-None-Match') === $etag) {
        return response()->json(null, 304);
    }

    return response()
        ->json(new BotResource($bot))
        ->header('ETag', $etag)
        ->header('Cache-Control', 'private, max-age=60');
}
```

3. **Different cache strategies**
```php
// Public data (can be cached by CDN)
return response()
    ->json($data)
    ->header('Cache-Control', 'public, max-age=300, s-maxage=600');

// Private data (user-specific)
return response()
    ->json($userData)
    ->header('Cache-Control', 'private, max-age=60');

// No cache (sensitive data)
return response()
    ->json($sensitiveData)
    ->header('Cache-Control', 'no-store');

// Stale-while-revalidate
return response()
    ->json($data)
    ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
```

4. **Static assets in Vite**
```typescript
// vite.config.ts
export default defineConfig({
  build: {
    // Add hash to filenames for cache busting
    rollupOptions: {
      output: {
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash].[ext]',
      },
    },
  },
});
```

5. **Nginx/CDN configuration**
```nginx
# Static assets - long cache
location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}

# API responses
location /api/ {
    add_header Cache-Control "no-cache";
    add_header Vary "Authorization";
}
```

### Cache-Control Directive Guide

| Directive | Use Case |
|-----------|----------|
| `public` | Can be cached by CDN/proxies |
| `private` | Only browser can cache |
| `no-cache` | Must revalidate before use |
| `no-store` | Never cache |
| `max-age=N` | Cache for N seconds |
| `s-maxage=N` | CDN cache for N seconds |
| `immutable` | Never changes (for hashed assets) |
| `stale-while-revalidate=N` | Use stale while fetching fresh |

## Verification

```bash
# Check headers are set
curl -I https://api.botjao.com/api/models | grep -i cache

# Check CDN caching (Cloudflare)
# cf-cache-status: HIT

# Browser DevTools
# Network tab > Size column shows "(from cache)"
```

## Prevention

- Set cache headers for all responses
- Use hashed filenames for assets
- Configure CDN cache rules
- Test with curl -I
- Monitor CDN cache hit rate

## Project-Specific Notes

**BotFacebook Context:**
- CDN: Cloudflare
- API responses: 60s private
- Static assets: 1 year with hash
- LLM models list: 1 hour public
- User data: private, no CDN
