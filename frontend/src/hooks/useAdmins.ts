import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

interface Admin {
  id: number;
  user_id: number;
  bot_id: number;
  assigned_by: number | null;
  created_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  };
  active_conversations_count?: number;
}

interface SearchUser {
  id: number;
  name: string;
  email: string;
}

// Get admins for a bot
export function useBotAdmins(botId: number | undefined) {
  return useQuery({
    queryKey: ['bot-admins', botId],
    queryFn: async () => {
      const { data } = await api.get<{ data: Admin[] }>(`/bots/${botId}/admins`);
      return data.data;
    },
    enabled: !!botId,
  });
}

// Get admins with conversation counts
export function useBotAdminsWithCounts(botId: number | undefined) {
  return useQuery({
    queryKey: ['bot-admins-counts', botId],
    queryFn: async () => {
      const { data } = await api.get<{ data: Admin[] }>(`/bots/${botId}/admins?with_counts=true`);
      return data.data;
    },
    enabled: !!botId,
  });
}

// Search users by email
export function useSearchUsers(email: string) {
  return useQuery({
    queryKey: ['search-users', email],
    queryFn: async () => {
      const { data } = await api.get<{ data: SearchUser[] }>(`/users/search?email=${encodeURIComponent(email)}`);
      return data.data;
    },
    enabled: email.length >= 3,
  });
}

// Add admin to bot
export function useAddAdmin(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (userId: number) => {
      const { data } = await api.post(`/bots/${botId}/admins`, { user_id: userId });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bot-admins', botId] });
      queryClient.invalidateQueries({ queryKey: ['bot-admins-counts', botId] });
    },
  });
}

// Remove admin from bot
export function useRemoveAdmin(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (userId: number) => {
      const { data } = await api.delete(`/bots/${botId}/admins/${userId}`);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bot-admins', botId] });
      queryClient.invalidateQueries({ queryKey: ['bot-admins-counts', botId] });
    },
  });
}

// Update auto-assignment settings
export function useUpdateAutoAssignment(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (settings: { enabled: boolean; mode: 'round_robin' | 'load_balanced' }) => {
      const { data } = await api.patch(`/bots/${botId}/settings`, {
        auto_assignment_enabled: settings.enabled,
        auto_assignment_mode: settings.mode,
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bot', botId] });
    },
  });
}

// Assign conversation to admin
export function useAssignConversation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ conversationId, userId }: { conversationId: number; userId: number }) => {
      const { data } = await api.post(`/conversations/${conversationId}/assign`, { user_id: userId });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['conversations'] });
    },
  });
}

// Unassign conversation
export function useUnassignConversation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (conversationId: number) => {
      const { data } = await api.post(`/conversations/${conversationId}/unassign`);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['conversations'] });
    },
  });
}
