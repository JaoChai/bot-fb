# Chat Feature Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor chat feature ทั้ง backend และ frontend เพื่อลด complexity โดยไม่เปลี่ยน behavior

**Architecture:** Extract Method / Extract Component — แยก methods/components ที่ใหญ่เกินไปออกเป็นส่วนย่อย ยึดหลัก single responsibility ทุก task ต้อง build pass ก่อน-หลัง refactor

**Tech Stack:** Laravel 12 (PHP 8.4), React 19, TypeScript, TanStack React Query v5, Zustand, Tailwind v4

**Important:** ก่อน commit ทุกครั้ง ต้องรัน `/simplify` เพื่อ review code quality

---

## File Structure Overview

### Files to Modify

| File | Current Lines | Target | Change |
|------|--------------|--------|--------|
| `backend/app/Jobs/ProcessAggregatedMessages.php` | 360 | ~360 | Extract 4 private methods from 239-line method, clean debug logs |
| `frontend/src/hooks/chat/useRealtime.ts` | 368 | ~250 | Extract `updateConversationInList` + `createMessageFromEvent` to utility |
| `frontend/src/components/conversation/NotesPanel.tsx` | 357 | ~180 | Extract `<NoteForm>` component |
| `frontend/src/pages/ChatPage.tsx` | 317 | ~260 | Extract `<BotSelectorPanel>` component |
| `frontend/src/components/conversation/TagsPanel.tsx` | 270 | ~170 | Extract `<TagAutocomplete>` component |

### Files to Create

| File | Purpose |
|------|---------|
| `frontend/src/hooks/chat/realtimeUtils.ts` | Shared helpers: `updateConversationInList`, `createMessageFromEvent` |
| `frontend/src/components/conversation/NoteForm.tsx` | Add/Edit form for notes |
| `frontend/src/components/chat/BotSelectorPanel.tsx` | Bot picker + clear context button |
| `frontend/src/components/conversation/TagAutocomplete.tsx` | Tag input with autocomplete dropdown |

---

## Task 1: Extract Methods from ProcessAggregatedMessages (HIGH)

**Files:**
- Modify: `backend/app/Jobs/ProcessAggregatedMessages.php`

Main method `processAggregatedMessages()` มี 239 บรรทัด ทำ 6 หน้าที่ — extract เป็น private methods โดยไม่เปลี่ยน behavior

- [ ] **Step 1: Verify tests pass before refactoring**

```bash
cd backend && php artisan test
```

Expected: All tests pass

- [ ] **Step 2: Clean up debug error_log calls**

Replace all `error_log("[AGGREGATION_DEBUG]...")` calls with proper `Log::debug()`:

```php
// BEFORE (6 occurrences):
error_log("[AGGREGATION_DEBUG] Job started: {$debugData}");
error_log("[AGGREGATION_DEBUG] Early exit: group_id mismatch...");
error_log("[AGGREGATION_DEBUG] Early exit: no content...");
error_log("[AGGREGATION_DEBUG] Early exit: bot inactive...");
error_log("[AGGREGATION_DEBUG] Early exit: handover mode...");
error_log("[AGGREGATION_DEBUG] Generating AI response...");

// AFTER:
Log::debug('[Aggregation] Job started', [...]);
Log::debug('[Aggregation] Early exit: group_id mismatch', [...]);
// etc.
```

Keep the same data but use structured logging instead of `error_log` + `json_encode`.

- [ ] **Step 3: Extract `validateAndGetContent()` method**

Extract lines 91-141 (validation + content retrieval) into a private method:

