import { useState, useEffect } from 'react';
import { useParams, useSearchParams } from 'react-router';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { QADashboard } from '@/components/qa-inspector/QADashboard';
import { QAEvaluationLogList } from '@/components/qa-inspector/QAEvaluationLogList';
import { QAEvaluationLogDetail } from '@/components/qa-inspector/QAEvaluationLogDetail';
import { QAWeeklyReportList } from '@/components/qa-inspector/QAWeeklyReportList';
import { QAWeeklyReportView } from '@/components/qa-inspector/QAWeeklyReportView';
import { QAInspectorSettings } from '@/components/qa-inspector/QAInspectorSettings';
import { BarChart3, List, Settings, FileText } from 'lucide-react';

const VALID_TABS = ['dashboard', 'logs', 'reports', 'settings'] as const;
type TabValue = typeof VALID_TABS[number];

export function QAInspectorPage() {
  const { botId } = useParams<{ botId: string }>();
  const [searchParams, setSearchParams] = useSearchParams();
  const [selectedLogId, setSelectedLogId] = useState<number | null>(null);
  const [selectedReportId, setSelectedReportId] = useState<number | null>(null);

  // Get tab from URL or default to 'dashboard'
  const tabParam = searchParams.get('tab');
  const defaultTab: TabValue = VALID_TABS.includes(tabParam as TabValue)
    ? (tabParam as TabValue)
    : 'dashboard';
  const [activeTab, setActiveTab] = useState<TabValue>(defaultTab);

  // Sync tab with URL when it changes
  useEffect(() => {
    const currentTab = searchParams.get('tab');
    if (currentTab !== activeTab) {
      if (activeTab === 'dashboard') {
        // Remove tab param for default value
        searchParams.delete('tab');
      } else {
        searchParams.set('tab', activeTab);
      }
      setSearchParams(searchParams, { replace: true });
    }
  }, [activeTab, searchParams, setSearchParams]);

  const numericBotId = parseInt(botId || '0', 10);

  if (!numericBotId) {
    return (
      <div className="p-8 text-center text-muted-foreground">Invalid bot ID</div>
    );
  }

  // Show detail view if a log is selected
  if (selectedLogId) {
    return (
      <div className="container py-6">
        <QAEvaluationLogDetail
          botId={numericBotId}
          logId={selectedLogId}
          onBack={() => setSelectedLogId(null)}
        />
      </div>
    );
  }

  // Show report detail view if a report is selected
  if (selectedReportId) {
    return (
      <div className="container py-6">
        <QAWeeklyReportView
          botId={numericBotId}
          reportId={selectedReportId}
          onBack={() => setSelectedReportId(null)}
        />
      </div>
    );
  }

  return (
    <div className="container py-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold">QA Inspector</h1>
        <p className="text-muted-foreground">
          AI-powered quality assurance for bot responses
        </p>
      </div>

      <Tabs value={activeTab} onValueChange={(v) => setActiveTab(v as TabValue)} className="space-y-6">
        <TabsList>
          <TabsTrigger value="dashboard" className="gap-2">
            <BarChart3 className="h-4 w-4" />
            Dashboard
          </TabsTrigger>
          <TabsTrigger value="logs" className="gap-2">
            <List className="h-4 w-4" />
            Evaluation Logs
          </TabsTrigger>
          <TabsTrigger value="reports" className="gap-2">
            <FileText className="h-4 w-4" />
            Weekly Reports
          </TabsTrigger>
          <TabsTrigger value="settings" className="gap-2">
            <Settings className="h-4 w-4" />
            Settings
          </TabsTrigger>
        </TabsList>

        <TabsContent value="dashboard">
          <QADashboard botId={numericBotId} />
        </TabsContent>

        <TabsContent value="logs">
          <QAEvaluationLogList
            botId={numericBotId}
            onSelectLog={setSelectedLogId}
          />
        </TabsContent>

        <TabsContent value="reports">
          <QAWeeklyReportList
            botId={numericBotId}
            onSelectReport={setSelectedReportId}
          />
        </TabsContent>

        <TabsContent value="settings">
          <QAInspectorSettings botId={numericBotId} />
        </TabsContent>
      </Tabs>
    </div>
  );
}

export default QAInspectorPage;
