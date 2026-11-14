import { Badge } from '@/components/ui/badge';
import type { Message } from '@/types/api';

interface OutageNoticeBadgeProps {
  message: Pick<Message, 'sender' | 'is_outage_notice'>;
}

/**
 * Small muted label on the automated recovery notice a bot sends after an
 * outage. Renders only for bot messages carrying is_outage_notice === true —
 * absent or false means no badge, and it is never inferred from the text.
 */
export function OutageNoticeBadge({ message }: OutageNoticeBadgeProps) {
  if (message.sender !== 'bot' || message.is_outage_notice !== true) return null;

  return (
    <Badge variant="inactive" className="mt-1 whitespace-normal max-w-full text-left">
      ส่งอัตโนมัติหลังบอทล่ม
    </Badge>
  );
}
