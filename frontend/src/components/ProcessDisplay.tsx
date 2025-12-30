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
      return <Activity className="h-3.5 w-3.5 text-blue-500" />;
    case 'decision_start':
    case 'decision_result':
      return <Brain className="h-3.5 w-3.5 text-purple-500" />;
    case 'decision_skip':
      return <SkipForward className="h-3.5 w-3.5 text-gray-400" />;
    case 'decision_fallback':
      return <RefreshCw className="h-3.5 w-3.5 text-amber-500" />;
    case 'kb_search':
    case 'kb_result':
      return <Search className="h-3.5 w-3.5 text-emerald-500" />;
    case 'kb_skip':
      return <SkipForward className="h-3.5 w-3.5 text-gray-400" />;
    case 'chat_start':
      return <MessageSquare className="h-3.5 w-3.5 text-blue-500" />;
    case 'chat_fallback':
      return <RefreshCw className="h-3.5 w-3.5 text-amber-500" />;
    case 'error':
      return <AlertTriangle className="h-3.5 w-3.5 text-destructive" />;
    case 'done':
      return <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />;
    // Agentic Mode events
    case 'agent_start':
      return <Bot className="h-3.5 w-3.5 text-indigo-500" />;
    case 'agent_thinking':
      return <Sparkles className="h-3.5 w-3.5 text-indigo-500 animate-pulse" />;
    case 'agent_done':
      return <Bot className="h-3.5 w-3.5 text-emerald-500" />;
    case 'agent_error':
    case 'agent_max_iterations':
      return <AlertTriangle className="h-3.5 w-3.5 text-amber-500" />;
    case 'agent_fallback':
      return <RefreshCw className="h-3.5 w-3.5 text-amber-500" />;
    case 'tool_call': {
      const toolName = data?.tool_name as string;
      if (toolName === 'search_knowledge_base') {
        return <Search className="h-3.5 w-3.5 text-cyan-500" />;
      }
      if (toolName === 'calculate') {
        return <Calculator className="h-3.5 w-3.5 text-cyan-500" />;
      }
      return <Wrench className="h-3.5 w-3.5 text-cyan-500" />;
    }
    case 'tool_result':
      return <CheckCircle2 className="h-3.5 w-3.5 text-cyan-500" />;
    default:
      return <Activity className="h-3.5 w-3.5 text-gray-500" />;
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
      return `🔧 เรียกใช้ Tool: ${toolName || 'unknown'}`;
    }
    case 'tool_result': {
      const status = data?.status as string;
      return status === 'success' ? '✅ ผลลัพธ์ Tool' : '❌ Tool ล้มเหลว';
    }
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

    default:
      return '';
  }
}

// Get background color for each event type
function getEventBgColor(event: string): string {
  switch (event) {
    case 'decision_start':
    case 'decision_result':
      return 'bg-purple-500/10 border-purple-500/20';
    case 'kb_search':
    case 'kb_result':
      return 'bg-emerald-500/10 border-emerald-500/20';
    case 'chat_start':
      return 'bg-blue-500/10 border-blue-500/20';
    case 'decision_fallback':
    case 'chat_fallback':
      return 'bg-amber-500/10 border-amber-500/20';
    case 'decision_skip':
    case 'kb_skip':
      return 'bg-slate-500/10 border-slate-500/20';
    case 'error':
      return 'bg-destructive/10 border-destructive/20';
    case 'done':
      return 'bg-emerald-500/10 border-emerald-500/20';
    // Agentic Mode events
    case 'agent_start':
    case 'agent_thinking':
      return 'bg-indigo-500/10 border-indigo-500/20';
    case 'agent_done':
      return 'bg-emerald-500/10 border-emerald-500/20';
    case 'agent_error':
    case 'agent_fallback':
    case 'agent_max_iterations':
      return 'bg-amber-500/10 border-amber-500/20';
    case 'tool_call':
    case 'tool_result':
      return 'bg-cyan-500/10 border-cyan-500/20';
    default:
      return 'bg-muted/50';
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
      className={`flex items-start gap-2 p-2 rounded-md border text-xs ${getEventBgColor(log.event)}`}
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
          <Zap className="h-3 w-3 text-warning" />
          <span>กระบวนการทำงาน</span>
          {isStreaming && (
            <Loader2 className="h-3 w-3 animate-spin text-warning" />
          )}
          {summary && (
            <span className="text-warning">
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
              <Loader2 className="h-3.5 w-3.5 animate-spin text-warning" />
              <span className="text-muted-foreground">กำลังประมวลผล...</span>
            </div>
          )}

          {/* Summary */}
          {summary && (
            <div className="flex items-center justify-between p-2 rounded-md bg-emerald-500/10 border border-emerald-500/20 text-xs">
              <div className="flex items-center gap-2">
                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />
                <span className="text-emerald-700 dark:text-emerald-400 font-medium">
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
