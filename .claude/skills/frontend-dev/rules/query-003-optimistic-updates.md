---
id: query-003-optimistic-updates
title: Optimistic Updates in Mutations
impact: HIGH
impactDescription: "Provides instant UI feedback for better perceived performance"
category: query
tags: [react-query, mutations, optimistic, ux]
relatedRules: [react-005-use-optimistic, query-004-cache-invalidation]
---

## Why This Matters

Optimistic updates show the expected result immediately, before the server confirms. The UI feels instant while the actual request happens in the background. If the request fails, the UI rolls back to the previous state.

This dramatically improves perceived performance for common actions like toggling, liking, or editing.

## Bad Example

```tsx
// Problem: Waiting for server response
function BotToggle({ bot }) {
  const mutation = useMutation({
    mutationFn: (id) => api.patch(`/api/v1/bots/${id}/toggle`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bots'] });
    },
  });

  return (
    <Switch
      checked={bot.active}
      onCheckedChange={() => mutation.mutate(bot.id)}
      disabled={mutation.isPending} // Button disabled, user waits
    />
  );
  // User clicks → disabled → waits 200-500ms → sees change
}

// Problem: Refetch approach is slow
function useUpdateBot() {
  return useMutation({
    mutationFn: updateBot,
    onSuccess: () => {
      // Refetches entire list - slow and wasteful
      queryClient.invalidateQueries({ queryKey: ['bots'] });
    },
  });
}
```

**Why it's wrong:**
- User waits for server response before seeing feedback
- Disabled state during mutation feels unresponsive
- Refetching entire list after small change is wasteful
- Poor perceived performance even with fast API

## Good Example

```tsx
// Solution: Optimistic update with rollback
function useToggleBot() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (botId: string) =>
      api.patch(`/api/v1/bots/${botId}/toggle`),

    // Optimistically update before server responds
    onMutate: async (botId) => {
      // Cancel outgoing refetches (prevents race conditions)
      await queryClient.cancelQueries({ queryKey: queryKeys.bots.all });

      // Snapshot current state for rollback
      const previousBots = queryClient.getQueryData(queryKeys.bots.list());
      const previousBot = queryClient.getQueryData(queryKeys.bots.detail(botId));

      // Optimistically update the cache
      queryClient.setQueryData(queryKeys.bots.list(), (old: Bot[] | undefined) =>
        old?.map((bot) =>
          bot.id === botId ? { ...bot, active: !bot.active } : bot
        )
      );

      queryClient.setQueryData(queryKeys.bots.detail(botId), (old: Bot | undefined) =>
        old ? { ...old, active: !old.active } : old
      );

      // Return context for rollback
      return { previousBots, previousBot };
    },

    // Rollback on error
    onError: (err, botId, context) => {
      if (context?.previousBots) {
        queryClient.setQueryData(queryKeys.bots.list(), context.previousBots);
      }
      if (context?.previousBot) {
        queryClient.setQueryData(queryKeys.bots.detail(botId), context.previousBot);
      }
      toast.error('Failed to toggle bot');
    },

    // Always refetch after mutation settles
    onSettled: (_, __, botId) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.bots.detail(botId) });
      queryClient.invalidateQueries({ queryKey: queryKeys.bots.lists() });
    },
  });
}

// Usage - instant feedback
function BotToggle({ bot }) {
  const toggleMutation = useToggleBot();

  return (
    <Switch
      checked={bot.active}
      onCheckedChange={() => toggleMutation.mutate(bot.id)}
      // No disabled state needed - UI updates immediately!
    />
  );
}

// Optimistic add to list
function useCreateBot() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: CreateBotDTO) =>
      api.post('/api/v1/bots', data).then(r => r.data.data),

    onMutate: async (newBot) => {
      await queryClient.cancelQueries({ queryKey: queryKeys.bots.lists() });

      const previousBots = queryClient.getQueryData(queryKeys.bots.list());

      // Add optimistic bot with temporary ID
      queryClient.setQueryData(queryKeys.bots.list(), (old: Bot[] = []) => [
        { ...newBot, id: `temp-${Date.now()}`, creating: true },
        ...old,
      ]);

      return { previousBots };
    },

    onError: (err, _, context) => {
      queryClient.setQueryData(queryKeys.bots.list(), context?.previousBots);
      toast.error('Failed to create bot');
    },

    onSuccess: (newBot) => {
      // Replace temp bot with real one
      queryClient.setQueryData(queryKeys.bots.list(), (old: Bot[] = []) =>
        old.map(bot => bot.creating ? newBot : bot)
      );
    },

    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.bots.lists() });
    },
  });
}
```

**Why it's better:**
- Instant UI feedback (0ms perceived latency)
- No disabled states during mutation
- Automatic rollback on failure
- Server becomes source of truth after mutation settles
- Better UX for common interactions

## Project-Specific Notes

**When to Use Optimistic Updates:**
| Action | Optimistic? | Reason |
|--------|-------------|--------|
| Toggle active/inactive | Yes | Instant feedback expected |
| Like/favorite | Yes | Common social pattern |
| Delete item | Yes | Immediate visual feedback |
| Create item | Maybe | Show "creating" state |
| Update form | No | Wait for validation |
| Complex operations | No | Too many states to track |

**BotFacebook Optimistic Patterns:**
```tsx
// Toggle bot status
useToggleBot() // Optimistic

// Mark conversation as read
useMarkAsRead() // Optimistic

// Send message
useSendMessage() // Optimistic with "sending" indicator

// Update bot settings
useUpdateBotSettings() // NOT optimistic - wait for server validation
```

## References

- [TanStack Query Optimistic Updates](https://tanstack.com/query/latest/docs/framework/react/guides/optimistic-updates)
- Related rule: react-005-use-optimistic
