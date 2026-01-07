---
name: websocket-debugger
description: "Debug WebSocket, Reverb, Laravel Echo, real-time issues - ใช้เมื่อ broadcast ไม่ถึง, event ไม่ fire, race condition, unread count ผิด, message ไม่ update, real-time ไม่ทำงาน, connection หลุด, channel subscription fail"
---

# WebSocket & Reverb Debugger

Debug real-time communication issues ระหว่าง Laravel Reverb และ React (Laravel Echo)

## Architecture Overview

```
Frontend (React)          Backend (Laravel)           Reverb Server
─────────────────        ──────────────────          ─────────────
useEcho hooks      <-->   BroadcastEvent      <-->   WebSocket Server
- useConversationChannel  - MessageSent              (Railway)
- useBotChannel           - ConversationUpdated
- useAdminChannel         - AdminNotification
```

## Key Files

| Layer | File | Purpose |
|-------|------|---------|
| Frontend | `frontend/src/hooks/useEcho.ts` | Echo hooks for subscriptions |
| Frontend | `frontend/src/lib/echo.ts` | Echo client configuration |
| Frontend | `frontend/src/types/realtime.ts` | Channel/Event type definitions |
| Backend | `backend/app/Events/*.php` | Broadcast event classes |
| Backend | `backend/config/reverb.php` | Reverb server config |
| Backend | `backend/config/broadcasting.php` | Broadcasting driver config |
| Backend | `backend/routes/channels.php` | Channel authorization |

---

## Common Issues & Solutions

### Issue 1: Event ไม่ถึง Frontend

**Symptoms:** broadcast แล้วแต่ frontend ไม่ได้รับ

**Debug Steps:**
1. ตรวจสอบ channel name ตรงกัน
   ```typescript
   // Frontend
   echo.private(`conversation.${id}`)

   // Backend - broadcastOn()
   return new PrivateChannel('conversation.' . $this->conversation->id);
   ```

2. ตรวจสอบ event name มี dot prefix
   ```typescript
   // Frontend ต้องมี dot
   .listen('.message.sent', ...)  // ถูก
   .listen('message.sent', ...)   // ผิด!
   ```

3. ตรวจสอบ broadcastAs() ใน Event
   ```php
   public function broadcastAs(): string
   {
       return 'message.sent';  // ไม่ต้องมี dot
   }
   ```

### Issue 2: Race Condition - Data เก่า

**Symptoms:** broadcast ได้รับแต่ data เป็นค่าเก่า

**Root Cause:** Event ถูก dispatch ก่อน data save เสร็จ

**Solution:** ใช้ `$afterCommit = true`
```php
class MessageSent implements ShouldBroadcast
{
    public $afterCommit = true;  // รอ transaction commit ก่อน
}
```

หรือ refresh data ก่อน broadcast:
```php
public function __construct(Message $message)
{
    $this->message = $message->fresh();  // refresh จาก DB
}
```

### Issue 3: Unread Count ผิด

**Symptoms:** unread count ไม่ตรงกับความเป็นจริง

**Debug Steps:**
1. ตรวจสอบ timing ของ markAsRead
2. ตรวจสอบ broadcast payload มี unread_count ล่าสุด
3. ใช้ `fresh()` หรือ `refresh()` ก่อน broadcast

```php
// ใน Event constructor
$this->conversation = $conversation->fresh();
$this->unreadCount = $conversation->messages()
    ->whereNull('read_at')
    ->count();
```

### Issue 4: Connection หลุดบ่อย

**Symptoms:** WebSocket disconnect แล้ว reconnect ไม่ได้

**Debug Steps:**
1. ตรวจสอบ Reverb server status บน Railway
2. ตรวจสอบ CORS config
3. ตรวจสอบ broadcasting/auth endpoint

```typescript
// frontend/src/lib/echo.ts
export function reconnectEcho() {
    const echo = getEcho();
    echo.connector.pusher.connection.reconnect();
}
```

### Issue 5: Channel Authorization Fail

**Symptoms:** 403 Forbidden เมื่อ subscribe private channel

**Debug Steps:**
1. ตรวจสอบ `routes/channels.php`
   ```php
   Broadcast::channel('conversation.{id}', function ($user, $id) {
       return $user->canAccessConversation($id);
   });
   ```