```php
/**
 * Validate group is still active and get merged content.
 * Returns [mergedContent, messageCount] or null if should skip.
 *
 * @return array{string, int}|null
 */
private function validateAndGetContent(MessageAggregationService $aggregationService): ?array
{
    $conversationId = $this->conversation->id;

    $cachedGroupId = $aggregationService->getCurrentGroupId($conversationId);
    $cachedMessageIds = $aggregationService->getMessageIds($conversationId);
    $startedAt = $aggregationService->getStartedAt($conversationId);

    Log::debug('[Aggregation] Job started', [
        'conversation_id' => $conversationId,
        'job_group_id' => $this->groupId,
        'cached_group_id' => $cachedGroupId,
        'group_id_match' => $cachedGroupId === $this->groupId,
        'message_count' => count($cachedMessageIds),
        'started_at' => $startedAt,
        'bot_id' => $this->bot->id,
    ]);

    if (! $aggregationService->isActiveGroup($conversationId, $this->groupId)) {
        $reason = $cachedGroupId === null ? 'cache_expired_or_missing' : 'newer_group_exists';
        Log::debug('[Aggregation] Early exit: group_id mismatch', [
            'reason' => $reason,
            'job_group_id' => $this->groupId,
            'cached_group_id' => $cachedGroupId,
        ]);

        return null;
    }

    $mergedContent = $aggregationService->getMergedContent($conversationId);

    if (empty($mergedContent)) {
        $reason = empty($cachedMessageIds) ? 'message_ids_empty' : 'messages_not_found_in_db';
        Log::debug('[Aggregation] Early exit: no content', [
            'reason' => $reason,
            'message_ids' => $cachedMessageIds,
        ]);
        $aggregationService->clearAggregation($conversationId);

        return null;
    }

    $messageCount = count($cachedMessageIds);

    Log::info('Processing aggregated messages', [
        'conversation_id' => $conversationId,
        'group_id' => $this->groupId,
        'message_count' => $messageCount,
        'merged_length' => strlen($mergedContent),
    ]);

    return [$mergedContent, $messageCount, $cachedMessageIds];
}
```

- [ ] **Step 4: Extract `shouldGenerate()` method**

Extract lines 146-172 (fast validation transaction):

```php
/**
 * Quick DB check: is bot active and not in handover?
 */
private function shouldGenerate(): bool
{
    $shouldGenerate = false;

    DB::transaction(function () use (&$shouldGenerate) {
        $this->conversation->refresh();
        $this->bot->refresh();

        if ($this->bot->status !== 'active') {
            Log::debug('[Aggregation] Early exit: bot inactive', [
                'bot_id' => $this->bot->id,
                'status' => $this->bot->status,
            ]);

            return;
        }

        if ($this->conversation->is_handover) {
            Log::debug('[Aggregation] Early exit: handover mode', [
                'conversation_id' => $this->conversation->id,
            ]);

            return;
        }

        $shouldGenerate = true;
    });

    return $shouldGenerate;
}
```

- [ ] **Step 5: Extract `acquireResponseLock()` method**

Extract lines 204-239 (lock acquisition + re-dispatch logic):

```php
/**
 * Acquire per-conversation response lock.
 * Returns the lock if acquired, or null if re-dispatched/abandoned.
 */
private function acquireResponseLock(MessageAggregationService $aggregationService): ?\Illuminate\Contracts\Cache\Lock
{
    $conversationId = $this->conversation->id;
    $responseLock = Cache::lock("ai_response:{$conversationId}", 30);

    if ($responseLock->get()) {
        Cache::forget("ai_response_redispatch:{$conversationId}:{$this->groupId}");

        return $responseLock;
    }

    $redispatchKey = "ai_response_redispatch:{$conversationId}:{$this->groupId}";
    $attempts = (int) Cache::get($redispatchKey, 0);

    if ($attempts >= 3) {
        Log::warning('Aggregation: max re-dispatch attempts reached', [
            'conversation_id' => $conversationId,
            'group_id' => $this->groupId,
            'attempts' => $attempts,
        ]);
        Cache::forget($redispatchKey);
        $aggregationService->clearAggregation($conversationId);

        return null;
    }

    Cache::put($redispatchKey, $attempts + 1, now()->addMinutes(5));

    Log::info('Aggregation: response lock held, re-dispatching', [
        'conversation_id' => $conversationId,
        'attempt' => $attempts + 1,
    ]);

    ProcessAggregatedMessages::dispatch(
        $this->bot, $this->conversation, $this->groupId, $this->externalUserId
    )->onQueue('webhooks')->delay(now()->addSeconds(5));

    return null;
}
```

- [ ] **Step 6: Extract `generateAndDeliver()` method**

Extract lines 244-298 (AI generation + channel delivery + plugins):

