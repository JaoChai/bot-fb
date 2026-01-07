---
name: railway-deployer
description: "Debug Railway deployment, production issues, environment variables, logging - ใช้เมื่อ deploy ไม่ผ่าน, logs หาย, env ไม่ถูก, cache issues บน production, error ที่เกิดเฉพาะ production, health check fail"
---

# Railway Deployer & Production Debugger

Debug และแก้ไขปัญหา deployment บน Railway สำหรับ BotFacebook

## Architecture Overview

```
Railway Project
├── api (Laravel Backend)
│   ├── Dockerfile / Nixpacks
│   ├── Environment Variables
│   └── Health Check: /api/health
├── frontend (React + Vite)
│   ├── Express server (serve.js)
│   └── Static files
├── reverb (WebSocket Server)
│   └── php artisan reverb:start
└── PostgreSQL (Neon - External)
```

## Key Files

| File | Purpose |
|------|---------|
| `backend/Dockerfile` | Laravel container build |
| `frontend/serve.js` | Express static server |
| `frontend/package.json` | Start script for Railway |
| `backend/.env.example` | Environment template |

---

## Common Issues & Solutions

### Issue 1: Deploy ไม่ผ่าน - Build Error

**Symptoms:** Railway build failed

**Debug Steps:**
1. ดู build logs
   ```bash
   railway logs --service api
   ```

2. Common build errors:
   | Error | Solution |
   |-------|----------|
   | `composer install failed` | Check PHP version in Dockerfile |
   | `npm ERR!` | Check Node version, clear cache |
   | `Memory exhausted` | Add `COMPOSER_MEMORY_LIMIT=-1` |

**Solution: Dockerfile optimization**
```dockerfile
# Set memory limit
ENV COMPOSER_MEMORY_LIMIT=-1

# Use production dependencies
RUN composer install --no-dev --optimize-autoloader
```

### Issue 2: Logs ไม่แสดง / หาย

**Symptoms:** ใช้ `Log::info()` แต่ไม่เห็นใน Railway logs

**Root Cause:** Laravel log ไปที่ file ไม่ใช่ stdout

**Solution:**
```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['stderr'],  // เปลี่ยนจาก daily เป็น stderr
    ],
    'stderr' => [
        'driver' => 'monolog',
        'handler' => StreamHandler::class,
        'with' => [
            'stream' => 'php://stderr',
        ],
    ],
],
```

หรือใช้ `error_log()` โดยตรง:
```php
error_log('Debug: ' . json_encode($data));
```

### Issue 3: Environment Variables ไม่ถูก

**Symptoms:** Config ผิด, API key หาย

**Debug Steps:**
1. ตรวจสอบ env บน Railway
   ```bash
   railway variables
   ```

2. ตรวจสอบใน Laravel
   ```php
   // ใน tinker หรือ controller
   dd(config('services.openai.key'));
   dd(env('OPENAI_API_KEY'));
   ```

**Gotcha: config() vs env()**
```php
// ❌ Wrong - env() อาจ return null หลัง cache
$key = env('OPENAI_API_KEY');

// ✅ Correct - ใช้ config()
$key = config('services.openai.key');

// ⚠️ Gotcha - config default ไม่ทำงานถ้า env return null
$value = config('app.setting', 'default');  // อาจได้ null
$value = config('app.setting') ?? 'default'; // ✅ ใช้แบบนี้แทน
```

### Issue 4: Cache Issues บน Production

**Symptoms:** Data เก่า, config ไม่ update

**Debug Steps:**
1. ตรวจสอบ cache driver
   ```php
   error_log('Cache driver: ' . config('cache.default'));
   ```

2. Clear cache (ระวัง production!)
   ```bash
   railway run --service api php artisan cache:clear
   railway run --service api php artisan config:clear
   ```

**Solution: ใช้ Redis/Database cache แทน file**
```php
// config/cache.php
'default' => env('CACHE_DRIVER', 'database'),
```

### Issue 5: Health Check Fail

**Symptoms:** Railway restart service บ่อย

**Debug:**
1. ทดสอบ health endpoint
   ```bash
   curl https://api.botjao.com/api/health
   ```

2. ตรวจสอบ response time
   - Railway default timeout: 5s
   - ถ้า health check ช้า → fail

**Solution:**
```php
// routes/api.php
Route::get('/health', function () {
    // Don't do heavy operations
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
    ]);
});
```

### Issue 6: Frontend serve.json ไม่ทำงาน

**Symptoms:** SPA routing 404 บน production

**Root Cause:** Railway ใช้ Nixpacks ไม่อ่าน serve.json

**Solution: ใช้ Express server**
```javascript
// frontend/serve.js
const express = require('express');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3000;

app.use(express.static(path.join(__dirname, 'dist')));

// SPA fallback
app.get('*', (req, res) => {
    res.sendFile(path.join(__dirname, 'dist', 'index.html'));
});

app.listen(PORT, () => {
    console.log(`Server running on port ${PORT}`);
});
```

