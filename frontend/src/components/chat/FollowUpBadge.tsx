import { Badge } from '@/components/ui/badge';
import type { Message } from '@/types/api';

interface FollowUpBadgeProps {
  message: Pick<Message, 'sender' | 'is_follow_up'>;
}

/**
 * Small muted label on the automated customer follow-up message the follow-up
 * feature sent. Renders only for bot messages carrying is_follow_up === true —
 * absent or false means no badge, and it is never inferred from the text.
 */
export function FollowUpBadge({ message }: FollowUpBadgeProps) {
  if (message.sender !== 'bot' || message.is_follow_up !== true) return null;

  return (
    <Badge variant="inactive" className="mt-1 whitespace-normal max-w-full text-left">
      ข้อความตามลูกค้า
    </Badge>
  );
}
