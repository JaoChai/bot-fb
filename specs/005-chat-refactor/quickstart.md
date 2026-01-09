# Quickstart: Chat Page Refactor

**Created**: 2026-01-09 | **Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

## Prerequisites

- Node.js 20+ and npm
- PHP 8.2+ and Composer
- PostgreSQL (Neon)
- Access to bot-fb repository

## Quick Setup

```bash
# Clone and checkout feature branch
git checkout 005-chat-refactor

# Backend
cd backend
composer install
php artisan migrate
php artisan serve

# Frontend (new terminal)
cd frontend
npm install
npm run dev
```

## File Structure Overview

### What You're Refactoring

| Current File | Lines | Target After Refactor |
|--------------|-------|----------------------|
| `ChatPage.tsx` | ~560 | ~150 (layout only) |
| `ChatWindow.tsx` | ~786 | ~100 (container) |
| `CustomerInfoPanel.tsx` | ~400 | ~100 (container) |
| `useConversations.ts` | ~885 | 6 files @ ~150 each |
| `ConversationController.php` | ~1,079 | ~200 (delegate to services) |

### New Files to Create

**Frontend Components** (`frontend/src/components/chat/`):
```
MessageList.tsx       # Message display with virtual scroll
MessageBubble.tsx     # Single message rendering
MessageInput.tsx      # Input with send button
ChatHeader.tsx        # Conversation header with actions
ConversationItem.tsx  # Single conversation in list
CustomerDetails.tsx   # Customer info section
ConversationNotes.tsx # Notes section
ConversationTags.tsx  # Tags section
```

**Frontend Hooks** (`frontend/src/hooks/chat/`):
```
useMessages.ts           # Message queries/mutations
useConversationList.ts   # List queries with filters
useConversationDetails.ts # Single conversation
useNotes.ts              # Notes CRUD
useTags.ts               # Tags CRUD
useRealtime.ts           # WebSocket subscriptions
```

**Frontend Store** (`frontend/src/stores/`):
```
chatStore.ts   # UI state (selected conversation, panels)
```

**Frontend Adapters** (`frontend/src/components/chat/adapters/`):
```
ChannelAdapter.ts     # Interface definition
LineAdapter.tsx       # LINE-specific rendering
TelegramAdapter.tsx   # Telegram-specific rendering
FacebookAdapter.tsx   # Facebook-specific rendering
```

**Backend Services** (`backend/app/Services/Chat/`):
```
ConversationService.php  # Conversation CRUD + queries
MessageService.php       # Message handling
NoteService.php          # Admin notes
TagService.php           # Conversation tags
```

## Development Workflow

### 1. Backend First (Service Layer)

Start by creating the service layer without touching the controller:

```bash
# Create services directory
mkdir -p backend/app/Services/Chat

# Create tests first (TDD)
mkdir -p backend/tests/Unit/Services/Chat
```

Example service structure:

```php
// backend/app/Services/Chat/MessageService.php
<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

class MessageService
{
    public function getForConversation(Conversation $conversation, int $limit = 50): Collection
    {
        return $conversation->messages()
            ->with(['sender'])
            ->orderByDesc('created_at')
            ->take($limit)
            ->get()
            ->reverse();
    }

    public function send(Conversation $conversation, string $content, $sender): Message
    {
        // Business logic here
    }
}
```

### 2. Frontend Second (Component Split)

After backend services are ready, split frontend components:

```bash
# Create new component files
cd frontend/src/components/chat
touch MessageList.tsx MessageBubble.tsx MessageInput.tsx ChatHeader.tsx
```

### 3. Hooks Split

Extract from `useConversations.ts` into domain files:

```bash
mkdir -p frontend/src/hooks/chat
cd frontend/src/hooks/chat
touch useMessages.ts useConversationList.ts useNotes.ts useTags.ts useRealtime.ts
```

### 4. Store Creation

```bash
# Create Zustand store for UI state
touch frontend/src/stores/chatStore.ts
```

## Testing Strategy

### Backend Unit Tests

```bash
# Run all chat service tests
php artisan test tests/Unit/Services/Chat

# Run with coverage
php artisan test --coverage --min=80 tests/Unit/Services/Chat
```

### Frontend Component Tests

```bash
# Run chat component tests
npm run test -- --grep chat

# Watch mode during development
npm run test:watch
```

## Common Patterns

### Creating a New Component

```tsx
// 1. Define props interface
interface MessageBubbleProps {
  message: Message;
  isOwn: boolean;
}

// 2. Export named function component
export function MessageBubble({ message, isOwn }: MessageBubbleProps) {
  return (
    <div className={cn(
      'rounded-lg p-3',
      isOwn ? 'bg-blue-500 text-white' : 'bg-gray-100'
    )}>
      {message.content}
    </div>
  );
}
```

### Creating a Hook

```tsx
// hooks/chat/useMessages.ts
export const messageKeys = {
  all: ['messages'] as const,
  list: (conversationId: string) => [...messageKeys.all, 'list', conversationId] as const,
};

export function useMessages(conversationId: string) {
  return useQuery({
    queryKey: messageKeys.list(conversationId),
    queryFn: () => api.messages.list(conversationId),
    staleTime: 30_000,
  });
}
```

### Creating a Service

```php
// app/Services/Chat/NoteService.php
class NoteService
{
    public function addNote(Conversation $conversation, string $content, User $user): void
    {
        $notes = $conversation->memory_notes ?? [];
        $notes[] = [
            'id' => Str::uuid()->toString(),
            'content' => $content,
            'created_by' => $user->id,
            'created_at' => now()->toIso8601String(),
        ];

        $conversation->update(['memory_notes' => $notes]);
    }
}
```

## Validation Checklist

Before marking a task complete:

- [ ] Component under 200 lines?
- [ ] Single responsibility?
- [ ] TypeScript types defined?
- [ ] Tests written?
- [ ] No regressions?

## Key Files to Reference

| Reference | Location |
|-----------|----------|
| Spec | `specs/005-chat-refactor/spec.md` |
| Plan | `specs/005-chat-refactor/plan.md` |
| Research | `specs/005-chat-refactor/research.md` |
| Data Model | `specs/005-chat-refactor/data-model.md` |
| API Contract | `specs/005-chat-refactor/contracts/chat-api.yaml` |

## Getting Help

```bash
# Check agent sets available
# See CLAUDE.md for full list

# Recommended sets for this refactor:
# - frontend-dev: React components, hooks
# - backend-dev: Laravel services
# - code-review: Before commits
```

## Next Steps

After reading this quickstart:

1. Run `/speckit.tasks` to generate task breakdown
2. Start with backend service layer (lower risk)
3. Split frontend components incrementally
4. Test each change before moving on
