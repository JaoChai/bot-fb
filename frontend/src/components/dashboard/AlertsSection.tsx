import { Link } from 'react-router';
import {
  AlertCircle,
  Users,
  FlaskConical,
  Sparkles,
  ArrowRight,
  Clock,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import type { DashboardAlerts } from '@/types/api';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';

interface AlertsSectionProps {
  alerts: DashboardAlerts;
}

export function AlertsSection({ alerts }: AlertsSectionProps) {
  const hasAlerts =
    alerts.handover_conversations.length > 0 ||
    alerts.running_evaluations.length > 0 ||
    alerts.pending_improvements.length > 0;

  if (!hasAlerts) {
    return null;
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <AlertCircle className="h-4 w-4 text-orange-500" />
          ต้องดำเนินการ
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Handover Conversations */}
        {alerts.handover_conversations.length > 0 && (
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2 text-sm font-medium text-red-600">
                <Users className="h-4 w-4" />
                <span>
                  {alerts.handover_conversations.length} การสนทนาที่รอมนุษย์ตอบ
                </span>
              </div>
              <Button variant="ghost" size="sm" asChild>
                <Link to="/conversations?status=handover">
                  ดูทั้งหมด <ArrowRight className="ml-1 h-3 w-3" />
                </Link>
              </Button>
            </div>
            <div className="pl-6 space-y-1">
              {alerts.handover_conversations.slice(0, 3).map((conv) => (
                <Link
                  key={conv.id}
                  to={`/conversations/${conv.id}`}
                  className="flex items-center justify-between text-sm text-muted-foreground hover:text-foreground transition-colors"
                >
                  <span>
                    {conv.bot_name}: {conv.customer_name}
                  </span>
                  <span className="flex items-center gap-1 text-xs">
                    <Clock className="h-3 w-3" />
                    {formatDistanceToNow(new Date(conv.waiting_since), {
                      addSuffix: false,
                      locale: th,
                    })}
                  </span>
                </Link>
              ))}
            </div>
          </div>
        )}

        {/* Running Evaluations */}
        {alerts.running_evaluations.length > 0 && (
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2 text-sm font-medium text-blue-600">
                <FlaskConical className="h-4 w-4" />
                <span>
                  {alerts.running_evaluations.length} การประเมินกำลังทำงาน
                </span>
              </div>
            </div>
            <div className="pl-6 space-y-2">
              {alerts.running_evaluations.slice(0, 3).map((eval_) => (
                <Link
                  key={eval_.id}
                  to={`/bots/${eval_.bot_id}/evaluations/${eval_.id}`}
                  className="block space-y-1 hover:bg-accent/50 rounded p-1 -m-1 transition-colors"
                >
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">
                      {eval_.bot_name}: {eval_.name}
                    </span>
                    <span className="text-xs font-medium">
                      {eval_.progress_percent}%
                    </span>
                  </div>
                  <Progress value={eval_.progress_percent} className="h-1.5" />
                </Link>
              ))}
            </div>
          </div>
        )}

        {/* Pending Improvements */}
        {alerts.pending_improvements.length > 0 && (
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2 text-sm font-medium text-purple-600">
                <Sparkles className="h-4 w-4" />
                <span>
                  {alerts.pending_improvements.length} คำแนะนำรออนุมัติ
                </span>
              </div>
            </div>
            <div className="pl-6 space-y-1">
              {alerts.pending_improvements.slice(0, 3).map((imp) => (
                <Link
                  key={imp.id}
                  to={`/bots/${imp.bot_id}/improvement-sessions/${imp.id}`}
                  className="flex items-center justify-between text-sm text-muted-foreground hover:text-foreground transition-colors"
                >
                  <span>{imp.bot_name}</span>
                  <span className="text-xs">
                    {imp.suggestions_count} คำแนะนำ
                  </span>
                </Link>
              ))}
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
