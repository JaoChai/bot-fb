// QA Bot Inspector Types

/**
 * Deep partial utility type for nested objects
 */
export type DeepPartial<T> = {
  [P in keyof T]?: T[P] extends object ? DeepPartial<T[P]> : T[P];
};

/**
 * Model configuration for each layer of QA Inspector
 */
export interface QAInspectorModelConfig {
  primary: string;
  fallback: string;
}

/**
 * QA Inspector settings configuration stored in Bot
 */
export interface QAInspectorSettings {
  qa_inspector_enabled: boolean;
  models: {
    realtime: QAInspectorModelConfig;
    analysis: QAInspectorModelConfig;
    report: QAInspectorModelConfig;
  };
  settings: {
    score_threshold: number;
    sampling_rate: number;
    report_schedule: string;
  };
  notifications: {
    email: boolean;
    alert: boolean;
    slack: boolean;
  };
}

/**
 * Evaluation scores for each metric (0.00-1.00)
 */
export interface QAEvaluationScores {
  answer_relevancy: number;
  faithfulness: number;
  role_adherence: number;
  context_precision: number;
  task_completion: number;
}

/**
 * Issue details from Layer 2 analysis
 */
export interface QAIssueDetails {
  analysis_model: string;
  analysis_timestamp: string;
  root_cause: string;
  prompt_section_identified: string | null;
  similar_issues_count: number;
  severity: 'low' | 'medium' | 'high' | 'critical';
  confidence: number;
}

/**
 * Model metadata for tracking LLM usage
 */
export interface QAModelMetadata {
  realtime_model: string;
  analysis_model?: string;
  prompt_tokens: number;
  completion_tokens: number;
  total_cost: number;
}

/**
 * Knowledge base chunk used in RAG context
 */
export interface QAKBChunk {
  chunk_id: number;
  document_id: number;
  document_name: string;
  content: string;
  similarity: number;
}

/**
 * Real-time evaluation log for each conversation turn
 */
export interface QAEvaluationLog {
  id: number;
  bot_id: number;
  conversation_id: number;
  message_id: number;
  flow_id: number | null;
  scores: QAEvaluationScores;
  overall_score: number;
  is_flagged: boolean;
  issue_type: string | null;
  issue_details: QAIssueDetails | null;
  user_question: string;
  bot_response: string;
  system_prompt_used: string | null;
  kb_chunks_used: QAKBChunk[] | null;
  model_metadata: QAModelMetadata | null;
  evaluated_at: string;
  created_at: string;
  updated_at: string;
}

/**
 * Score distribution for weekly reports
 */
export interface QAScoreDistribution {
  excellent: number;  // 90-100
  good: number;       // 70-89
  needs_improvement: number; // 50-69
  poor: number;       // 0-49
}

/**
 * Metric averages for performance summary
 */
export interface QAMetricAverages {
  answer_relevancy: number;
  faithfulness: number;
  role_adherence: number;
  context_precision: number;
  task_completion: number;
}

/**
 * Performance summary for weekly report
 */
export interface QAPerformanceSummary {
  total_conversations: number;
  total_evaluated: number;
  total_flagged: number;
  error_rate: number;
  average_score: number;
  score_trend: string;
  score_distribution: QAScoreDistribution;
  metric_averages: QAMetricAverages;
}

/**
 * Example conversation for issue analysis
 */
export interface QAIssueExample {
  evaluation_log_id: number;
  user_question: string;
  bot_response: string;
  expected: string;
}

/**
 * Top issue in weekly report
 */
export interface QATopIssue {
  rank: number;
  issue_type: string;
  count: number;
  percentage: number;
  pattern: string;
  prompt_section: string | null;
  example_conversations: QAIssueExample[];
  root_cause: string;
}

/**
 * Prompt suggestion for improvement
 */
export interface QAPromptSuggestion {
  priority: number;
  section: string;
  line_range: string | null;
  issue_addressed: string;
  expected_impact: string;
  before: string;
  after: string;
  applied: boolean;
  applied_at: string | null;
}

