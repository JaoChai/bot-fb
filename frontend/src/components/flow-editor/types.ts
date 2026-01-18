/**
 * Shared types for FlowEditor components
 * Part of 006-bots-refactor feature
 */

export interface FlowFormData {
  // Basic Info
  name: string;
  is_default: boolean;
  system_prompt: string | null;

  // Model Settings
  primary_chat_model: string | null;
  fallback_chat_model: string | null;
  decision_model: string | null;
  fallback_decision_model: string | null;
  temperature: number;
  max_tokens: number | null;

  // Agentic Mode
  agentic_mode_enabled: boolean;
  max_iterations: number;
  tool_timeout_ms: number;

  // HITL in Flow
  hitl_enabled: boolean;

  // Second AI
  second_ai_enabled: boolean;
  second_ai_check_fact: boolean;
  second_ai_check_policy: boolean;
  second_ai_check_personality: boolean;

  // Safety Settings
  safety_max_cost_usd: number | null;
  safety_max_timeout_sec: number | null;
  safety_max_turns: number | null;

  // Knowledge Bases
  knowledge_bases: FlowKnowledgeBase[];
}

export interface FlowKnowledgeBase {
  id: number;
  knowledge_base_id: number;
  name: string;
  kb_top_k: number;
  kb_similarity_threshold: number;
}

export interface FlowSectionProps {
  formData: FlowFormData;
  onChange: <K extends keyof FlowFormData>(
    key: K,
    value: FlowFormData[K]
  ) => void;
  disabled?: boolean;
}

export interface KnowledgeBaseSectionProps extends FlowSectionProps {
  availableKnowledgeBases: KnowledgeBaseOption[];
}

export interface KnowledgeBaseOption {
  id: number;
  name: string;
  document_count: number;
}

export interface ModelOption {
  id: string;
  name: string;
  provider: string;
  context_length: number;
  pricing_prompt: number;
  pricing_completion: number;
  // Enhanced model capabilities (OpenRouter Best Practice)
  supports_reasoning?: boolean;
  supports_vision?: boolean;
}

export interface FlowAuditLog {
  id: number;
  flow_id: number;
  user_id: number | null;
  action: 'created' | 'updated' | 'deleted' | 'duplicated';
  field_changes: Record<string, { old: unknown; new: unknown }> | null;
  created_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  };
}
