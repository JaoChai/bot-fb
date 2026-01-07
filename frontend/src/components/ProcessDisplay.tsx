import { useState, useMemo, memo } from 'react';
import {
  ChevronDown,
  ChevronUp,
  Activity,
  Brain,
  Search,
  MessageSquare,
  AlertTriangle,
  CheckCircle2,
  SkipForward,
  RefreshCw,
  Loader2,
  Clock,
  Zap,
  Wrench,
  Bot,
  Sparkles,
  Calculator,
  ShieldAlert,
  XCircle,
  Timer,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { ProcessLog, DoneSummary } from '@/hooks/useStreamingChat';

interface ProcessDisplayProps {
  logs: ProcessLog[];
  summary?: DoneSummary;
  isStreaming?: boolean;
}

// Get icon for each event type
function getEventIcon(event: string, data?: Record<string, unknown>) {
  switch (event) {
    case 'process_start':
      return <Activity className="h-3.5 w-3.5 text-foreground" />;
    case 'decision_start':
    case 'decision_result':
      return <Brain className="h-3.5 w-3.5 text-foreground" />;
    case 'decision_skip':
      return <SkipForward className="h-3.5 w-3.5 text-muted-foreground" />;
    case 'decision_fallback':
      return <RefreshCw className="h-3.5 w-3.5 text-muted-foreground" />;
    case 'kb_search':
    case 'kb_result':
      return <Search className="h-3.5 w-3.5 text-foreground" />;
    case 'kb_skip':
      return <SkipForward className="h-3.5 w-3.5 text-muted-foreground" />;
    case 'chat_start':
      return <MessageSquare className="h-3.5 w-3.5 text-foreground" />;
    case 'chat_fallback':
      return <RefreshCw className="h-3.5 w-3.5 text-muted-foreground" />;
    case 'error':
      return <AlertTriangle className="h-3.5 w-3.5 text-destructive" />;
    case 'done':
      return <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />;
    // Agentic Mode events
    case 'agent_start':
      return <Bot className="h-3.5 w-3.5 text-foreground" />;
    case 'agent_thinking':
      return <Sparkles className="h-3.5 w-3.5 text-foreground animate-pulse" />;
    case 'agent_done':
      return <Bot className="h-3.5 w-3.5 text-emerald-500" />;
    case 'agent_error':
    case 'agent_max_iterations':
      return <AlertTriangle className="h-3.5 w-3.5 text-destructive" />;
    case 'agent_fallback':
      return <RefreshCw className="h-3.5 w-3.5 text-muted-foreground" />;
    case 'tool_call': {
      const toolName = data?.tool_name as string;
      if (toolName === 'search_knowledge_base') {
        return <Search className="h-3.5 w-3.5 text-foreground" />;
      }
      if (toolName === 'calculate') {
        return <Calculator className="h-3.5 w-3.5 text-foreground" />;
      }
      if (toolName === 'think') {
        return <Brain className="h-3.5 w-3.5 text-purple-500" />;
      }
      return <Wrench className="h-3.5 w-3.5 text-foreground" />;
    }
    case 'tool_result':
      return <CheckCircle2 className="h-3.5 w-3.5 text-foreground" />;
    // HITL Safety events
    case 'agent_safety_stop':
      return <ShieldAlert className="h-3.5 w-3.5 text-destructive" />;
    case 'agent_approval_required':
      return <Timer className="h-3.5 w-3.5 text-amber-500" />;
    case 'agent_approval_waiting':
      return <Loader2 className="h-3.5 w-3.5 text-amber-500 animate-spin" />;
    case 'agent_approval_response':
      return data?.approved
        ? <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />
        : <XCircle className="h-3.5 w-3.5 text-destructive" />;
    default:
      return <Activity className="h-3.5 w-3.5 text-muted-foreground" />;
  }
}

// Get label for each event type
function getEventLabel(event: string, data?: Record<string, unknown>): string {
  switch (event) {
    case 'process_start':
      return data?.agentic_mode ? '🤖 Agentic Mode' : 'เริ่มประมวลผล';
    case 'decision_start':
      return 'Decision Model กำลังวิเคราะห์...';
    case 'decision_result':
      return 'ผลการวิเคราะห์';
    case 'decision_skip':
      return 'ข้าม Decision Model';
    case 'decision_fallback':
      return 'ใช้ Fallback Decision Model';
    case 'kb_search':
      return 'ค้นหา Knowledge Base...';
    case 'kb_result':
      return 'ผลการค้นหา KB';
    case 'kb_skip':
      return 'ข้าม Knowledge Base';
    case 'chat_start':
      return 'Chat Model กำลังสร้างคำตอบ...';
    case 'chat_fallback':
      return 'ใช้ Fallback Chat Model';
    case 'error':
      return 'ข้อผิดพลาด';
    case 'done':
      return 'เสร็จสิ้น';
    // Agentic Mode events
    case 'agent_start':
      return '🤖 Agent เริ่มทำงาน';
    case 'agent_thinking':
      return `💭 กำลังคิด... (รอบ ${data?.iteration || '?'})`;
    case 'agent_done':
      return '✅ Agent ทำงานเสร็จ';
    case 'agent_error':
      return '⚠️ Agent พบข้อผิดพลาด';
    case 'agent_fallback':
      return '🔄 Fallback to direct response';
    case 'agent_max_iterations':
      return '⚠️ ถึงจำนวนรอบสูงสุด';
    case 'tool_call': {
      const toolName = data?.tool_name as string;
      if (toolName === 'search_knowledge_base') {
        return '🔍 ค้นหาฐานความรู้...';
      }
      if (toolName === 'calculate') {
        return '🧮 คำนวณ...';
      }
      if (toolName === 'think') {
        return '🧠 กำลังคิด...';
      }
      return `🔧 เรียกใช้ Tool: ${toolName || 'unknown'}`;
    }
    case 'tool_result': {
      const status = data?.status as string;
      return status === 'success' ? '✅ ผลลัพธ์ Tool' : '❌ Tool ล้มเหลว';
    }
    // HITL Safety events
    case 'agent_safety_stop':
      return '🛑 Agent หยุดทำงาน (Safety)';
    case 'agent_approval_required':
      return '⏳ รอการอนุมัติจาก User';
    case 'agent_approval_waiting':
      return '⏳ กำลังรอ...';
    case 'agent_approval_response':
      return data?.approved ? '✅ อนุมัติแล้ว' : '❌ ปฏิเสธ';
    default:
      return event;
  }
}

// Format event details
function formatDetails(event: string, data: Record<string, unknown>): string {
  switch (event) {
    case 'decision_start':
      return `Model: ${data.model || 'N/A'}`;

    case 'decision_result':
      return `Intent: ${data.intent} (${Math.round((data.confidence as number || 0) * 100)}%) • ${data.time_ms}ms`;

    case 'decision_skip':
    case 'kb_skip':
      return data.reason as string || '';

    case 'decision_fallback':
    case 'chat_fallback':
      return `${data.original_model} → ${data.fallback_model}`;

    case 'kb_search': {
      const kbs = data.knowledge_bases as Array<{ name: string }> || [];
      return `${kbs.length} KB: ${kbs.map(k => k.name).join(', ')}`;
    }

    case 'kb_result': {
      const results = data.results as Array<{ kb_name: string; chunks_found: number; top_relevance: number }> || [];
      if (results.length === 0) {
        return 'ไม่พบข้อมูลที่เกี่ยวข้อง';
      }
      const totalChunks = data.total_chunks as number || 0;
      const topRelevance = results.length > 0 ? Math.max(...results.map(r => r.top_relevance)) : 0;
      return `พบ ${totalChunks} chunks (relevance: ${topRelevance}%) • ${data.time_ms}ms`;
    }

    case 'chat_start':
      return `Model: ${data.model || 'N/A'}${data.has_kb_context ? ' + KB Context' : ''}`;

    case 'error':
      return data.message as string || 'Unknown error';

    case 'done': {
      const timeMs = data.total_time_ms as number || 0;
      const promptTokens = data.prompt_tokens as number || 0;
      const completionTokens = data.completion_tokens as number || 0;
      const toolCalls = data.tool_calls as number || 0;
      let result = `รวม ${(timeMs / 1000).toFixed(1)}s • Tokens: ${promptTokens + completionTokens}`;
      if (toolCalls > 0) {
        result += ` • Tools: ${toolCalls}`;
      }
      return result;
    }

    // Agentic Mode events
    case 'agent_start': {
      const tools = data.tools as string[] || [];
      return `Model: ${data.model || 'N/A'} • Max: ${data.max_iterations || '?'} รอบ • Tools: ${tools.length}`;
    }

    case 'agent_thinking':
      return '';

    case 'agent_done':
      return `${data.iterations || 0} รอบ • ${data.total_tool_calls || 0} tool calls`;

    case 'agent_error':
      return data.error as string || 'Unknown error';

    case 'agent_fallback':
      return data.reason as string || '';

    case 'agent_max_iterations':
      return data.message as string || '';

    case 'tool_call': {
      const args = data.arguments as Record<string, unknown> || {};
      const argStr = Object.entries(args)
        .map(([k, v]) => `${k}: ${JSON.stringify(v).slice(0, 30)}`)
        .join(', ');
      return argStr || '';
    }

    case 'tool_result': {
      const preview = data.result_preview as string || '';
      const timeMs = data.time_ms as number || 0;
      return `${preview.slice(0, 100)}${preview.length > 100 ? '...' : ''} • ${timeMs}ms`;
    }

    // HITL Safety events
    case 'agent_safety_stop': {
      const message = data.message ? ` - ${data.message}` : '';
      return `หยุดเนื่องจาก: ${data.type || 'unknown'}${message}`;
    }

    case 'agent_approval_required':
      return `Tool: ${data.tool_name || 'unknown'} (timeout: ${data.timeout_seconds || 60}s)`;

    case 'agent_approval_waiting':
      return `รอแล้ว ${data.elapsed_seconds || 0}/${data.timeout_seconds || 60} วินาที`;

    case 'agent_approval_response':
      return data.reason as string || (data.approved ? 'อนุมัติ' : 'ปฏิเสธ');

    default:
      return '';
  }
}

// Get background color for each event type
function getEventBgColor(event: string, data?: Record<string, unknown>): string {
  switch (event) {
    case 'error':
    case 'agent_error':
    case 'agent_safety_stop':
      return 'bg-destructive/10 border-destructive/20';
    case 'done':
    case 'agent_done':
      return 'bg-emerald-500/10 border-emerald-500/20';
    case 'agent_approval_required':
    case 'agent_approval_waiting':
      return 'bg-amber-500/10 border-amber-500/20';
    case 'agent_approval_response':
      return data?.approved
        ? 'bg-emerald-500/10 border-emerald-500/20'
        : 'bg-destructive/10 border-destructive/20';
    default:
      return 'bg-muted/50 border-border';
  }
}

// Memoized individual log item
const ProcessLogItem = memo(function ProcessLogItem({
  log,
}: {
  log: ProcessLog;
}) {
  return (
    <div
      className={`flex items-start gap-2 p-2 rounded-md border text-xs ${getEventBgColor(log.event, log.data)}`}
    >
      <div className="mt-0.5 shrink-0">
        {getEventIcon(log.event, log.data)}
      </div>
      <div className="flex-1 min-w-0">
        <div className="font-medium text-foreground">
          {getEventLabel(log.event, log.data)}
        </div>
        {formatDetails(log.event, log.data) && (
          <div className="text-muted-foreground mt-0.5 truncate">
            {formatDetails(log.event, log.data)}
          </div>
        )}
      </div>
      {typeof log.data.time_ms === 'number' && log.event !== 'done' && (
        <div className="flex items-center gap-1 text-muted-foreground shrink-0">
          <Clock className="h-3 w-3" />
          <span>{log.data.time_ms}ms</span>
        </div>
      )}
    </div>
  );
});

export const ProcessDisplay = memo(function ProcessDisplay({
  logs,
  summary,
  isStreaming,
}: ProcessDisplayProps) {
  const [isExpanded, setIsExpanded] = useState(true);

  // Don't render if no logs and not streaming
  if (logs.length === 0 && !isStreaming) return null;

  // Memoize filtered logs to prevent re-filtering on every render
  const displayLogs = useMemo(
    () => logs.filter((log) => log.event !== 'process_start'),
    [logs]
  );

  return (
    <div className="mb-2">
      <Button
        variant="ghost"
        size="sm"
        className="w-full justify-between text-xs text-muted-foreground hover:bg-muted/50 px-2 py-1 h-auto"
        onClick={() => setIsExpanded(!isExpanded)}
      >
        <div className="flex items-center gap-1.5">
          <Zap className="h-3 w-3 text-foreground" />
          <span>กระบวนการทำงาน</span>
          {isStreaming && (
            <Loader2 className="h-3 w-3 animate-spin text-foreground" />
          )}
          {summary && (
            <span className="text-foreground font-medium">
              ({(summary.total_time_ms / 1000).toFixed(1)}s)
            </span>
          )}
        </div>
        {isExpanded ? (
          <ChevronUp className="h-3 w-3" />
        ) : (
          <ChevronDown className="h-3 w-3" />
        )}
      </Button>

      {isExpanded && (
        <div className="mt-1 space-y-1">
          {displayLogs.map((log) => (
            <ProcessLogItem key={log.id} log={log} />
          ))}

          {/* Streaming indicator */}
          {isStreaming && displayLogs.length === 0 && (
            <div className="flex items-center gap-2 p-2 rounded-md bg-muted/50 text-xs">
              <Loader2 className="h-3.5 w-3.5 animate-spin text-foreground" />
              <span className="text-muted-foreground">กำลังประมวลผล...</span>
            </div>
          )}

          {/* Summary */}
          {summary && (
            <div className="flex items-center justify-between p-2 rounded-md bg-emerald-500/10 border border-emerald-500/20 text-xs">
              <div className="flex items-center gap-2">
                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />
                <span className="text-emerald-600 dark:text-emerald-400 font-medium">
                  เสร็จสิ้น
                </span>
              </div>
              <div className="flex items-center gap-3 text-muted-foreground">
                <span>
                  <Clock className="h-3 w-3 inline mr-1" />
                  {(summary.total_time_ms / 1000).toFixed(1)}s
                </span>
                <span>
                  Tokens: {summary.prompt_tokens + summary.completion_tokens}
                </span>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
});
