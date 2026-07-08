/**
 * useConfirmPayment - Admin manual payment confirmation
 *
 * Confirms a payment through the bot's output pipeline (Flex + LINE push + plugins),
 * so a manually confirmed payment creates an order exactly like the automatic slip path.
 * Used when EasySlip is down or the admin verifies a slip by hand.
 */
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { messageKeys } from './messageKeys';
import type { Message } from '@/types/api';

export interface ConfirmPaymentResponse {
  message: Message;
  order_created: boolean;
}

export function useConfirmPayment(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      amount,
    }: {
      conversationId: number;
      amount?: number;
    }) => {
      const response = await api.post<ConfirmPaymentResponse>(
        `/conversations/${conversationId}/confirm-payment`,
        amount != null ? { amount } : {}
      );
      return response.data;
    },
    onSuccess: (_data, { conversationId }) => {
      if (!botId) return;
      // Bot message was pushed server-side; refetch so it appears in the thread.
      queryClient.invalidateQueries({
        queryKey: messageKeys.list(botId, conversationId),
      });
      queryClient.invalidateQueries({
        queryKey: messageKeys.infinite(botId, conversationId),
      });
    },
  });
}
