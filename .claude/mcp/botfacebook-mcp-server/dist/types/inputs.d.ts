/**
 * Input types for BotFacebook MCP Server tools
 */
export type DiagnoseAction = "all" | "backend" | "frontend" | "database" | "queue" | "cache" | "api_keys" | "routes" | "logs" | "railway";
export interface DiagnoseInput {
    action: DiagnoseAction;
    target?: string;
    verbose?: boolean;
    lines?: number;
}
export type FixAction = "restart_queue" | "clear_cache" | "clear_routes" | "clear_views" | "clear_config" | "clear_all" | "migrate" | "migrate_fresh" | "seed" | "rebuild_frontend" | "optimize" | "reindex_kb";
export interface FixInput {
    action: FixAction;
    target?: string;
    confirm?: boolean;
    force?: boolean;
}
export type BotManageAction = "list_bots" | "get_bot" | "create_bot" | "update_bot" | "delete_bot" | "test_bot" | "test_line" | "list_flows" | "get_flow" | "create_flow" | "update_flow" | "delete_flow" | "duplicate_flow" | "set_default_flow" | "test_flow" | "get_kb" | "search_kb" | "list_documents" | "upload_document" | "delete_document" | "reprocess_document" | "list_conversations" | "get_conversation" | "send_agent_message" | "toggle_handover" | "close_conversation";
export interface BotManageInput {
    action: BotManageAction;
    bot_id?: number;
    flow_id?: number;
    kb_id?: number;
    conversation_id?: number;
    document_id?: number;
    data?: Record<string, unknown>;
    query?: string;
    message?: string;
    limit?: number;
    page?: number;
}
export type EvaluateAction = "list" | "create" | "show" | "progress" | "test_cases" | "test_case_detail" | "report" | "cancel" | "retry" | "compare" | "personas";
export interface EvaluationConfig {
    flow_id: number;
    name?: string;
    description?: string;
    personas?: string[];
    generator_model?: string;
    simulator_model?: string;
    judge_model?: string;
    test_count?: number;
    include_multi_turn?: boolean;
    include_edge_cases?: boolean;
}
export interface EvaluateInput {
    action: EvaluateAction;
    bot_id: number;
    evaluation_id?: number;
    test_case_id?: number;
    config?: EvaluationConfig;
    evaluation_ids?: number[];
    page?: number;
    per_page?: number;
    status?: string;
}
export type ExecuteAction = "cost_summary" | "cost_by_bot" | "cost_by_model" | "check_api_keys" | "rotate_webhook" | "list_tokens" | "revoke_token" | "deploy_backend" | "deploy_frontend" | "railway_logs" | "railway_status" | "run_e2e" | "run_unit" | "test_webhook" | "tinker";
export interface ExecuteInput {
    action: ExecuteAction;
    bot_id?: number;
    token_id?: number;
    from_date?: string;
    to_date?: string;
    group_by?: "day" | "week" | "month";
    code?: string;
    service?: "backend" | "frontend" | "reverb";
    lines?: number;
    confirm?: boolean;
}
//# sourceMappingURL=inputs.d.ts.map