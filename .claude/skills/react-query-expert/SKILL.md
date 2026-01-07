---
name: react-query-expert
description: "Debug React Query, TanStack Query, mutation, cache invalidation, refetch issues - ใช้เมื่อ UI ไม่ update หลัง mutation, toggle ไม่ทำงาน, data เก่า, stale data, optimistic update ผิด, infinite query, queryClient issues"
---

# React Query Expert

Debug และแก้ไขปัญหา TanStack Query (React Query) ใน BotFacebook

## Architecture Overview

```
Component                   React Query                 API
─────────                   ───────────                 ───
useConversations()    -->   queryKey: ['conversations'] --> GET /api/bots/{id}/conversations
useMutation()         -->   invalidateQueries()        --> POST/PUT/DELETE
```

## Key Files

| File | Purpose |
|------|---------|
| `frontend/src/hooks/useConversations.ts` | Conversation queries & mutations |
| `frontend/src/hooks/useAuth.ts` | Auth state management |
| `frontend/src/hooks/useFlows.ts` | Flow CRUD operations |
| `frontend/src/hooks/useKnowledgeBase.ts` | KB document management |
| `frontend/src/lib/api.ts` | Axios instance & interceptors |

---

## Common Issues & Solutions

### Issue 1: UI ไม่ Update หลัง Mutation

**Symptoms:** กด save/toggle แล้ว UI ไม่เปลี่ยน ต้อง refresh

**Root Cause:** Cache ไม่ถูก invalidate

**Solution 1: invalidateQueries**
```typescript
const mutation = useMutation({
    mutationFn: async (data) => {
        return api.put(`/bots/${botId}`, data);
    },
    onSuccess: () => {
        // Force refetch จาก server
        queryClient.invalidateQueries({ queryKey: ['bots'] });
    },
});
```

**Solution 2: refetchQueries (แนะนำ)**
```typescript
onSuccess: () => {
    // Refetch ทันที ไม่ต้องรอ stale
    queryClient.refetchQueries({ queryKey: ['bots', botId] });
},
```

**Solution 3: setQueryData (Optimistic)**
```typescript
onMutate: async (newData) => {
    await queryClient.cancelQueries({ queryKey: ['bots', botId] });
    const previous = queryClient.getQueryData(['bots', botId]);

    queryClient.setQueryData(['bots', botId], (old) => ({
        ...old,
        ...newData,
    }));

    return { previous };
},
onError: (err, newData, context) => {
    queryClient.setQueryData(['bots', botId], context?.previous);
},
```

### Issue 2: Toggle UI ไม่ตอบสนอง

**Symptoms:** กด toggle แล้วต้องรอ API ก่อน UI ถึงจะเปลี่ยน

**Solution: Local State + Mutation**
```typescript
const [localEnabled, setLocalEnabled] = useState(bot.is_enabled);

const toggleMutation = useMutation({
    mutationFn: () => api.patch(`/bots/${bot.id}/toggle`),
    onMutate: () => {
        // Update UI immediately
        setLocalEnabled((prev) => !prev);
    },
    onError: () => {
        // Rollback on error
        setLocalEnabled(bot.is_enabled);
    },
    onSettled: () => {
        // Sync with server
        queryClient.invalidateQueries({ queryKey: ['bots'] });
    },
});

// Sync when server data changes
useEffect(() => {
    setLocalEnabled(bot.is_enabled);
}, [bot.is_enabled]);
```

### Issue 3: Data เก่า / Stale Data

**Symptoms:** เห็น data เก่า แม้ server มี data ใหม่แล้ว

**Debug:**
```typescript
// ตรวจสอบ staleTime
useQuery({
    queryKey: ['conversations'],
    staleTime: 0,        // Consider stale immediately
    gcTime: 5 * 60 * 1000, // Cache for 5 minutes
});
```

**Solution:**
```typescript
// Force fresh data
useQuery({
    queryKey: ['conversations', botId],
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
});
```

### Issue 4: Infinite Query ไม่ Load More

**Symptoms:** กด load more แล้วไม่มีอะไรเกิดขึ้น

**Debug Checklist:**
1. `getNextPageParam` return ค่าถูกต้องไหม?
2. `hasNextPage` เป็น true ไหม?
3. API response มี pagination meta ไหม?

```typescript
useInfiniteQuery({
    queryKey: ['messages', conversationId],
    queryFn: ({ pageParam = 1 }) =>
        api.get(`/conversations/${conversationId}/messages?page=${pageParam}`),
    getNextPageParam: (lastPage) => {
        // ต้อง return undefined ถ้าไม่มี next page
        if (lastPage.meta.current_page >= lastPage.meta.last_page) {
            return undefined;
        }
        return lastPage.meta.current_page + 1;
    },
});
```

### Issue 5: Race Condition ระหว่าง Queries

**Symptoms:** Data แสดงผิดเพราะ query เก่า respond หลัง query ใหม่

**Solution: Use queryKey properly**
```typescript
// ❌ Wrong - ไม่มี dependency
useQuery({
    queryKey: ['conversations'],
    queryFn: () => fetchConversations(botId), // botId อาจเปลี่ยน
});

// ✅ Correct - มี dependency ใน queryKey
useQuery({
    queryKey: ['conversations', botId, filters],
    queryFn: () => fetchConversations(botId, filters),
    enabled: !!botId,
});
```

---

## Best Practices ใน BotFacebook

### Query Key Convention
```typescript
// List
['conversations', botId, filters]
['messages', conversationId, { page }]

// Single item
['conversation', conversationId]
['bot', botId]

// Nested
['bots', botId, 'flows']
['bots', botId, 'flows', flowId]
```