/**
 * Weekly report status
 */
export type QAReportStatus = 'generating' | 'completed' | 'failed';

/**
 * Weekly QA report
 */
export interface QAWeeklyReport {
  id: number;
  bot_id: number;
  week_start: string;
  week_end: string;
  status: QAReportStatus;
  performance_summary: QAPerformanceSummary;
  top_issues: QATopIssue[];
  prompt_suggestions: QAPromptSuggestion[];
  total_conversations: number;
  total_flagged: number;
  average_score: number;
  previous_average_score: number | null;
  generation_cost: number | null;
  generated_at: string | null;
  notification_sent: boolean;
  created_at: string;
  updated_at: string;
}

/**
 * API request to update QA Inspector settings
 */
export interface UpdateQAInspectorSettingsData {
  qa_inspector_enabled?: boolean;
  qa_realtime_model?: string;
  qa_realtime_fallback_model?: string;
  qa_analysis_model?: string;
  qa_analysis_fallback_model?: string;
  qa_report_model?: string;
  qa_report_fallback_model?: string;
  qa_score_threshold?: number;
  qa_sampling_rate?: number;
  qa_report_schedule?: string;
  qa_notifications?: {
    email?: boolean;
    alert?: boolean;
    slack?: boolean;
  };
}

/**
 * Filters for querying evaluation logs
 */
export interface QAEvaluationLogFilters {
  is_flagged?: boolean;
  issue_type?: string;
  min_score?: number;
  max_score?: number;
  from_date?: string;
  to_date?: string;
  per_page?: number;
  page?: number;
}

/**
 * Filters for querying weekly reports
 */
export interface QAWeeklyReportFilters {
  status?: QAReportStatus;
  from_date?: string;
  to_date?: string;
  per_page?: number;
  page?: number;
}

/**
 * Dashboard summary for QA Inspector
 */
export interface QAInspectorDashboard {
  enabled: boolean;
  today_evaluated: number;
  today_flagged: number;
  today_error_rate: number;
  week_average_score: number;
  week_trend: string;
  latest_report: QAWeeklyReport | null;
  recent_issues: QAEvaluationLog[];
}

/**
 * Issue type options for filtering
 */
export type QAIssueType =
  | 'price_error'
  | 'hallucination'
  | 'off_topic'
  | 'inappropriate'
  | 'incomplete'
  | 'wrong_context'
  | 'policy_violation'
  | 'other';

/**
 * Report schedule options
 */
export type QAReportSchedule =
  | 'monday_00:00'
  | 'monday_09:00'
  | 'friday_18:00'
  | 'sunday_00:00';

/**
 * Stats summary data
 */
export interface QAStatsSummary {
  total_evaluated: number;
  total_flagged: number;
  error_rate: number;
  average_score: number;
}

/**
 * Score trend data point
 */
export interface QAScoreTrendPoint {
  date: string;
  average_score: number;
  count: number;
}

/**
 * Issue breakdown item
 */
export interface QAIssueBreakdownItem {
  type: string;
  count: number;
  percentage: number;
}

/**
 * Stats data returned from API
 */
export interface QAStatsData {
  summary: QAStatsSummary;
  score_trend: QAScoreTrendPoint[];
  issue_breakdown: QAIssueBreakdownItem[];
  metric_averages: QAMetricAverages;
}

/**
 * Apply suggestion request data
 */
export interface ApplySuggestionRequest {
  flow_id: number;
  force?: boolean;
}

/**
 * Apply suggestion success response
 */
export interface ApplySuggestionResponse {
  success: boolean;
  message: string;
  flow_id: number;
  suggestion_index: number;
  applied_at: string;
}

/**
 * Apply suggestion conflict response
 */
export interface ApplySuggestionConflict {
  conflict: true;
  message: string;
  expected: string;
  actual: string;
  can_force: boolean;
}