```php
/**
 * Generate AI response and deliver to channel.
 */
private function generateAndDeliver(
    string $mergedContent,
    int $messageCount,
    AIService $aiService,
    LINEService $lineService,
    MultipleBubblesService $bubblesService
): ?\App\Models\Message {
    Log::debug('[Aggregation] Generating AI response', [
        'conversation_id' => $this->conversation->id,
        'content_length' => strlen($mergedContent),
    ]);

    $result = $aiService->generateResponse(
        $this->bot,
        $mergedContent,
        $this->conversation
    );

    $botMessage = $this->conversation->messages()->create([
        'sender' => 'bot',
        'content' => $result['content'],
        'type' => 'text',
        'model_used' => $result['model'],
        'prompt_tokens' => $result['usage']['prompt_tokens'],
        'completion_tokens' => $result['usage']['completion_tokens'],
        'cost' => $result['cost'],
        'metadata' => $result['rag_metadata'] ?? null,
    ]);

    if ($botMessage->content) {
        $this->deliverToChannel($botMessage, $lineService, $bubblesService);
    }

    if ($botMessage) {
        try {
            app(\App\Services\FlowPluginService::class)
                ->executePlugins($this->bot, $this->conversation, $botMessage);
        } catch (\Exception $e) {
            Log::warning('Flow plugin execution failed in aggregation', [
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    $this->updateStats($messageCount, $botMessage->id);

    return $botMessage;
}

/**
 * Deliver bot message to the appropriate channel.
 */
private function deliverToChannel(
    \App\Models\Message $botMessage,
    LINEService $lineService,
    MultipleBubblesService $bubblesService
): void {
    $paymentFlex = app(\App\Services\PaymentFlexService::class);
    $transformed = $paymentFlex->tryConvertToFlex($botMessage->content, $this->conversation);

    if (is_array($transformed)) {
        $retryKey = $lineService->generateRetryKey();
        $lineService->push($this->bot, $this->externalUserId, [$transformed], $retryKey);
    } elseif ($bubblesService->isEnabled($this->bot)) {
        $bubbles = $bubblesService->parseIntoBubbles($botMessage->content, $this->bot);
        $bubblesService->sendBubbles($this->bot, $this->externalUserId, null, $bubbles, $this->conversation);
    } else {
        $retryKey = $lineService->generateRetryKey();
        $lineService->push($this->bot, $this->externalUserId, [$botMessage->content], $retryKey);
    }
}
```

- [ ] **Step 7: Rewrite `processAggregatedMessages()` to use extracted methods**

The main method becomes a simple orchestrator:

```php
protected function processAggregatedMessages(
    MessageAggregationService $aggregationService,
    AIService $aiService,
    LINEService $lineService,
    MultipleBubblesService $bubblesService
): void {
    $conversationId = $this->conversation->id;

    // Step 1: Validate group and get content
    $result = $this->validateAndGetContent($aggregationService);
    if ($result === null) {
        return;
    }
    [$mergedContent, $messageCount, $cachedMessageIds] = $result;

    $botMessage = null;

    // Step 2: Check if bot should generate a response
    if ($this->shouldGenerate()) {
        // Safety check: skip if bot already responded
        if ($this->hasAlreadyResponded($conversationId, $cachedMessageIds)) {
            $aggregationService->clearAggregation($conversationId);
            return;
        }

        // Step 3: Acquire response lock
        $responseLock = $this->acquireResponseLock($aggregationService);
        if ($responseLock === null) {
            return;
        }

        app(ConversationContextService::class)->autoClearIfIdle($this->conversation);

        try {
            // Step 4: Generate response and deliver
            $botMessage = $this->generateAndDeliver(
                $mergedContent, $messageCount, $aiService, $lineService, $bubblesService
            );
        } finally {
            $responseLock->release();
        }
    }

    // Step 5: Cleanup and broadcast
    $aggregationService->clearAggregation($conversationId);

    if ($botMessage) {
        $this->broadcastResponse($botMessage);
    }
}
```

Also add the two small helpers:

```php
private function hasAlreadyResponded(int $conversationId, array $cachedMessageIds): bool
{
    if (empty($cachedMessageIds)) {
        return false;
    }

    $latestMessageId = max($cachedMessageIds);
    $latestMessage = \App\Models\Message::find($latestMessageId);

    if (! $latestMessage) {
        return false;
    }

    $alreadyResponded = \App\Models\Message::where('conversation_id', $conversationId)
        ->where('sender', 'bot')
        ->where('created_at', '>=', $latestMessage->created_at)
        ->exists();

    if ($alreadyResponded) {
        Log::info('Safety net: bot already responded after latest message', [
            'conversation_id' => $conversationId,
            'group_id' => $this->groupId,
            'latest_message_id' => $latestMessageId,
        ]);
    }

    return $alreadyResponded;
}

private function broadcastResponse(\App\Models\Message $botMessage): void
{
    $this->conversation->refresh();
    $conversationData = [
        'id' => $this->conversation->id,
        'message_count' => $this->conversation->message_count,
        'last_message_at' => $this->conversation->last_message_at?->toISOString(),
        'unread_count' => $this->conversation->unread_count,
    ];
    broadcast(new MessageSent($botMessage, $conversationData))->toOthers();
    broadcast(new ConversationUpdated($this->conversation, 'message_received'))->toOthers();
}
```

