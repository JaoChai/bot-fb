import { Link } from 'react-router';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';
import { AlertTriangle, ArrowRight, XCircle } from 'lucide-react';
import type { DashboardBotSummary, DashboardHandoverAlert } from '@/types/api';

interface AlertStripProps {
  bots: DashboardBotSummary[];
  handovers: DashboardHandoverAlert[];
  /** Total recent handovers from summary (the list is capped at 5 server-side) */
  handoverTotal: number;
}

/**
 * Shows only actionable problems: bots that are down and customers waiting
 * for a human reply (backend already filters handovers to the last 48h).
 * Renders nothing when everything is fine.
 */
export function AlertStrip({ bots, handovers, handoverTotal }: AlertStripProps) {
  const inactiveBots = bots.filter((b) => b.status !== 'active');

  if (inactiveBots.length === 0 && handovers.length === 0) return null;

  return (
    <div className="space-y-3">
      {inactiveBots.length > 0 && (
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
      )}

      {handovers.length > 0 && (
        <div className="rounded-lg border border-amber-500/30 bg-amber-50/50 px-4 py-3 dark:bg-amber-950/20">
          <div className="flex items-center gap-2">
            <AlertTriangle
              className="size-4 shrink-0 text-amber-600 dark:text-amber-400"
              strokeWidth={1.5}
            />
            <p className="text-sm font-medium text-amber-700 dark:text-amber-300">
              ลูกค้ารอคนตอบ {handoverTotal} คน
            </p>
          </div>
          <ul className="mt-2 space-y-1">
            {handovers.map((h) => (
              <li key={h.id}>
                <Link
                  to={`/chat?botId=${h.bot_id}`}
                  className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors hover:bg-amber-100/60 dark:hover:bg-amber-900/30"
                >
                  <span className="min-w-0 truncate font-medium">{h.customer_name}</span>
                  <span className="shrink-0 text-xs text-muted-foreground">{h.bot_name}</span>
                  <span className="ml-auto shrink-0 text-xs tabular-nums text-amber-700 dark:text-amber-400">
                    รอ {formatDistanceToNow(new Date(h.waiting_since), { locale: th })}
                  </span>
                  <ArrowRight className="size-3 shrink-0 text-muted-foreground" />
                </Link>
              </li>
            ))}
          </ul>
          {handoverTotal > handovers.length && (
            <p className="mt-1 px-2 text-xs text-muted-foreground">
              และอีก {handoverTotal - handovers.length} คน — ดูทั้งหมดในหน้าแชท
            </p>
          )}
        </div>
      )}
    </div>
  );
}
