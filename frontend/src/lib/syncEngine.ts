import { api } from '@/lib/api';
import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import type { QueryClient, InfiniteData } from '@tanstack/react-query';
import { type ConversationsResponse } from '@/hooks/chat/useConversationList';
import { messageKeys, type MessagesResponse } from '@/hooks/chat/messageKeys';
import type { Conversation, Message } from '@/types/api';

interface SyncCursors {
  lastConvSyncAt: Record<number, string>;
  lastMessageId: Record<string, number>;
  setCursor: (key: string, value: string | number) => void;
}

export const useSyncCursors = create<SyncCursors>()(
  persist(
    (set) => ({
      lastConvSyncAt: {},
      lastMessageId: {},
      setCursor: (key, value) =>
        set((state) => {
          if (key.startsWith('conv:')) {
            const botId = parseInt(key.split(':')[1]);
            return { lastConvSyncAt: { ...state.lastConvSyncAt, [botId]: value as string } };
          }
          return { lastMessageId: { ...state.lastMessageId, [key]: value as number } };
        }),
    }),
    { name: 'sync-cursors', storage: createJSONStorage(() => localStorage) }
  )
);

let pendingSync: Promise<void> | null = null;

export async function syncBot(
  botId: number,
  queryClient: QueryClient,
  selectedConversationId?: number | null
): Promise<void> {
  if (pendingSync) return pendingSync;

  pendingSync = (async () => {
    try {
      const cursors = useSyncCursors.getState();
      const since = cursors.lastConvSyncAt[botId];

      const params = since ? `?since=${encodeURIComponent(since)}` : '';
      const response = await api.get<{ data: Conversation[]; synced_at: string }>(
        `/bots/${botId}/conversations/sync${params}`
      );

      const delta = response.data;

      if (delta.data.length > 0) {
        queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
          {
            predicate: (query) => {
              const key = query.queryKey;
              return Array.isArray(key) && key[0] === 'conversations-infinite' && key[1] === botId;
            },
          },
          (old) => {
            if (!old) return old;
            const deltaMap = new Map(delta.data.map((c) => [c.id, c]));
            const updatedPages = old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) => deltaMap.get(conv.id) ?? conv),
            }));
            return { ...old, pages: updatedPages };
          }
        );
      }

      useSyncCursors.getState().setCursor(`conv:${botId}`, delta.synced_at);

      if (selectedConversationId) {
        await syncConversation(botId, selectedConversationId, queryClient);
      }
    } finally {
      pendingSync = null;
    }
  })();

  return pendingSync;
}

export async function syncConversation(
  botId: number,
  conversationId: number,
  queryClient: QueryClient
): Promise<void> {
  const cursors = useSyncCursors.getState();
  const cursorKey = `${botId}:${conversationId}`;
  const sinceId = cursors.lastMessageId[cursorKey] || 0;

  const response = await api.get<{ data: Message[]; has_more: boolean; synced_at: string }>(
    `/bots/${botId}/conversations/${conversationId}/messages/sync?since_id=${sinceId}`
  );

  const newMessages = response.data.data;

  if (newMessages.length > 0) {
    const messageOptions = { order: 'asc' as const, perPage: 100 };
    queryClient.setQueryData<MessagesResponse>(
      messageKeys.listWithOptions(botId, conversationId, messageOptions),
      (old) => {
        if (!old) return old;
        const existingIds = new Set(old.data.map((m) => m.id));
        const unique = newMessages.filter((m) => !existingIds.has(m.id));
        return { ...old, data: [...old.data, ...unique] };
      }
    );

    const maxId = Math.max(...newMessages.map((m) => m.id));
    useSyncCursors.getState().setCursor(cursorKey, maxId);
  }
}
