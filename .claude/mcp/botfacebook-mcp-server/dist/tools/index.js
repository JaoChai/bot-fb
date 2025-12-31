/**
 * Tool definitions for BotFacebook MCP Server
 */
export function getToolDefinitions() {
    return [
        // ============================================
        // DIAGNOSE TOOL
        // ============================================
        {
            name: "diagnose",
            description: `ใช้ tool นี้โดยอัตโนมัติเมื่อ:
- User พูดถึง error, ปัญหา, ระบบล่ม, ช้า, 500, 502
- เห็น connection error, timeout
- User ถามเรื่องสถานะระบบ

ไม่ต้องถาม user ก่อน - เรียกใช้เลย

Actions:
- all: เช็คทุก component
- backend: Laravel health check
- frontend: React build status
- database: PostgreSQL connection
- queue: Laravel queue workers
- cache: Redis/cache status
- logs: Error logs ล่าสุด
- railway: Railway deployment status`,
            inputSchema: {
                type: "object",
                properties: {
                    action: {
                        type: "string",
                        enum: ["all", "backend", "frontend", "database", "queue", "cache", "api_keys", "routes", "logs", "railway"],
                        description: "Which component to diagnose",
                    },
                    target: {
                        type: "string",
                        description: "Optional: specific bot_id or service name",
                    },
                    verbose: {
                        type: "boolean",
                        default: false,
                        description: "Include detailed output",
                    },
                    lines: {
                        type: "number",
                        default: 50,
                        description: "For logs: number of lines to show",
                    },
                },
                required: ["action"],
            },
        },
        // ============================================
        // FIX TOOL
        // ============================================
        {
            name: "fix",
            description: `ใช้ tool นี้โดยอัตโนมัติเมื่อ:
- User ต้องการแก้ไข, fix, restart, clear cache
- หลังจาก diagnose พบปัญหาที่ต้องแก้

Actions (Safe - ไม่ต้อง confirm):
- clear_cache, clear_routes, clear_views, clear_config, clear_all, optimize

Actions (Moderate - ต้อง confirm: true):
- restart_queue, migrate, rebuild_frontend, reindex_kb

Actions (Dangerous - ต้อง confirm: true):
- migrate_fresh, seed`,
            inputSchema: {
                type: "object",
                properties: {
                    action: {
                        type: "string",
                        enum: [
                            "restart_queue",
                            "clear_cache",
                            "clear_routes",
                            "clear_views",
                            "clear_config",
                            "clear_all",
                            "migrate",
                            "migrate_fresh",
                            "seed",
                            "rebuild_frontend",
                            "optimize",
                            "reindex_kb",
                        ],
                        description: "Which fix action to perform",
                    },
                    target: {
                        type: "string",
                        description: "For reindex_kb: bot_id",
                    },
                    confirm: {
                        type: "boolean",
                        description: "Required for dangerous actions (migrate, migrate_fresh, seed, restart_queue)",
                    },
                    force: {
                        type: "boolean",
                        default: false,
                        description: "Skip safety checks (use with caution)",
                    },
                },
                required: ["action"],
            },
        },
        // ============================================
        // BOT_MANAGE TOOL
        // ============================================
        {
            name: "bot_manage",
            description: `ใช้ tool นี้โดยอัตโนมัติเมื่อ:
- User พูดถึง bot, บอท, KB, knowledge, flow, conversation
- ต้องการ list, create, update, delete, test bot หรือ components

Actions แยกตาม category:
Bot: list_bots, get_bot, create_bot, update_bot, delete_bot, test_bot, test_line
Flow: list_flows, get_flow, create_flow, update_flow, delete_flow, duplicate_flow, set_default_flow, test_flow
KB: get_kb, search_kb, list_documents, upload_document, delete_document, reprocess_document
Conversation: list_conversations, get_conversation, send_agent_message, toggle_handover, close_conversation`,
            inputSchema: {
                type: "object",
                properties: {
                    action: {
                        type: "string",
                        enum: [
                            "list_bots", "get_bot", "create_bot", "update_bot", "delete_bot", "test_bot", "test_line",
                            "list_flows", "get_flow", "create_flow", "update_flow", "delete_flow", "duplicate_flow", "set_default_flow", "test_flow",
                            "get_kb", "search_kb", "list_documents", "upload_document", "delete_document", "reprocess_document",
                            "list_conversations", "get_conversation", "send_agent_message", "toggle_handover", "close_conversation",
                        ],
                        description: "Which bot management action to perform",
                    },
                    bot_id: { type: "number", description: "Bot ID (required for most actions)" },
                    flow_id: { type: "number", description: "Flow ID" },
                    kb_id: { type: "number", description: "Knowledge Base ID" },
                    conversation_id: { type: "number", description: "Conversation ID" },
                    document_id: { type: "number", description: "Document ID" },
                    data: {
                        type: "object",
                        description: "Payload for create/update actions",
                    },
                    query: { type: "string", description: "For search_kb: search query" },
                    message: { type: "string", description: "For test_bot, test_flow, send_agent_message: message to send" },
                    limit: { type: "number", description: "Pagination limit" },
                    page: { type: "number", description: "Pagination page" },
                },
                required: ["action"],
            },
        },
        // ============================================
        // EVALUATE TOOL
        // ============================================
        {
            name: "evaluate",
            description: `ใช้ tool นี้โดยอัตโนมัติเมื่อ:
- User พูดถึง ประเมิน, evaluate, score, test bot quality
- ต้องการดู evaluation results, report

Actions:
- list: List evaluations for a bot
- create: Start new evaluation
- show: Get evaluation details
- progress: Get running evaluation progress
- test_cases: Get test cases
- report: Get evaluation report
- cancel, retry: Manage evaluations
- compare: Compare multiple evaluations
- personas: Get available personas`,
            inputSchema: {
                type: "object",
                properties: {
                    action: {
                        type: "string",
                        enum: ["list", "create", "show", "progress", "test_cases", "test_case_detail", "report", "cancel", "retry", "compare", "personas"],
                        description: "Which evaluation action to perform",
                    },
                    bot_id: { type: "number", description: "Bot ID (required)" },
                    evaluation_id: { type: "number", description: "Evaluation ID" },
                    test_case_id: { type: "number", description: "Test case ID" },
                    config: {
                        type: "object",
                        description: "For create: evaluation configuration",
                        properties: {
                            flow_id: { type: "number", description: "Flow ID to evaluate" },
                            name: { type: "string" },
                            personas: { type: "array", items: { type: "string" } },
                            test_count: { type: "number", minimum: 10, maximum: 100 },
                        },
                    },
                    evaluation_ids: {
                        type: "array",
                        items: { type: "number" },
                        description: "For compare: evaluation IDs to compare",
                    },
                    page: { type: "number" },
                    per_page: { type: "number" },
                },
                required: ["action", "bot_id"],
            },
        },
        // ============================================
        // EXECUTE TOOL
        // ============================================
        {
            name: "execute",
            description: `ใช้ tool นี้สำหรับ actions อื่นๆ:

Cost/Analytics:
- cost_summary: Get cost analytics
- cost_by_bot: Cost breakdown by bot
- cost_by_model: Cost breakdown by model

Security:
- check_api_keys: Validate API keys
- rotate_webhook: Regenerate bot webhook URL
- list_tokens, revoke_token: Manage auth tokens

Railway (Deploy & Management):
- deploy_backend, deploy_frontend: Railway deployment
- railway_logs: Get Railway logs
- railway_status: Check Railway service status
- railway_services: List all services
- railway_variables: Get env variables
- railway_set_variable: Set env variable
- railway_redeploy: Redeploy/restart service

Test:
- run_e2e: Run E2E tests
- run_unit: Run unit tests
- test_webhook: Test LINE webhook

Tinker:
- tinker: Execute PHP code in Laravel context`,
            inputSchema: {
                type: "object",
                properties: {
                    action: {
                        type: "string",
                        enum: [
                            "cost_summary", "cost_by_bot", "cost_by_model",
                            "check_api_keys", "rotate_webhook", "list_tokens", "revoke_token",
                            "deploy_backend", "deploy_frontend", "railway_logs", "railway_status",
                            "railway_services", "railway_variables", "railway_set_variable", "railway_redeploy",
                            "run_e2e", "run_unit", "test_webhook",
                            "tinker",
                        ],
                        description: "Which action to execute",
                    },
                    bot_id: { type: "number", description: "Bot ID (for bot-specific actions)" },
                    token_id: { type: "number", description: "Token ID (for revoke_token)" },
                    from_date: { type: "string", format: "date", description: "For cost queries: start date" },
                    to_date: { type: "string", format: "date", description: "For cost queries: end date" },
                    group_by: {
                        type: "string",
                        enum: ["day", "week", "month"],
                        description: "For cost queries: grouping",
                    },
                    code: { type: "string", description: "For tinker: PHP code to execute" },
                    service: {
                        type: "string",
                        enum: ["backend", "frontend", "reverb"],
                        description: "For railway_logs, railway_variables, railway_redeploy: which service",
                    },
                    lines: { type: "number", default: 100, description: "For railway_logs: number of lines" },
                    confirm: { type: "boolean", description: "Required for dangerous actions (deploy, revoke_token)" },
                    variable_name: { type: "string", description: "For railway_set_variable: variable name" },
                    variable_value: { type: "string", description: "For railway_set_variable: variable value" },
                },
                required: ["action"],
            },
        },
    ];
}
//# sourceMappingURL=index.js.map