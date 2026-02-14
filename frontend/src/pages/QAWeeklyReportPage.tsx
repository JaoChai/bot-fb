import { useParams, useNavigate } from 'react-router';
import { QAWeeklyReportView } from '@/components/qa-inspector/QAWeeklyReportView';

export function QAWeeklyReportPage() {
  const { botId, reportId } = useParams<{ botId: string; reportId: string }>();
  const navigate = useNavigate();

  const numericBotId = parseInt(botId || '0', 10);
  const numericReportId = parseInt(reportId || '0', 10);

  if (!numericBotId || !numericReportId) {
    return (
      <div className="p-8 text-center text-muted-foreground">
        Invalid parameters
      </div>
    );
  }

  return (
    <div className="container py-6">
      <QAWeeklyReportView
        botId={numericBotId}
        reportId={numericReportId}
        onBack={() => navigate(`/bots/${botId}/qa-inspector`)}
      />
    </div>
  );
}