### Mutation Pattern
```typescript
const updateMutation = useMutation({
    mutationFn: (data: UpdateData) =>
        api.put(`/endpoint/${id}`, data),
    onSuccess: (response) => {
        // Option 1: Invalidate list
        queryClient.invalidateQueries({ queryKey: ['items'] });

        // Option 2: Update single item cache
        queryClient.setQueryData(['item', id], response.data);

        // Option 3: Both
        queryClient.invalidateQueries({ queryKey: ['items'] });
        queryClient.setQueryData(['item', id], response.data);
    },
    onError: (error) => {
        toast.error(error.response?.data?.message || 'Error');
    },
});
```

### Real-time + React Query
```typescript
// Invalidate cache when WebSocket event received
useBotChannel(botId, {
    onConversationUpdate: (event) => {
        // Invalidate specific conversation
        queryClient.invalidateQueries({
            queryKey: ['conversation', event.id],
        });

        // Invalidate conversation list
        queryClient.invalidateQueries({
            queryKey: ['conversations', botId],
        });
    },
});
```

---

## Debug Commands

### React Query DevTools
```typescript
// เพิ่มใน App.tsx (dev only)
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';

<QueryClientProvider client={queryClient}>
    <App />
    <ReactQueryDevtools initialIsOpen={false} />
</QueryClientProvider>
```

### Console Debug
```typescript
// ดู cache ทั้งหมด
console.log(queryClient.getQueryCache().getAll());

// ดู specific query
console.log(queryClient.getQueryData(['conversations', botId]));

// Force refetch
queryClient.refetchQueries({ queryKey: ['conversations'] });
```

---

## Checklist เมื่อ Debug React Query

- [ ] queryKey มี dependencies ครบไหม?
- [ ] enabled condition ถูกต้องไหม?
- [ ] onSuccess มี invalidateQueries ไหม?
- [ ] staleTime เหมาะสมไหม?
- [ ] API response format ตรงกับ type ไหม?
- [ ] Error handling มีไหม?

---

## Real Bugs จากโปรเจคนี้ (Git History)

### Bug 1: Bot Toggle ไม่ Update UI
**Commits:** `02e1826`, `0fcf096`, `919f25b`, `3629778`

**Problem:** กด toggle bot status แล้ว UI ไม่เปลี่ยน ต้อง refresh

**Solution ที่ลองแล้วไม่ work:**
```typescript
// ❌ invalidateQueries - ช้าเกินไป
onSuccess: () => {
    queryClient.invalidateQueries({ queryKey: ['bots'] });
}

// ❌ setQueryData - ไม่ sync กับ server
onMutate: () => {
    queryClient.setQueryData(['bots'], ...);
}
```

**Solution ที่ work:**
```typescript
// ✅ Local state + refetchQueries
const [localEnabled, setLocalEnabled] = useState(bot.is_enabled);

const mutation = useMutation({
    mutationFn: () => api.patch(`/bots/${bot.id}/toggle`),
    onMutate: () => setLocalEnabled(prev => !prev),  // UI update ทันที
    onError: () => setLocalEnabled(bot.is_enabled),   // Rollback
    onSettled: () => {
        queryClient.refetchQueries({ queryKey: ['bots'] });  // Sync
    },
});

useEffect(() => {
    setLocalEnabled(bot.is_enabled);  // Sync when server changes
}, [bot.is_enabled]);
```

---

### Bug 2: Chat Page ไม่ Refresh เมื่อ F5
**Commit:** `d38814d` - fix chat page not refreshing on F5 or menu switch

**Problem:** กด F5 หรือ switch menu แล้ว data ไม่โหลดใหม่

**Solution:**
```typescript
useQuery({
    queryKey: ['conversations', botId],
    refetchOnMount: 'always',      // Refetch ทุกครั้งที่ mount
    refetchOnWindowFocus: true,    // Refetch เมื่อ focus window
    staleTime: 0,                  // Consider stale immediately
});
```

---

### Bug 3: Race Condition ใน useMarkAsRead
**Commit:** `df8b187` - add onSuccess to useMarkAsRead to fix race condition

**Problem:** Mark as read แล้วแต่ unread count ยังไม่ update

**Solution:**
```typescript
const markAsRead = useMutation({
    mutationFn: (id) => api.post(`/conversations/${id}/read`),
    onSuccess: () => {
        // ต้อง invalidate ทั้ง conversation และ list
        queryClient.invalidateQueries({
            queryKey: ['conversation', conversationId]
        });
        queryClient.invalidateQueries({
            queryKey: ['conversations', botId]
        });
    },
});
```

---

### Bug 4: Fallback Polling เมื่อ WebSocket หลุด
**Commit:** `1699fd3` - add fallback polling when WebSocket disconnected

**Problem:** WebSocket หลุดแล้ว data ไม่ update

**Solution:**
```typescript
const isConnected = useConnectionStore((state) => state.isConnected);

useQuery({
    queryKey: ['conversations', botId],
    // Poll ทุก 10s เมื่อ WebSocket หลุด
    refetchInterval: isConnected ? false : 10000,
});
```

---

### Bug 5: Message หายเมื่อส่ง
**Commit:** `d4c84bb` - prevent message disappearing with WebSocket-first pattern

**Problem:** ส่ง message แล้ว message หายไปชั่วขณะ

**Root Cause:** Optimistic update ถูก overwrite โดย query refetch

**Solution:** WebSocket-first pattern
```typescript
// ไม่ต้อง optimistic update สำหรับ messages
// รอ WebSocket event มาแล้วค่อย update
useBotChannel(botId, {
    onMessage: (event) => {
        queryClient.setQueryData(['messages', event.conversation_id],
            (old) => [...old, event.message]
        );
    },
});
```
