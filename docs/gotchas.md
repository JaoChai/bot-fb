# Gotchas & Known Issues

รวมปัญหาที่พบบ่อยและวิธีแก้ไข

## Laravel Backend

### config() Returns null Despite Default Value

**Problem:**
```php
config('app.name', 'Default')  // Returns null แทน 'Default'
```

**Solution:**
```php
config('app.name') ?? 'Default'  // ✅ Works
```

**Why:** Laravel's `config()` ไม่ใช่ null coalescing ถ้า key ไม่มีใน config file จะ return null ทันที ไม่สน default parameter

**Reference:** [Issue #...](#)

---

### API Response Wrapped in {data:X}

**Problem:**
```typescript
// Frontend expects:
{ id: 1, name: 'Bot' }

// But gets:
{ data: { id: 1, name: 'Bot' } }
```

**Solution:**
```typescript
// Always access response.data
const bot = response.data.data;  // Note: double .data

// Or use axios interceptor
axios.interceptors.response.use(
  response => response.data,  // Unwrap first layer
  error => Promise.reject(error)
);
```

**Why:** Laravel API Resource wrapper + Axios response wrapper = double nesting

---

### Railway serve.json Fails

**Problem:**
```bash
# Railway deploy fails with serve.json
Error: Cannot find module 'serve'
```

**Solution:**
```javascript
// Use Express server instead (server.js)
const express = require('express');
const path = require('path');

const app = express();
const port = process.env.PORT || 3000;

app.use(express.static(path.join(__dirname, 'dist')));

app.get('*', (req, res) => {
  res.sendFile(path.join(__dirname, 'dist', 'index.html'));
});

app.listen(port);
```

**Why:** Railway ไม่มี `serve` package ติดตั้งอัตโนมัติ

---

## Database

### N+1 Query Problem

**Problem:**
```php
$bots = Bot::all();
foreach ($bots as $bot) {
    echo $bot->user->name; // N queries!
}
```

**Solution:**
```php
$bots = Bot::with('user')->get();
foreach ($bots as $bot) {
    echo $bot->user->name; // Only 2 queries total
}
```

**Detection:**
- Use Laravel Debugbar (dev)
- Check Neon query logs
- Use `DB::enableQueryLog()`

---

### Migration Rollback Fails on SQLite

**Problem:**
```bash
php artisan migrate:rollback
# Error: no such table: ...
```

**Solution:**
```php
// In migration down()
public function down()
{
    // Check if SQLite
    if (DB::connection()->getDriverName() === 'sqlite') {
        // Handle SQLite-specific rollback
    }

    // Regular rollback
}
```

**Why:** SQLite ไม่ support ALTER TABLE บาง operations

**Reference:** backend/database/migrations/2025_12_31_130000_migrate_timestamps_utc_to_bangkok.php:XXX

---

### Race Condition in Customer Profile Creation

**Problem:**
- สร้าง customer profile ซ้ำเมื่อมี concurrent requests
- UniqueConstraintViolationException

**Solution:**
```php
DB::transaction(function () use ($userId) {
    // Use SELECT FOR UPDATE
    $profile = CustomerProfile::lockForUpdate()
        ->where('user_id', $userId)
        ->first();

    if (!$profile) {
        CustomerProfile::create(['user_id' => $userId]);
    }
});

// Or use firstOrCreate with DB lock
$profile = DB::transaction(function () use ($userId) {
    return CustomerProfile::firstOrCreate(['user_id' => $userId]);
});
```

**Reference:** PR #94

---

## React Frontend

### React Query Cache Not Updating

**Problem:**
```typescript
// Mutation success แต่ UI ไม่ update
mutate(data);
```

**Solution:**
```typescript
const mutation = useMutation({
  mutationFn: updateBot,
  onSuccess: () => {
    // Invalidate query
    queryClient.invalidateQueries({ queryKey: ['bots'] });

    // Or update cache directly
    queryClient.setQueryData(['bot', id], newData);
  },
});
```

**Why:** React Query ไม่รู้ว่า mutation เปลี่ยน data - ต้องบอกให้ invalidate

**Reference:** [react-query-expert skill](../CLAUDE.md#required-skills)

---

### Toggle Switch Not Working

**Problem:**
```typescript
<Toggle checked={isActive} onChange={handleToggle} />
// Clicking ไม่ทำงาน
```

**Solution:**
```typescript
// ต้อง update state ด้วย
const [isActive, setIsActive] = useState(false);

<Toggle
  checked={isActive}
  onChange={(checked) => {
    setIsActive(checked);
    // Call API
    updateBot({ is_active: checked });
  }}
/>
```

**Why:** Controlled component ต้องมี state management

---

## WebSocket / Real-time

### Message ไม่ Update Real-time

**Problem:**
```typescript
// ส่งข้อความแล้ว แต่ไม่เห็น update
```

**Solution:**
```typescript
// 1. ตรวจสอบ Echo connection
Echo.connector.pusher.connection.bind('connected', () => {
  console.log('Connected to Reverb');
});

// 2. ตรวจสอบ channel subscription
Echo.private(`conversation.${conversationId}`)
  .listen('MessageSent', (e) => {
    console.log('New message:', e.message);
    // Update UI
  });

// 3. ตรวจสอบ Laravel broadcast config
// config/broadcasting.php
```

**Debug:**
- ดู Railway logs สำหรับ Reverb
- ตรวจสอบ WebSocket connection status
- ใช้ `websocket-debugger` skill

**Reference:** [websocket-debugger skill](docs/agent-sets.md#webhook-debug)

---

## LINE Integration

### Webhook Not Receiving Messages

**Problem:**
- LINE webhook ไม่ส่ง messages มา
- 200 OK แต่ไม่มี data

**Solution:**
```php
// 1. Verify webhook signature
public function handleWebhook(Request $request)
{
    $signature = $request->header('X-Line-Signature');

    if (!$this->verifySignature($signature, $request->getContent())) {
        return response()->json(['error' => 'Invalid signature'], 401);
    }

    // Process webhook
}

// 2. Check webhook URL
// Must be HTTPS
// Must return 200 within 3 seconds

// 3. Test webhook locally with ngrok
ngrok http 8000
```

**Debug:**
- ใช้ LINE Messaging API Console
- ดู Railway logs
- ใช้ `line-expert` skill

---

## Authentication

### JWT Token Expired Silently

**Problem:**
```typescript
// API calls fail with 401 แต่ไม่มี error message
```

**Solution:**
```typescript
// Add axios interceptor to handle token refresh
axios.interceptors.response.use(
  response => response,
  async error => {
    if (error.response?.status === 401) {
      // Try refresh token
      try {
        const { data } = await axios.post('/api/auth/refresh');
        localStorage.setItem('access_token', data.access_token);

        // Retry original request
        error.config.headers.Authorization = `Bearer ${data.access_token}`;
        return axios.request(error.config);
      } catch {
        // Refresh failed - redirect to login
        window.location.href = '/login';
      }
    }

    return Promise.reject(error);
  }
);
```

---

## Semantic Search

### Thai Search Not Finding Results

**Problem:**
```sql
-- Search "ขอบคุณ" แต่ไม่เจอ
```

**Solution:**
```php
// 1. Check tokenization
$tokens = $this->tokenizer->tokenize('ขอบคุณ');
// Should be: ['ขอบคุณ'] not ['ขอ', 'บ', 'คุณ']

// 2. Adjust similarity threshold
// Use 0.7 for Thai instead of 0.8
$results = DB::table('embeddings')
    ->where('similarity', '>=', 0.7)
    ->get();

// 3. Use hybrid search (semantic + keyword)
```

**Debug:**
- ใช้ `thai-nlp` skill
- ตรวจสอบ embedding quality
- ปรับ threshold

**Reference:** [thai-nlp skill](docs/agent-sets.md#required-skills)

---

## Deployment

### Railway Deploy Fails with Build Error

**Problem:**
```bash
Error: Cannot find module '@vitejs/plugin-react'
```

**Solution:**
```json
// package.json - ย้าย devDependencies ที่จำเป็นต่อ build ไปที่ dependencies
{
  "dependencies": {
    "@vitejs/plugin-react": "^4.0.0",
    "vite": "^5.0.0"
  }
}
```

**Why:** Railway ไม่ install devDependencies ใน production build

---

### Environment Variables Not Working

**Problem:**
```bash
# Railway deploy แล้ว env ไม่ทำงาน
```

**Solution:**
```bash
# 1. Set in Railway dashboard
# Variables > Add Variable

# 2. Restart service after adding
# (Railway auto-restart may not pick up new vars)

# 3. For Vite (frontend), use VITE_ prefix
VITE_API_URL=https://api.botjao.com
```

**Debug:**
- ดู Railway deployment logs
- ใช้ `railway-deployer` skill

---

## Testing

### Tests Fail on CI but Pass Locally

**Problem:**
```bash
# Local: ✅ Tests pass
# CI: ❌ Tests fail
```

**Solution:**
```yaml
# .github/workflows/test.yml
env:
  DB_CONNECTION: sqlite
  DB_DATABASE: ':memory:'
  CACHE_DRIVER: array
  QUEUE_CONNECTION: sync
  SESSION_DRIVER: array
```

**Why:** CI environment ต่างจาก local (database, cache, etc.)

---

## Performance

## Common False Assumptions (Debugging Traps)

ข้อสมมติฐานที่มักผิดเมื่อ debug - ตรวจสอบให้แน่ใจก่อนแก้!

| คิดว่า... | จริงๆ คือ... | วิธีตรวจสอบ |
|----------|-------------|-------------|
| Cache ไม่ update | Code ไม่ได้ถูกใช้งาน | `grep -r "ComponentName" src/` |
| Deploy สำเร็จแล้ว | ใช้ cached build เก่า | ตรวจ commitHash ใน deployment |
| Component มีแล้ว | ไม่ได้ import/ใช้งาน | grep หา import statement |
| Type error เล็กน้อย | มี duplicate types 2 ชุด | ตรวจสอบ import paths ทั้งหมด |
| UI ไม่ render | Component ไม่ได้ถูกเรียกใช้ | ตรวจสอบ parent component |
| Bundle มี code | Code อยู่แต่ไม่ถูก execute | ตรวจสอบ conditional rendering |

### ตัวอย่างที่พบบ่อย

**Case: Component exists but not rendered**
```typescript
// ❌ มี StickerReplySection.tsx แต่ไม่ได้ใช้
// BotSettingsPage.tsx ใช้ inline code แทน

// ✅ วิธีตรวจสอบ
grep -r "StickerReplySection" frontend/src/
// ถ้าไม่เจอ import → component ไม่ได้ถูกใช้งาน
```

**Case: Deploy success but old code**
```bash
# ❌ Deployment สำเร็จแต่ไม่มี commitHash
# → Railway ใช้ cached build

# ✅ วิธีตรวจสอบ
railway logs --service frontend --json | grep commitHash
# ถ้า commitHash ว่าง → ใช้ cache เก่า
```

---

## Quick Fixes Reference

| Problem | Quick Fix |
|---------|-----------|
| config() null | Use `??` operator |
| API double wrap | Access `response.data.data` |
| N+1 queries | Use `with()` eager loading |
| Thai search ไม่เจอ | Lower threshold to 0.7 |
| WebSocket ขาด | Check Reverb connection |
| Deploy ล้ม | Check devDependencies → dependencies |
| Tests fail CI | Set proper env vars |
| AI ช้า | Use model tiers + caching |
| Component not rendering | Check if imported/used | `grep -r "ComponentName"` |

---

## ไม่เจอปัญหา?

1. Search memory first: `/mem-search "คำอธิบายปัญหา"`
2. Check agent sets: [agent-sets.md](agent-sets.md)
3. Use debugging guide: [debugging.md](debugging.md)
4. Ask in project chat