- [ ] **Step 8: Verify tests pass after refactoring**

```bash
cd backend && php artisan test
vendor/bin/pint --test
```

Expected: All tests pass, code style clean

- [ ] **Step 9: Run /simplify then commit**

```bash
# After /simplify review
git add backend/app/Jobs/ProcessAggregatedMessages.php
git commit -m "refactor(jobs): extract methods from ProcessAggregatedMessages

- Extract validateAndGetContent(), shouldGenerate(), acquireResponseLock(),
  generateAndDeliver(), deliverToChannel(), hasAlreadyResponded(), broadcastResponse()
- Replace error_log() debug calls with structured Log::debug()
- Main method reduced from ~240 lines to ~35 lines orchestrator
- No behavior changes"
```

---

## Task 2: Extract NoteForm from NotesPanel (HIGH)

**Files:**
- Create: `frontend/src/components/conversation/NoteForm.tsx`
- Modify: `frontend/src/components/conversation/NotesPanel.tsx`

NotesPanel มี 357 บรรทัด จัดการ 6 state variables — add form และ edit form มี UI ซ้ำกัน (type selector + textarea + action buttons) extract เป็น shared `<NoteForm>` component

- [ ] **Step 1: Verify frontend builds before refactoring**

```bash
cd frontend && npx tsc --noEmit && npm run build
```

Expected: No errors

- [ ] **Step 2: Create NoteForm component**

```tsx
// frontend/src/components/conversation/NoteForm.tsx
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Loader2, StickyNote, Brain, Bell, Save, X } from 'lucide-react';

type NoteType = 'note' | 'memory' | 'reminder';

interface NoteFormProps {
  initialContent?: string;
  initialType?: NoteType;
  onSave: (content: string, type: NoteType) => Promise<void>;
  onCancel: () => void;
  isPending: boolean;
}

export function NoteForm({
  initialContent = '',
  initialType = 'note',
  onSave,
  onCancel,
  isPending,
}: NoteFormProps) {
  const [content, setContent] = useState(initialContent);
  const [type, setType] = useState<NoteType>(initialType);

  const handleSave = async () => {
    if (!content.trim()) return;
    await onSave(content.trim(), type);
  };

  return (
    <div className="space-y-3">
      <Select value={type} onValueChange={(v) => setType(v as NoteType)}>
        <SelectTrigger className="w-32">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="note">
            <div className="flex items-center gap-2">
              <StickyNote className="h-4 w-4" />
              Note
            </div>
          </SelectItem>
          <SelectItem value="memory">
            <div className="flex items-center gap-2">
              <Brain className="h-4 w-4" />
              Memory
            </div>
          </SelectItem>
          <SelectItem value="reminder">
            <div className="flex items-center gap-2">
              <Bell className="h-4 w-4" />
              Reminder
            </div>
          </SelectItem>
        </SelectContent>
      </Select>
      <Textarea
        placeholder="Write your note here..."
        value={content}
        onChange={(e) => setContent(e.target.value)}
        rows={3}
        className="resize-none"
      />
      <div className="flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={onCancel}>
          <X className="h-4 w-4 mr-1" />
          Cancel
        </Button>
        <Button
          size="sm"
          onClick={handleSave}
          disabled={!content.trim() || isPending}
        >
          {isPending && <Loader2 className="h-4 w-4 mr-1 animate-spin" />}
          <Save className="h-4 w-4 mr-1" />
          Save
        </Button>
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Refactor NotesPanel to use NoteForm**

Replace the inline add form (lines 171-233) and edit form (lines 248-285) with `<NoteForm>`.

Remove state variables: `newContent`, `newType`, `editContent`, `editType` (4 of 6 eliminated).

Keep only: `isAdding`, `editingId`.

```tsx
// Simplified NotesPanel.tsx — key changes:

// Add form usage:
{isAdding && (
  <div className="p-3 border rounded-lg bg-muted/50">
    <NoteForm
      onSave={async (content, type) => {
        await addNote.mutateAsync({
          conversationId,
          data: { content, type },
        });
        toast({ title: 'Note added', description: 'Your note has been saved.' });
        setIsAdding(false);
      }}
      onCancel={() => setIsAdding(false)}
      isPending={addNote.isPending}
    />
  </div>
)}