```json
// package.json
{
    "scripts": {
        "start": "node serve.js"
    }
}
```

---

## Railway CLI Commands

```bash
# Login
railway login

# Link project
railway link

# View logs
railway logs
railway logs --service api
railway logs --service frontend

# Run command in service
railway run --service api php artisan tinker
railway run --service api php artisan migrate

# View variables
railway variables
railway variables --service api

# Deploy
railway up
```

---

## Environment Variables Checklist

### Backend (Laravel)
```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...

DB_CONNECTION=pgsql
DATABASE_URL=postgresql://...

CACHE_DRIVER=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database

LOG_CHANNEL=stderr
LOG_LEVEL=info

REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
```

### Frontend
```env
VITE_API_URL=https://api.botjao.com
VITE_REVERB_HOST=reverb.botjao.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

---

## Debug Workflow บน Production

1. **เพิ่ม logging ก่อน deploy**
   ```php
   error_log('[DEBUG] Data: ' . json_encode($data));
   ```

2. **Deploy และดู logs**
   ```bash
   railway up && railway logs -f
   ```

3. **Reproduce issue**

4. **ดู logs แล้วแก้**

5. **ลบ debug logs ก่อน merge**

---

## Checklist ก่อน Deploy

- [ ] `APP_DEBUG=false` บน production?
- [ ] Sensitive env vars ตั้งค่าแล้ว?
- [ ] `php artisan config:cache` รันแล้ว?
- [ ] Database migrations รันแล้ว?
- [ ] Health check endpoint ทำงาน?
- [ ] Frontend build สำเร็จ?
- [ ] CORS config ถูกต้อง?

---

## Real Bugs จากโปรเจคนี้ (Git History)

### Bug 1: Frontend SPA Routing 404
**Commits:** `e45fa31`, `f7fb915`

**Problem:** Deploy frontend แล้ว direct URL access ได้ 404

**Root Cause:** Railway Nixpacks ไม่อ่าน serve.json

**Solution ที่ไม่ work:**
```json
// ❌ serve.json - Railway ไม่อ่าน
{
    "rewrites": [{ "source": "**", "destination": "/index.html" }]
}
```

**Solution ที่ work:**
```javascript
// ✅ serve.js - Express server
const express = require('express');
const path = require('path');

const app = express();
app.use(express.static(path.join(__dirname, 'dist')));

// SPA fallback - ทุก route ไป index.html
app.get('*', (req, res) => {
    res.sendFile(path.join(__dirname, 'dist', 'index.html'));
});

app.listen(process.env.PORT || 3000);
```

```json
// package.json
{
    "scripts": {
        "start": "node serve.js"
    }
}
```

```json
// railway.json - force Nixpacks
{
    "build": { "builder": "NIXPACKS" },
    "deploy": { "startCommand": "npm start" }
}
```

---

### Bug 2: Laravel authorizeResource ไม่ทำงาน
**Commit:** `7273832` - replace authorizeResource with per-method authorize calls

**Problem:** Policy ไม่ถูกเรียก บน production

**Solution:**
```php
// ❌ authorizeResource ใน constructor - ไม่ work บาง environment
public function __construct()
{
    $this->authorizeResource(Bot::class, 'bot');
}

// ✅ Per-method authorize - work ทุกที่
public function show(Bot $bot)
{
    $this->authorize('view', $bot);
    return new BotResource($bot);
}
```

---

### Bug 3: Policy ไม่ Register
**Commit:** `6ff9061` - register QuickReplyPolicy in AppServiceProvider

**Problem:** Policy ไม่ทำงาน แม้ file มีอยู่

**Solution:** Register manually
```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::policy(QuickReply::class, QuickReplyPolicy::class);
}
```

---

### Bug 4: Cache Driver Issues
**Commits:** `c788001`, `c08786d`, `d1a4ad7`

**Problem:** Cache ไม่ทำงานบน Railway

**Debug Steps:**
```php
// เพิ่ม logging เพื่อ debug
error_log('Cache driver: ' . config('cache.default'));
error_log('Cache store: ' . config('cache.stores.' . config('cache.default')));
```

**Solution:** ใช้ database cache แทน file
```env
CACHE_DRIVER=database
SESSION_DRIVER=database
```

---

### Bug 5: Logs ไม่เห็นบน Railway
**Commit:** `d1a4ad7` - use error_log for Railway visibility

**Problem:** `Log::info()` ไม่แสดงใน Railway logs

**Solution:**
```php
// ใช้ error_log() แทน Log facade
error_log('[DEBUG] ' . json_encode($data));

// หรือ config logging.php
'default' => 'stderr',
```

---

## Gotchas เฉพาะ BotFacebook

| Issue | Solution |
|-------|----------|
| `config('x','')` returns null | ใช้ `config('x') ?? ''` |
| API response wrapped | Access `response.data.data` |
| serve.json ไม่ work | ใช้ Express server |
| Policy ไม่ทำงาน | Register ใน AppServiceProvider |
| Logs หาย | ใช้ `error_log()` หรือ stderr channel |
