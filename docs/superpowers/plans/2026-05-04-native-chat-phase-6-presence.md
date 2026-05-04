# Native Chat — Phase 6: Multi-Agent Presence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans.

**Goal:** ให้ agent หลายคนรู้ว่าใครกำลังดูเคสไหน + soft warning เมื่อเปิดเคสเดียวกัน (ไม่ใช่ hard lock)

**Architecture:** Activate existing `useBotPresence` hook (มี API อยู่แล้ว) + เพิ่ม whisper event `viewing` + สร้าง UI component แสดง viewers + toast warning

**Tech Stack:** React 19, Pusher.js Presence Channel, Laravel Echo, Zustand

**Spec reference:** `docs/superpowers/specs/2026-05-03-native-chat-design.md` Section 5 Phase 6

**Depends on:** Phase 1 merged ✅, Phase 2 recommended

---

## Pre-Flight

- [ ] Create branch: `feat/chat-multi-agent-presence`
- [ ] Verify presence channel auth works: `backend/routes/channels.php` has `bot.{botId}.presence` channel

## File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `frontend/src/hooks/chat/useConversationViewers.ts` | **create** | Track which agents view which conversation via whisper events |
| `frontend/src/components/chat/AgentPresenceBadge.tsx` | **create** | Show avatar/count of other agents on conversation |
| `frontend/src/pages/ChatPage.tsx` | modify | Activate useBotPresence + wire viewers |
| `frontend/src/components/chat/ConversationItem.tsx` | modify | Show viewer badge per conversation |
| `frontend/src/components/chat/ChatWindow.tsx` | modify | Whisper `viewing` event when conversation opened |

---

## Task 1: useConversationViewers Hook

**Files:**
- Create: `frontend/src/hooks/chat/useConversationViewers.ts`

- [ ] **Step 1.1: Create hook**

```typescript
import { create } from 'zustand';

interface ViewerState {
  viewers: Record<number, { id: number; name: string }[]>; // conversationId → agents
  setViewers: (conversationId: number, agents: { id: number; name: string }[]) => void;
  addViewer: (conversationId: number, agent: { id: number; name: string }) => void;
  removeViewer: (conversationId: number, agentId: number) => void;
  clearAll: () => void;
}

export const useConversationViewers = create<ViewerState>((set) => ({
  viewers: {},
  setViewers: (conversationId, agents) =>
    set((state) => ({ viewers: { ...state.viewers, [conversationId]: agents } })),
  addViewer: (conversationId, agent) =>
    set((state) => {
      const current = state.viewers[conversationId] || [];
      if (current.some((a) => a.id === agent.id)) return state;
      return { viewers: { ...state.viewers, [conversationId]: [...current, agent] } };
    }),
  removeViewer: (conversationId, agentId) =>
    set((state) => {
      const current = state.viewers[conversationId] || [];
      return { viewers: { ...state.viewers, [conversationId]: current.filter((a) => a.id !== agentId) } };
    }),
  clearAll: () => set({ viewers: {} }),
}));
```

- [ ] **Step 1.2: Verify tsc**
- [ ] **Step 1.3: Commit:** `feat(chat): add conversation viewers zustand store`

## Task 2: AgentPresenceBadge Component

**Files:**
- Create: `frontend/src/components/chat/AgentPresenceBadge.tsx`

- [ ] **Step 2.1: Create component**

```tsx
import { useConversationViewers } from '@/hooks/chat/useConversationViewers';
import { useAuthStore } from '@/stores/authStore';

interface AgentPresenceBadgeProps {
  conversationId: number;
}

export function AgentPresenceBadge({ conversationId }: AgentPresenceBadgeProps) {
  const viewers = useConversationViewers((s) => s.viewers[conversationId] || []);
  const currentUserId = useAuthStore((s) => s.user?.id);

  // Filter out self
  const otherViewers = viewers.filter((v) => v.id !== currentUserId);
  if (otherViewers.length === 0) return null;

  return (
    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
      <span className="h-1.5 w-1.5 rounded-full bg-blue-400" />
      {otherViewers.length === 1
        ? otherViewers[0].name
        : `${otherViewers.length} agents`}
    </span>
  );
}
```

- [ ] **Step 2.2: Commit:** `feat(chat): add agent presence badge component`

## Task 3: Wire Presence into ChatPage + ChatWindow

**Files:**
- Modify: `frontend/src/pages/ChatPage.tsx`
- Modify: `frontend/src/components/chat/ChatWindow.tsx`
- Modify: `frontend/src/components/chat/ConversationItem.tsx`

- [ ] **Step 3.1:** In ChatPage, activate `useBotPresence(botId, { onHere, onJoining, onLeaving })`:
  - `onHere`: set initial members
  - `onJoining`: add member
  - `onLeaving`: remove member
  - Listen for whisper `viewing` events → update `useConversationViewers`

- [ ] **Step 3.2:** In ChatWindow, whisper `viewing` event when conversation opens:
```typescript
useEffect(() => {
  const echo = getEcho();
  const channel = echo.join(`bot.${botId}.presence`);
  channel.whisper('viewing', { conversationId: conversation.id });
  return () => channel.whisper('left', { conversationId: conversation.id });
}, [botId, conversation.id]);
```

- [ ] **Step 3.3:** In ConversationItem, show `<AgentPresenceBadge conversationId={conversation.id} />`

- [ ] **Step 3.4:** Add toast when opening a conversation that other agents view:
```typescript
if (otherViewers.length > 0) {
  toast({ title: `${otherViewers[0].name} กำลังดูเคสนี้อยู่` });
}
```

- [ ] **Step 3.5: Verify tsc + tests**
- [ ] **Step 3.6: Commit:**
```
feat(chat): wire multi-agent presence with whisper events

Agents see who else is viewing each conversation. Soft toast warning
when opening a conversation another agent is viewing. Uses Pusher
presence channel whisper events (no backend changes needed).
```

## Task 4: /simplify + Push + PR

- [ ] Run `/simplify`
- [ ] Push: `feat/chat-multi-agent-presence`
- [ ] PR: `feat(chat): multi-agent presence indicators`

## Definition of Done
- [ ] 2 agents open same conversation → both see each other
- [ ] Toast fires when opening conversation another agent views
- [ ] Badge shows in conversation list
- [ ] No backend changes needed (uses existing presence channel)