// Edit form usage (inside map):
{isEditing ? (
  <NoteForm
    initialContent={note.content}
    initialType={note.type}
    onSave={async (content, type) => {
      await updateNote.mutateAsync({
        conversationId,
        noteId: note.id,
        data: { content, type },
      });
      toast({ title: 'Note updated', description: 'Your changes have been saved.' });
      setEditingId(null);
    }}
    onCancel={() => setEditingId(null)}
    isPending={updateNote.isPending}
  />
) : (
  // existing display code...
)}
```

Remove: `handleAddNote`, `handleUpdateNote`, `startEditing`, `cancelEditing` functions, and `newContent`, `newType`, `editContent`, `editType` state. Add error handling to the inline `onSave` callbacks.

- [ ] **Step 4: Verify frontend builds**

```bash
cd frontend && npx tsc --noEmit && npm run build
```

Expected: No errors

- [ ] **Step 5: Run /simplify then commit**

```bash
git add frontend/src/components/conversation/NoteForm.tsx frontend/src/components/conversation/NotesPanel.tsx
git commit -m "refactor(notes): extract NoteForm from NotesPanel

- Create shared NoteForm component for add/edit modes
- NotesPanel reduced from 357 to ~180 lines
- Eliminated 4 of 6 useState variables
- No behavior changes"
```

---

## Task 3: Extract Realtime Utilities from useRealtime (HIGH)

**Files:**
- Create: `frontend/src/hooks/chat/realtimeUtils.ts`
- Modify: `frontend/src/hooks/chat/useRealtime.ts`

`useRealtime.ts` มี 368 บรรทัด — extract `updateConversationInList()` helper (76 บรรทัด) และ `createMessageFromEvent()` factory ออกเป็น utility file

- [ ] **Step 1: Verify frontend builds**

```bash
cd frontend && npx tsc --noEmit && npm run build
```

- [ ] **Step 2: Create realtimeUtils.ts**

Extract the standalone `updateConversationInList` function (lines 292-368) and create a `createMessageFromEvent` helper to eliminate the inline Message object construction (lines 77-96):

```tsx
// frontend/src/hooks/chat/realtimeUtils.ts
import type { QueryClient, InfiniteData } from '@tanstack/react-query';
import { conversationKeys, type ConversationsResponse } from './useConversationList';
import type { Message, Conversation, ConversationFilters } from '@/types/api';
import type { MessageSentEvent } from '@/types/realtime';

export function createMessageFromEvent(event: MessageSentEvent): Message {
  return {
    id: event.id,
    conversation_id: event.conversation_id,
    sender: event.sender,
    content: event.content,
    type: event.type,
    media_url: event.media_url,
    media_type: event.media_type,
    media_metadata: null,
    model_used: null,
    prompt_tokens: null,
    completion_tokens: null,
    cost: null,
    external_message_id: null,
    reply_to_message_id: null,
    sentiment: null,
    intents: null,
    created_at: event.created_at,
    updated_at: event.created_at,
  };
}

export function updateConversationInList(
  queryClient: QueryClient,
  botId: number,
  filters: ConversationFilters,
  conversationId: number,
  selectedConversationId: number | null | undefined,
  event: MessageSentEvent
) {
  queryClient.setQueryData<InfiniteData<ConversationsResponse>>(
    conversationKeys.infinite(botId, filters),
    (old) => {
      if (!old) return old;

      const nowNeedsResponse = event.sender === 'user';

      let targetPageIdx = -1;
      let targetItemIdx = -1;
      for (let p = 0; p < old.pages.length; p++) {
        const idx = old.pages[p].data.findIndex((c) => c.id === conversationId);
        if (idx !== -1) {
          targetPageIdx = p;
          targetItemIdx = idx;
          break;
        }
      }

      if (targetPageIdx === -1) return old;

      const existingConv = old.pages[targetPageIdx].data[targetItemIdx];
      const updatedConv: Conversation = {
        ...existingConv,
        last_message_at: event.conversation?.last_message_at ?? event.created_at,
        message_count: event.conversation?.message_count ?? existingConv.message_count + 1,
        unread_count:
          existingConv.id === selectedConversationId
            ? 0
            : (event.conversation?.unread_count ?? existingConv.unread_count + 1),
        needs_response: nowNeedsResponse,
        last_message: createMessageFromEvent(event),
      };

      const newPages = old.pages.map((page, i) => {
        const filteredData = i === targetPageIdx
          ? page.data.filter((_, j) => j !== targetItemIdx)
          : page.data;

        if (i === 0) {
          return { ...page, data: [updatedConv, ...filteredData] };
        }
        return { ...page, data: filteredData };
      });

      return { ...old, pages: newPages };
    }
  );
}
```

- [ ] **Step 3: Update useRealtime.ts to import from realtimeUtils**

Replace inline `updateConversationInList` function and Message object creation:

```tsx
// At top of useRealtime.ts:
import { updateConversationInList, createMessageFromEvent } from './realtimeUtils';

