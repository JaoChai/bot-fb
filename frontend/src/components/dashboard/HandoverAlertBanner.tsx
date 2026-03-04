import { useState } from 'react';
import { AlertTriangle, ChevronDown, ChevronUp, ExternalLink } from 'lucide-react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';
import type { DashboardHandoverAlert } from '@/types/api';

interface HandoverAlertBannerProps {
  conversations: DashboardHandoverAlert[];
}

export function HandoverAlertBanner({ conversations }: HandoverAlertBannerProps) {
  const [isExpanded, setIsExpanded] = useState(false);

  if (conversations.length === 0) return null;

  return (
    <div className="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30">
      <div className="flex items-center justify-between px-4 py-3">
        <div className="flex items-center gap-2">
          <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
          <span className="text-sm font-medium text-amber-800 dark:text-amber-300">
            มี {conversations.length} แชทรอคุณตอบ
          </span>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            className="h-7 text-xs text-amber-700 hover:text-amber-900 dark:text-amber-400"
            onClick={() => setIsExpanded(!isExpanded)}
          >
            {isExpanded ? (
              <ChevronUp className="h-3 w-3 mr-1" />
            ) : (
              <ChevronDown className="h-3 w-3 mr-1" />
            )}
            {isExpanded ? 'ย่อ' : 'ดูรายชื่อ'}
          </Button>
          <Button variant="ghost" size="sm" className="h-7 text-xs text-amber-700 hover:text-amber-900 dark:text-amber-400" asChild>
            <Link to="/chat?filter=handover">
              ดูแชท
              <ExternalLink className="h-3 w-3 ml-1" />
            </Link>
          </Button>
        </div>
      </div>

      {isExpanded && (
        <div className="border-t border-amber-200 dark:border-amber-800 px-4 py-2 space-y-1">
          {conversations.map((conv) => (
            <div
              key={conv.id}
              className="flex items-center justify-between py-1.5 text-sm"
            >
              <div className="flex items-center gap-2">
                <span className="text-amber-800 dark:text-amber-300 font-medium">
                  {conv.customer_name}
                </span>
                <span className="text-amber-600/70 dark:text-amber-500 text-xs">
                  ({conv.bot_name})
                </span>
              </div>
              <span className="text-xs text-amber-600/70 dark:text-amber-500">
                รอมา {formatDistanceToNow(new Date(conv.waiting_since), { locale: th })}
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
