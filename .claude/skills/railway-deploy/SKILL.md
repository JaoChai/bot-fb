---
name: railway-deploy
description: Deploy to Railway, check deployment logs, verify health endpoints, and troubleshoot deployment issues. Use when deploying, seeing 502/503 errors, checking production status, or debugging Railway-specific problems.
---

# Railway Deployment

## Quick Commands

```bash
# View logs
railway logs --service backend
railway logs --service frontend

# Check environment variables
railway variables --service backend

# Deploy
railway up --service backend
railway up --service frontend

# Link project (if needed)
railway link
```

---

## Deployment Checklist

### Before Deploy
- [ ] Local build passes: `npm run build` / `composer install`
- [ ] Health endpoint exists: `/api/health` returns 200
- [ ] Environment variables set in Railway dashboard
- [ ] Port uses `$PORT` env variable (not hardcoded)

### After Deploy
- [ ] Check logs for startup errors: `railway logs`
- [ ] Verify health: `curl https://backend-production-b216.up.railway.app/api/health`
- [ ] Test actual endpoint functionality

---

## Common Errors

| Error | Likely Cause | Fix |
|-------|-------------|-----|
| 502 Bad Gateway | App crashed on startup | Check `railway logs` for error |
| 503 Service Unavailable | Deployment in progress | Wait, then check health |
| CORS error | Backend not responding | Check backend health first |
| Connection refused | Wrong port binding | Use `$PORT` env variable |

---

## Port Configuration

### Laravel (Dockerfile)
```dockerfile
# Must use Railway's PORT
CMD php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
```

### Vite/React
```javascript
// vite.config.js
export default defineConfig({
  server: {
    port: parseInt(process.env.PORT) || 5173,
    host: '0.0.0.0'
  }
})
```

---

## Health Endpoint Pattern

```php
// routes/api.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'services' => [
            'database' => DB::connection()->getPdo() ? 'connected' : 'error',
        ]
    ]);
});
```

---

## Debugging Flow

```
Deploy fails → railway logs → Find error → Fix locally → Test → Deploy again
                    ↑                                              |
                    └──────────────────────────────────────────────┘
```

---

## Production URLs

| Service | URL |
|---------|-----|
| Frontend | https://frontend-production-9fe8.up.railway.app |
| Backend | https://backend-production-b216.up.railway.app |
| Health | https://backend-production-b216.up.railway.app/api/health |