// In handleRealtimeMessage, replace lines 77-96 with:
const newMessage = createMessageFromEvent(event);

// Remove the standalone updateConversationInList function at bottom of file
```

- [ ] **Step 4: Update index.ts exports**

```tsx
// frontend/src/hooks/chat/index.ts — add:
export { updateConversationInList, createMessageFromEvent } from './realtimeUtils';
```

- [ ] **Step 5: Verify frontend builds**

```bash
cd frontend && npx tsc --noEmit && npm run build
```

- [ ] **Step 6: Run /simplify then commit**

```bash
git add frontend/src/hooks/chat/realtimeUtils.ts frontend/src/hooks/chat/useRealtime.ts frontend/src/hooks/chat/index.ts
git commit -m "refactor(realtime): extract utilities from useRealtime

- Move updateConversationInList to realtimeUtils.ts
- Create createMessageFromEvent helper (eliminates inline Message construction)
- useRealtime.ts reduced from 368 to ~250 lines
- No behavior changes"
```

---

## Task 4: Extract BotSelectorPanel from ChatPage (MODERATE)

**Files:**
- Create: `frontend/src/components/chat/BotSelectorPanel.tsx`
- Modify: `frontend/src/pages/ChatPage.tsx`

ChatPage มี 317 บรรทัด — Bot Selector + Clear Context dialog (lines 206-247) เป็น self-contained UI block ที่ extract ได้

- [ ] **Step 1: Verify frontend builds**

```bash
cd frontend && npx tsc --noEmit && npm run build
```

- [ ] **Step 2: Create BotSelectorPanel component**

```tsx
// frontend/src/components/chat/BotSelectorPanel.tsx
import { BotPicker } from '@/components/common';
import { Button } from '@/components/ui/button';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Loader2, RotateCcw } from 'lucide-react';

interface BotSelectorPanelProps {
  bots: Array<{ id: number; name: string }>;
  botId: number;
  onBotSelect: (value: string) => void;
  onClearContextAll: () => void;
  isClearPending: boolean;
}

