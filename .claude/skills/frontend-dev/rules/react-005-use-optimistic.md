---
id: react-005-use-optimistic
title: useOptimistic for Instant Feedback (React 19)
impact: MEDIUM
impactDescription: "Provides instant UI feedback during async operations"
category: react
tags: [react-19, hooks, optimistic-updates, ux]
relatedRules: [query-003-optimistic-updates]
---

## Why This Matters

`useOptimistic` lets you show optimistic state while an async action is pending. The UI updates immediately, making the app feel faster, then reconciles with the server response.

This is particularly useful for actions like toggling, liking, or adding items where instant feedback improves UX.

## Bad Example

```tsx
// Problem: Waiting for server response before updating UI
function LikeButton({ postId, initialLikes }) {
  const [likes, setLikes] = useState(initialLikes);
  const [loading, setLoading] = useState(false);

  const handleLike = async () => {
    setLoading(true);
    try {
      const result = await api.post(`/posts/${postId}/like`);
      setLikes(result.likes); // UI updates only after server responds
    } finally {
      setLoading(false);
    }
  };

  return (
    <button onClick={handleLike} disabled={loading}>
      {loading ? '...' : `${likes} Likes`}
    </button>
  );
}

// Problem: Manual optimistic update with rollback
function TodoList({ todos, onToggle }) {
  const [optimisticTodos, setOptimisticTodos] = useState(todos);

  const handleToggle = async (id) => {
    // Optimistic update
    setOptimisticTodos(prev =>
      prev.map(t => t.id === id ? { ...t, done: !t.done } : t)
    );

    try {
      await onToggle(id);
    } catch {
      // Manual rollback - easy to mess up
      setOptimisticTodos(todos);
    }
  };

  return (/* ... */);
}
```

**Why it's wrong:**
- User waits for server response to see feedback
- Loading state feels sluggish
- Manual rollback logic is error-prone
- State can get out of sync with server

## Good Example

```tsx
// Solution: useOptimistic for instant feedback
'use client';

import { useOptimistic } from 'react';

interface Message {
  id: string;
  text: string;
  sending?: boolean;
}

function MessageList({ messages, sendMessage }) {
  const [optimisticMessages, addOptimisticMessage] = useOptimistic<
    Message[],
    string
  >(
    messages,
    (state, newText) => [
      ...state,
      {
        id: `temp-${Date.now()}`,
        text: newText,
        sending: true, // Visual indicator
      },
    ]
  );

  async function handleSubmit(formData: FormData) {
    const text = formData.get('text') as string;
    addOptimisticMessage(text); // Instant UI update
    await sendMessage(text); // Actual server call
    // React automatically reconciles with real data
  }

  return (
    <div>
      {optimisticMessages.map((msg) => (
        <div
          key={msg.id}
          className={cn(
            'rounded p-2',
            msg.sending && 'opacity-70' // Show pending state
          )}
        >
          {msg.text}
          {msg.sending && <span className="ml-2 text-xs">Sending...</span>}
        </div>
      ))}

      <form action={handleSubmit}>
        <input name="text" required />
        <button type="submit">Send</button>
      </form>
    </div>
  );
}

// Toggle example
function TodoItem({ todo, onToggle }) {
  const [optimisticTodo, setOptimisticTodo] = useOptimistic(
    todo,
    (state, optimisticValue: boolean) => ({
      ...state,
      done: optimisticValue,
    })
  );

  async function handleToggle() {
    setOptimisticTodo(!optimisticTodo.done);
    await onToggle(todo.id);
  }

  return (
    <label className="flex items-center gap-2">
      <input
        type="checkbox"
        checked={optimisticTodo.done}
        onChange={handleToggle}
      />
      <span className={optimisticTodo.done ? 'line-through' : ''}>
        {todo.text}
      </span>
    </label>
  );
}
```

**Why it's better:**
- Instant visual feedback
- Automatic reconciliation with server state
- No manual rollback needed
- Pending state can be styled (sending indicator)
- Works with form actions

## Project-Specific Notes

**useOptimistic vs React Query Optimistic Updates:**

| Feature | useOptimistic | React Query |
|---------|---------------|-------------|
| Scope | Single component | Global cache |
| Rollback | Automatic | Manual setup |
| Complexity | Simple | More setup |
| Cache sync | No | Yes |

**BotFacebook Recommendation:**
- Use `useOptimistic` for isolated UI interactions
- Use React Query optimistic updates for data that affects multiple components

**Common Use Cases:**
- Like/unlike buttons
- Toggle switches
- Adding items to a list
- Sending messages in chat

```tsx
// Chat message example in BotFacebook
function ChatInput({ conversationId }) {
  const { messages } = useMessages(conversationId);
  const sendMutation = useSendMessage();

  const [optimisticMessages, addOptimistic] = useOptimistic(
    messages,
    (state, newMessage) => [...state, newMessage]
  );

  async function handleSend(formData) {
    const text = formData.get('text');
    addOptimistic({
      id: `temp-${Date.now()}`,
      text,
      sending: true,
    });
    await sendMutation.mutateAsync({ conversationId, text });
  }

  return (/* form UI */);
}
```

## References

- [React useOptimistic](https://react.dev/reference/react/useOptimistic)
- Related rule: query-003-optimistic-updates
