import { Link } from 'react-router';
import { ArrowRight, XCircle } from 'lucide-react';
import type { DashboardBotSummary } from '@/types/api';

interface AlertStripProps {
  bots: DashboardBotSummary[];
}

/**
 * Shows bots that are down. Renders nothing when everything is fine.
 */
export function AlertStrip({ bots }: AlertStripProps) {
  const inactiveBots = bots.filter((b) => b.status !== 'active');

  if (inactiveBots.length === 0) return null;

  return (
    <div className="flex items-center gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3">
      <XCircle className="size-4 shrink-0 text-destructive" strokeWidth={1.5} />
      <p className="text-sm font-medium text-destructive">
        บอทหยุดทำงาน: {inactiveBots.map((b) => b.name).join(', ')}
      </p>
      <Link
        to="/connections"
        className="ml-auto inline-flex shrink-0 items-center gap-1 text-xs font-medium text-destructive hover:underline"
      >
        ไปดู <ArrowRight className="size-3" />
      </Link>
    </div>
  );
}