export function BotSelectorPanel({
  bots,
  botId,
  onBotSelect,
  onClearContextAll,
  isClearPending,
}: BotSelectorPanelProps) {
  return (
    <div className="p-3 border-b bg-muted/30">
      <BotPicker
        bots={bots}
        value={botId}
        onChange={onBotSelect}
        showIcon={false}
      />

      <AlertDialog>
        <AlertDialogTrigger asChild>
          <Button
            variant="outline"
            size="sm"
            className="w-full mt-2"
            disabled={isClearPending || !botId}
          >
            {isClearPending ? (
              <Loader2 className="h-4 w-4 mr-2 animate-spin" />
            ) : (
              <RotateCcw className="h-4 w-4 mr-2" strokeWidth={1.5} />
            )}
            Reset All Contexts
          </Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Reset all contexts?</AlertDialogTitle>
            <AlertDialogDescription>
              Bot will start fresh with all open conversations.
              Chat history will be preserved but bot will not reference previous messages.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={onClearContextAll}>
              Reset All
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
```

- [ ] **Step 3: Update ChatPage to use BotSelectorPanel**

Replace lines 206-247 in ChatPage with:

```tsx
import { BotSelectorPanel } from '@/components/chat/BotSelectorPanel';

// In JSX (inside left panel div):
<BotSelectorPanel
  bots={bots.map((b) => ({ id: b.id, name: b.name }))}
  botId={botId}
  onBotSelect={handleBotSelect}
  onClearContextAll={handleClearContextAll}
  isClearPending={clearContextAll.isPending}
/>
```

Remove AlertDialog imports from ChatPage that are no longer needed.

- [ ] **Step 4: Verify frontend builds**

```bash
cd frontend && npx tsc --noEmit && npm run build
```

- [ ] **Step 5: Run /simplify then commit**

```bash
git add frontend/src/components/chat/BotSelectorPanel.tsx frontend/src/pages/ChatPage.tsx
git commit -m "refactor(chat): extract BotSelectorPanel from ChatPage

- Move bot picker + clear context dialog to dedicated component
- ChatPage reduced from 317 to ~260 lines
- Removed unused AlertDialog imports from ChatPage
- No behavior changes"
```

---

## Task 5: Extract TagAutocomplete from TagsPanel (MODERATE)

**Files:**
- Create: `frontend/src/components/conversation/TagAutocomplete.tsx`
- Modify: `frontend/src/components/conversation/TagsPanel.tsx`

TagsPanel มี 270 บรรทัด — autocomplete dropdown (lines 161-242) มี logic เรื่อง click outside, suggestions filter, keyboard handling ที่ควรแยก

- [ ] **Step 1: Verify frontend builds**

```bash
cd frontend && npx tsc --noEmit && npm run build
```

- [ ] **Step 2: Create TagAutocomplete component**

```tsx
// frontend/src/components/conversation/TagAutocomplete.tsx
import { useState, useRef, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Loader2, Plus, X, Tag, Check } from 'lucide-react';
import { cn } from '@/lib/utils';

interface TagAutocompleteProps {
  allTags: string[];
  currentTags: string[];
  onAddTag: (tag: string) => Promise<void>;
  onClose: () => void;
  isPending: boolean;
}

export function TagAutocomplete({
  allTags,
  currentTags,
  onAddTag,
  onClose,
  isPending,
}: TagAutocompleteProps) {
  const [inputValue, setInputValue] = useState('');
  const [showSuggestions, setShowSuggestions] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const suggestionsRef = useRef<HTMLDivElement>(null);

  const suggestions = allTags.filter(
    (tag) =>
      tag.toLowerCase().includes(inputValue.toLowerCase()) &&
      !currentTags.includes(tag)
  );

  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (
        suggestionsRef.current &&
        !suggestionsRef.current.contains(event.target as Node) &&
        inputRef.current &&
        !inputRef.current.contains(event.target as Node)
      ) {
        setShowSuggestions(false);
      }
    }

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleAdd = async (tag: string) => {
    const trimmedTag = tag.trim();
    if (!trimmedTag || currentTags.includes(trimmedTag)) return;
    await onAddTag(trimmedTag);
    setInputValue('');
    setShowSuggestions(false);
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && inputValue.trim()) {
      e.preventDefault();
      handleAdd(inputValue);
    } else if (e.key === 'Escape') {
      setShowSuggestions(false);
      onClose();
    }
  };

  return (
    <div className="relative">
      <div className="flex gap-2">
        <div className="relative flex-1">
          <Input
            ref={inputRef}
            type="text"
            placeholder="Type to search or create tag..."
            value={inputValue}
            onChange={(e) => {
              setInputValue(e.target.value);
              setShowSuggestions(true);
            }}
            onFocus={() => setShowSuggestions(true)}
            onKeyDown={handleKeyDown}
            className="pr-8"
          />
          {isPending && (
            <Loader2 className="absolute right-2 top-1/2 -translate-y-1/2 h-4 w-4 animate-spin text-muted-foreground" />
          )}
        </div>
        <Button variant="ghost" size="icon" onClick={onClose}>
          <X className="h-4 w-4" />
        </Button>
      </div>

      {showSuggestions && (suggestions.length > 0 || inputValue.trim()) && (
        <div
          ref={suggestionsRef}
          className="absolute z-10 mt-1 w-full bg-popover border rounded-md shadow-lg max-h-48 overflow-y-auto"
        >
          {inputValue.trim() && !allTags.includes(inputValue.trim()) && (
            <button
              onClick={() => handleAdd(inputValue)}
              className="w-full px-3 py-2 text-left text-sm hover:bg-muted flex items-center gap-2"
            >
              <Plus className="h-4 w-4 text-primary" />
              Create "<span className="font-medium">{inputValue.trim()}</span>"
            </button>
          )}

          {suggestions.map((tag) => (
            <button
              key={tag}
              onClick={() => handleAdd(tag)}
              className={cn(
                'w-full px-3 py-2 text-left text-sm hover:bg-muted flex items-center justify-between',
                currentTags.includes(tag) && 'opacity-50'
              )}
              disabled={currentTags.includes(tag)}
            >
              <span className="flex items-center gap-2">
                <Tag className="h-4 w-4 text-muted-foreground" />
                {tag}
              </span>
              {currentTags.includes(tag) && (
                <Check className="h-4 w-4 text-primary" />
              )}
            </button>
          ))}

          {suggestions.length === 0 && !inputValue.trim() && (
            <div className="px-3 py-2 text-sm text-muted-foreground">
              Start typing to search or create tags
            </div>
          )}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 3: Refactor TagsPanel to use TagAutocomplete**

Replace the inline autocomplete section (lines 161-242) with:

```tsx
import { TagAutocomplete } from './TagAutocomplete';

// In JSX:
{isAdding && (
  <TagAutocomplete
    allTags={allTags || []}
    currentTags={currentTags}
    onAddTag={async (tag) => {
      await addTags.mutateAsync({
        conversationId,
        data: { tags: [tag] },
      });
      toast({ title: 'Tag added', description: `"${tag}" has been added.` });
    }}
    onClose={() => {
      setIsAdding(false);
      setInputValue('');  // remove this — no longer needed
    }}
    isPending={addTags.isPending}
  />
)}
```

Remove from TagsPanel: `inputValue`, `showSuggestions` state, `inputRef`, `suggestionsRef` refs, `handleClickOutside` effect, `handleKeyDown`, `handleAddTag` (partially — keep for quick-add), `suggestions` computed value.

Keep: `isAdding` state, `handleRemoveTag`, quick-add section at bottom.

- [ ] **Step 4: Verify frontend builds**

```bash
cd frontend && npx tsc --noEmit && npm run build
```

- [ ] **Step 5: Run /simplify then commit**

```bash
git add frontend/src/components/conversation/TagAutocomplete.tsx frontend/src/components/conversation/TagsPanel.tsx
git commit -m "refactor(tags): extract TagAutocomplete from TagsPanel

- Move autocomplete input + dropdown to dedicated component
- TagsPanel reduced from 270 to ~140 lines
- Removed 4 state variables and click-outside effect from TagsPanel
- No behavior changes"
```

---

## Task 6 (Optional): Extract loadWithRelationships in ConversationAssignmentController (LOW)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/ConversationAssignmentController.php`
- Modify: `backend/app/Http/Controllers/Api/BaseConversationController.php`

`load(['customerProfile', 'assignedUser'])` ซ้ำ 4 ครั้ง — extract เป็น helper ใน BaseConversationController

- [ ] **Step 1: Verify tests pass**

```bash
cd backend && php artisan test
```

- [ ] **Step 2: Add helper to BaseConversationController**

```php
// In BaseConversationController.php, add:
protected function loadConversationRelationships(Conversation $conversation): Conversation
{
    return $conversation->load(['customerProfile', 'assignedUser']);
}
```

- [ ] **Step 3: Replace 4 occurrences in ConversationAssignmentController**

```php
// Lines 75, 112, 143, 177 — replace:
$conversation->load(['customerProfile', 'assignedUser']);
// With:
$this->loadConversationRelationships($conversation);
```

- [ ] **Step 4: Verify tests pass**

```bash
cd backend && php artisan test && vendor/bin/pint --test
```

- [ ] **Step 5: Run /simplify then commit**

```bash
git add backend/app/Http/Controllers/Api/BaseConversationController.php backend/app/Http/Controllers/Api/ConversationAssignmentController.php
git commit -m "refactor(assignment): extract loadConversationRelationships helper

- DRY: replaced 4 duplicate load() calls with shared helper
- No behavior changes"
```

---

## Summary

| Task | Priority | Backend/Frontend | Before → After (lines) |
|------|----------|-----------------|----------------------|
| 1. ProcessAggregatedMessages | HIGH | Backend | 360 → ~360 (same LOC, 7 focused methods vs 1 god method) |
| 2. NoteForm extraction | HIGH | Frontend | 357 → ~180 + ~70 new |
| 3. Realtime utilities | HIGH | Frontend | 368 → ~250 + ~90 new |
| 4. BotSelectorPanel | MODERATE | Frontend | 317 → ~260 + ~60 new |
| 5. TagAutocomplete | MODERATE | Frontend | 270 → ~140 + ~120 new |
| 6. Assignment helper | LOW | Backend | 187 → ~183 |

**Total new files:** 4 (NoteForm, realtimeUtils, BotSelectorPanel, TagAutocomplete)
**Total modified files:** 6
**Net LOC change:** Roughly neutral — complexity moves from large files to focused files

**Verification commands (run after all tasks):**
```bash
cd backend && php artisan test && vendor/bin/pint --test
cd frontend && npx tsc --noEmit && npm run build && npm run lint
```
