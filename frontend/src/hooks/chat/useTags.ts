/**
 * T035: useTags hook for chat panel
 * Query and mutations for conversation tags with optimistic updates
 */
import { useMutation, useQuery, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AddTagsData } from '@/types/api';
import { conversationKeys, type ConversationsResponse } from './useConversationList';

// Query key factory
export const tagsKeys = {
  all: ['bot-tags'] as const,
  list: (botId: number) => [...tagsKeys.all, botId] as const,
};

// Response types
interface TagsResponse {
  data: string[];
}

interface TagOperationResponse {
  data: { tags: string[] };
  message: string;
}

/**
 * Hook to fetch all unique tags used in bot conversations
 */
export function useBotTags(botId: number | undefined) {
  return useQuery({
    queryKey: botId ? tagsKeys.list(botId) : ['bot-tags', 'disabled'],
    queryFn: async () => {
      const response = await api.get<TagsResponse>(`/bots/${botId}/conversations/tags`);
      return response.data.data;
    },
    enabled: !!botId,
    staleTime: 60000, // 1 minute
  });
}

/**
 * Hook to add tags to a conversation with optimistic update
 */
export function useAddTags(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      data,
    }: {
      conversationId: number;
      data: AddTagsData;
    }) => {
      const response = await api.post<TagOperationResponse>(
        `/bots/${botId}/conversations/${conversationId}/tags`,
        data
      );
      return response.data;
    },
    onMutate: async ({ conversationId, data }) => {
      if (!botId) return;

      // Cancel outgoing refetches
      await queryClient.cancelQueries({ queryKey: conversationKeys.infinite(botId) });

      // Snapshot for rollback
      const previousData = queryClient.getQueriesData<InfiniteData<ConversationsResponse>>({
        predicate: (query) => {
          const key = query.queryKey;
          return (
            Array.isArray(key) &&
            key[0] === 'conversations-infinite' &&
            key[1] === botId
          );
        },
      });

      // Optimistically add tags to the conversation
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        {
          predicate: (query) => {
            const key = query.queryKey;
            return (
              Array.isArray(key) &&
              key[0] === 'conversations-infinite' &&
              key[1] === botId
            );
          },
        },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId
                  ? { ...conv, tags: [...(conv.tags || []), ...data.tags] }
                  : conv
              ),
            })),
          };
        }
      );

      return { previousData };
    },
    onError: (_err, _vars, context) => {
      // Rollback on error
      if (context?.previousData) {
        context.previousData.forEach(([queryKey, data]) => {
          if (data) {
            queryClient.setQueryData(queryKey, data);
          }
        });
      }
    },
    onSettled: () => {
      if (!botId) return;
      queryClient.invalidateQueries({ queryKey: conversationKeys.infinite(botId) });
      queryClient.invalidateQueries({ queryKey: tagsKeys.list(botId) });
    },
  });
}

/**
 * Hook to remove a tag from a conversation with optimistic update
 */
export function useRemoveTag(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      tag,
    }: {
      conversationId: number;
      tag: string;
    }) => {
      const response = await api.delete<TagOperationResponse>(
        `/bots/${botId}/conversations/${conversationId}/tags/${encodeURIComponent(tag)}`
      );
      return response.data;
    },
    onMutate: async ({ conversationId, tag }) => {
      if (!botId) return;

      await queryClient.cancelQueries({ queryKey: conversationKeys.infinite(botId) });

      const previousData = queryClient.getQueriesData<InfiniteData<ConversationsResponse>>({
        predicate: (query) => {
          const key = query.queryKey;
          return (
            Array.isArray(key) &&
            key[0] === 'conversations-infinite' &&
            key[1] === botId
          );
        },
      });

      // Optimistically remove the tag
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        {
          predicate: (query) => {
            const key = query.queryKey;
            return (
              Array.isArray(key) &&
              key[0] === 'conversations-infinite' &&
              key[1] === botId
            );
          },
        },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId
                  ? { ...conv, tags: (conv.tags || []).filter((t) => t !== tag) }
                  : conv
              ),
            })),
          };
        }
      );

      return { previousData };
    },
    onError: (_err, _vars, context) => {
      if (context?.previousData) {
        context.previousData.forEach(([queryKey, data]) => {
          if (data) {
            queryClient.setQueryData(queryKey, data);
          }
        });
      }
    },
    onSettled: () => {
      if (!botId) return;
      queryClient.invalidateQueries({ queryKey: conversationKeys.infinite(botId) });
      queryClient.invalidateQueries({ queryKey: tagsKeys.list(botId) });
    },
  });
}