2. ตรวจสอบ auth endpoint ใน Echo config
   ```typescript
   authEndpoint: `${API_URL}/broadcasting/auth`,
   ```

---

## Debug Commands

### Check Reverb Status (Railway)
```bash
# ดู logs ของ Reverb
railway logs --service reverb

# ดู connection count
railway shell --service reverb
php artisan reverb:connections
```

### Test Broadcast Locally
```bash
# Terminal 1: Start Reverb
cd backend && php artisan reverb:start --debug

# Terminal 2: Test broadcast
cd backend && php artisan tinker
>>> event(new App\Events\MessageSent($message));
```

### Debug Frontend Subscriptions
```typescript
// เพิ่มใน console
window.Echo.connector.pusher.connection.bind('state_change', (states) => {
    console.log('Echo state:', states.current);
});
```

---

## Event Payload Best Practices

```php
class MessageSent implements ShouldBroadcast
{
    public $afterCommit = true;

    public function __construct(
        public Message $message,
        public Conversation $conversation
    ) {
        // Capture data at dispatch time
        $this->conversation = $conversation->fresh();
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message->toArray(),
            'conversation_id' => $this->conversation->id,
            'unread_count' => $this->conversation->unread_count,
            'timestamp' => now()->toISOString(),
        ];
    }
}
```

---

## Checklist เมื่อ Debug WebSocket

- [ ] Channel name ตรงกันทั้ง frontend/backend?
- [ ] Event name มี dot prefix ใน frontend?
- [ ] `$afterCommit = true` ใน Event?
- [ ] Data ถูก fresh/refresh ก่อน broadcast?
- [ ] Channel authorization ถูกต้อง?
- [ ] Reverb server running?
- [ ] CORS config ถูกต้อง?

---

## Real Bugs จากโปรเจคนี้ (Git History)

### Bug 1: Message Duplication
**Commit:** `9331492` - add X-Socket-ID header to prevent message duplication

**Problem:** ส่ง message แล้วเห็น 2 ครั้ง (จาก API response + WebSocket)

**Solution:** ส่ง Socket ID ใน header เพื่อ exclude sender
```typescript
// lib/api.ts
api.interceptors.request.use((config) => {
    const socketId = window.Echo?.socketId();
    if (socketId) {
        config.headers['X-Socket-ID'] = socketId;
    }
    return config;
});
```

```php
// Laravel - exclude sender
broadcast(new MessageSent($message))->toOthers();
```

---

### Bug 2: Ping/Activity Timeout Mismatch
**Commit:** `fc23f38` - fix ping/activity timeout mismatch causing disconnects

**Problem:** WebSocket หลุดบ่อย

**Solution:** ตั้ง timeout ให้ตรงกัน
```typescript
// echo.ts
pusherOptions: {
    activityTimeout: 30000,
    pongTimeout: 10000,
}
```

---

### Bug 3: Conversation ไม่ขึ้นบนสุดเมื่อมี Message ใหม่
**Commit:** `4b05151` - move conversation to top when new message arrives

**Problem:** Message ใหม่มาแต่ conversation list ไม่ sort ใหม่

**Solution:** Re-sort list เมื่อได้ event
```typescript
onConversationUpdate: (event) => {
    queryClient.setQueryData(['conversations'], (old) => {
        // Move updated conversation to top
        const updated = old.filter(c => c.id !== event.id);
        return [event, ...updated];
    });
}
```

---

### Bug 4: Race Condition ใน handleNonTextMessage
**Commit:** `ea97b6d` - wrap handleNonTextMessage in transaction

**Problem:** Webhook process พร้อมกันหลาย request → data ผิด

**Solution:** ใช้ DB transaction
```php
DB::transaction(function () use ($event) {
    $this->handleNonTextMessage($event);
});
```

---

### Bug 5: needs_response ไม่ Sync
**Commit:** `600e066` - sync needs_response status via WebSocket events

**Problem:** Agent ตอบแล้วแต่ badge ยังแสดงว่าต้องตอบ

**Solution:** Broadcast needs_response change
```php
public function broadcastWith(): array
{
    return [
        'id' => $this->conversation->id,
        'needs_response' => $this->conversation->needs_response,
        'updated_at' => now()->toISOString(),
    ];
}
```
