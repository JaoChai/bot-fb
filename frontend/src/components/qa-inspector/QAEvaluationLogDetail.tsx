import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Loader2,
  ArrowLeft,
  AlertTriangle,
  CheckCircle,
  MessageSquare,
  Bot,
  FileText,
  Database,
} from 'lucide-react';
import { useQAEvaluationLog } from '@/hooks/useQAInspector';
import { cn } from '@/lib/utils';
import type { QAEvaluationScores, QAKBChunk } from '@/types/qa-inspector';

interface QAEvaluationLogDetailProps {
  botId: number;
  logId: number;
  onBack?: () => void;
}

export function QAEvaluationLogDetail({
  botId,
  logId,
  onBack,
}: QAEvaluationLogDetailProps) {
  const { data: log, isLoading, isError } = useQAEvaluationLog(botId, logId);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center p-8">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (isError || !log) {
    return (
      <div className="text-center p-8 text-muted-foreground">
        ไม่สามารถโหลดรายละเอียดการประเมินได้
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        {onBack && (
          <Button variant="ghost" size="sm" onClick={onBack}>
            <ArrowLeft className="h-4 w-4 mr-2" />
            กลับ
          </Button>
        )}
        <div className="flex-1">
          <h2 className="text-xl font-semibold">การประเมิน #{log.id}</h2>
          <p className="text-sm text-muted-foreground">
            {new Date(log.created_at).toLocaleString('th-TH')}
          </p>
        </div>
        {log.is_flagged ? (
          <Badge variant="destructive" className="gap-1">
            <AlertTriangle className="h-3 w-3" />
            พบปัญหา
          </Badge>
        ) : (
          <Badge className="gap-1 bg-green-500">
            <CheckCircle className="h-3 w-3" />
            ผ่าน
          </Badge>
        )}
      </div>

      {/* Scores */}
      <Card>
        <CardHeader>
          <CardTitle>คะแนนการประเมิน</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
            {Object.entries(log.scores as QAEvaluationScores).map(
              ([metric, score]) => (
                <ScoreCard key={metric} metric={metric} score={score} />
              )
            )}
          </div>
          <div className="mt-4 pt-4 border-t">
            <div className="flex items-center justify-between">
              <span className="font-medium">คะแนนรวม</span>
              <span
                className={cn(
                  'text-2xl font-bold',
                  log.overall_score >= 0.7
                    ? 'text-green-600'
                    : log.overall_score >= 0.5
                      ? 'text-yellow-600'
                      : 'text-red-600'
                )}
              >
                {Math.round(log.overall_score * 100)}%
              </span>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Issue Details (if flagged) */}
      {log.is_flagged && log.issue_type && (
        <Card className="border-destructive/50">
          <CardHeader>
            <CardTitle className="text-destructive">รายละเอียดปัญหา</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            <div className="flex items-center gap-2">
              <span className="text-sm text-muted-foreground">ประเภท:</span>
              <Badge variant="outline" className="capitalize">
                {log.issue_type.replace('_', ' ')}
              </Badge>
            </div>
            {log.issue_details && (
              <div>
                <span className="text-sm text-muted-foreground">รายละเอียด:</span>
                <pre className="mt-1 p-2 bg-muted rounded text-sm overflow-x-auto">
                  {JSON.stringify(log.issue_details, null, 2)}
                </pre>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* Conversation */}
      <Card>
        <CardHeader>
          <CardTitle>บทสนทนา</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex gap-3">
            <MessageSquare className="h-5 w-5 text-blue-500 mt-1 shrink-0" />
            <div className="flex-1">
              <p className="text-sm font-medium text-blue-600">คำถามผู้ใช้</p>
              <p className="mt-1 bg-blue-50 p-3 rounded-lg dark:bg-blue-950/30">
                {log.user_question}
              </p>
            </div>
          </div>
          <div className="flex gap-3">
            <Bot className="h-5 w-5 text-green-500 mt-1 shrink-0" />
            <div className="flex-1">
              <p className="text-sm font-medium text-green-600">คำตอบบอท</p>
              <p className="mt-1 bg-green-50 p-3 rounded-lg whitespace-pre-wrap dark:bg-green-950/30">
                {log.bot_response}
              </p>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Context Used */}
      {(log.system_prompt_used ||
        (log.kb_chunks_used && log.kb_chunks_used.length > 0)) && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <FileText className="h-5 w-5" />
              บริบทที่ใช้
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {log.system_prompt_used && (
              <div>
                <p className="text-sm font-medium mb-2">System Prompt</p>
                <pre className="p-3 bg-muted rounded text-sm overflow-x-auto max-h-48">
                  {log.system_prompt_used}
                </pre>
              </div>
            )}
            {log.kb_chunks_used && log.kb_chunks_used.length > 0 && (
              <div>
                <p className="text-sm font-medium mb-2 flex items-center gap-2">
                  <Database className="h-4 w-4" />
                  ข้อมูลจากฐานความรู้ ({log.kb_chunks_used.length} รายการ)
                </p>
                <div className="space-y-2">
                  {log.kb_chunks_used.map((chunk: QAKBChunk, idx: number) => (
                    <div key={idx} className="p-2 bg-muted rounded text-sm">
                      <div className="flex items-center gap-2 mb-1 text-xs text-muted-foreground">
                        <span>{chunk.document_name}</span>
                        <span>-</span>
                        <span>ความคล้ายคลึง: {(chunk.similarity * 100).toFixed(1)}%</span>
                      </div>
                      <p>{chunk.content}</p>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* Model Metadata */}
      {log.model_metadata && (
        <Card>
          <CardHeader>
            <CardTitle>ข้อมูลการประเมิน</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
              {log.model_metadata.realtime_model && (
                <div>
                  <p className="text-muted-foreground">โมเดลเรียลไทม์</p>
                  <p className="font-mono">{log.model_metadata.realtime_model}</p>
                </div>
              )}
              {log.model_metadata.analysis_model && (
                <div>
                  <p className="text-muted-foreground">โมเดลวิเคราะห์</p>
                  <p className="font-mono">{log.model_metadata.analysis_model}</p>
                </div>
              )}
              {log.model_metadata.prompt_tokens !== undefined && (
                <div>
                  <p className="text-muted-foreground">Prompt Tokens</p>
                  <p>{log.model_metadata.prompt_tokens.toLocaleString()}</p>
                </div>
              )}
              {log.model_metadata.completion_tokens !== undefined && (
                <div>
                  <p className="text-muted-foreground">Completion Tokens</p>
                  <p>{log.model_metadata.completion_tokens.toLocaleString()}</p>
                </div>
              )}
              {log.model_metadata.total_cost !== undefined && (
                <div>
                  <p className="text-muted-foreground">ค่าใช้จ่าย</p>
                  <p>${log.model_metadata.total_cost.toFixed(4)}</p>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function ScoreCard({ metric, score }: { metric: string; score: number }) {
  const percentage = Math.round((score || 0) * 100);
  const variant =
    percentage >= 70 ? 'success' : percentage >= 50 ? 'warning' : 'destructive';

  return (
    <div className="text-center p-3 border rounded-lg">
      <p className="text-xs text-muted-foreground capitalize mb-1">
        {metric.replace('_', ' ')}
      </p>
      <p
        className={cn(
          'text-xl font-bold',
          variant === 'success' && 'text-green-600',
          variant === 'warning' && 'text-yellow-600',
          variant === 'destructive' && 'text-red-600'
        )}
      >
        {percentage}%
      </p>
    </div>
  );
}

