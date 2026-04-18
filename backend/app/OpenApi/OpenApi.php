<?php

namespace App\OpenApi;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="BotJao API",
 *     description="API for BotJao chatbot platform - manage bots, flows, knowledge bases, and conversations",
 *
 *     @OA\Contact(
 *         email="support@botjao.com",
 *         name="BotJao Support"
 *     ),
 *
 *     @OA\License(
 *         name="Proprietary",
 *         url="https://www.botjao.com/terms"
 *     )
 * )
 *
 * @OA\Server(
 *     url="https://api.botjao.com",
 *     description="Production server"
 * )
 * @OA\Server(
 *     url="http://localhost:8000",
 *     description="Local development server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Enter your Sanctum token"
 * )
 *
 * @OA\Tag(
 *     name="Bots",
 *     description="Bot management endpoints"
 * )
 * @OA\Tag(
 *     name="Flows",
 *     description="Flow management endpoints for bot conversation configuration"
 * )
 * @OA\Tag(
 *     name="Bot Settings",
 *     description="Bot configuration and settings endpoints"
 * )
 *
 * @OA\Schema(
 *     schema="PaginationMeta",
 *     type="object",
 *
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="from", type="integer", example=1),
 *     @OA\Property(property="last_page", type="integer", example=5),
 *     @OA\Property(property="per_page", type="integer", example=15),
 *     @OA\Property(property="to", type="integer", example=15),
 *     @OA\Property(property="total", type="integer", example=75)
 * )
 *
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     type="object",
 *
 *     @OA\Property(property="message", type="string", example="The given data was invalid."),
 *     @OA\Property(
 *         property="errors",
 *         type="object",
 *         additionalProperties=@OA\Property(type="array", @OA\Items(type="string"))
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="Bot",
 *     type="object",
 *     required={"id", "name", "channel_type", "status"},
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="My Support Bot"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="status", type="string", enum={"active", "inactive"}, example="active"),
 *     @OA\Property(property="channel_type", type="string", enum={"line", "telegram", "facebook"}, example="line"),
 *     @OA\Property(property="page_id", type="string", nullable=true),
 *     @OA\Property(property="webhook_url", type="string", example="https://api.botjao.com/api/webhook/abc123"),
 *     @OA\Property(property="channel_access_token", type="string", description="Masked for security", example="********"),
 *     @OA\Property(property="channel_secret", type="string", nullable=true, description="Masked for security", example="********"),
 *     @OA\Property(property="credentials_visible", type="boolean", description="True if current user is owner"),
 *     @OA\Property(property="primary_chat_model", type="string", nullable=true, example="openai/gpt-4o"),
 *     @OA\Property(property="fallback_chat_model", type="string", nullable=true),
 *     @OA\Property(property="decision_model", type="string", nullable=true),
 *     @OA\Property(property="kb_enabled", type="boolean", example=false),
 *     @OA\Property(property="kb_relevance_threshold", type="number", format="float", example=0.7),
 *     @OA\Property(property="kb_max_results", type="integer", example=3),
 *     @OA\Property(property="auto_handover", type="boolean", example=false),
 *     @OA\Property(property="total_conversations", type="integer", example=0),
 *     @OA\Property(property="total_messages", type="integer", example=0),
 *     @OA\Property(property="last_active_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="settings", ref="#/components/schemas/BotSettings", nullable=true),
 *     @OA\Property(property="default_flow", ref="#/components/schemas/Flow", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="Flow",
 *     type="object",
 *     required={"id", "bot_id", "name"},
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="bot_id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Customer Support Flow"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="system_prompt", type="string", nullable=true, description="AI system prompt"),
 *     @OA\Property(property="model", type="string", nullable=true, example="openai/gpt-4o"),
 *     @OA\Property(property="fallback_model", type="string", nullable=true),
 *     @OA\Property(property="decision_model", type="string", nullable=true),
 *     @OA\Property(property="temperature", type="number", format="float", example=0.7),
 *     @OA\Property(property="max_tokens", type="integer", example=2048),
 *     @OA\Property(
 *         property="knowledge_bases",
 *         type="array",
 *
 *         @OA\Items(
 *             type="object",
 *
 *             @OA\Property(property="id", type="integer"),
 *             @OA\Property(property="name", type="string"),
 *             @OA\Property(property="kb_top_k", type="integer"),
 *             @OA\Property(property="kb_similarity_threshold", type="number", format="float")
 *         )
 *     ),
 *     @OA\Property(property="is_default", type="boolean", example=false),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="FlowList",
 *     type="object",
 *     description="Slim flow resource for list endpoints (excludes system_prompt)",
 *     required={"id", "bot_id", "name"},
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="bot_id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Customer Support Flow"),
 *     @OA\Property(property="description", type="string", nullable=true, description="Truncated to 100 chars"),
 *     @OA\Property(property="knowledge_bases_count", type="integer", example=2),
 *     @OA\Property(property="is_default", type="boolean"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="BotSettings",
 *     type="object",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="bot_id", type="integer"),
 *     @OA\Property(property="daily_message_limit", type="integer", example=1000),
 *     @OA\Property(property="per_user_limit", type="integer", example=100),
 *     @OA\Property(property="rate_limit_per_minute", type="integer", example=20),
 *     @OA\Property(property="max_tokens_per_response", type="integer", example=2000),
 *     @OA\Property(property="hitl_enabled", type="boolean", description="Human-in-the-Loop enabled"),
 *     @OA\Property(property="hitl_triggers", type="array", @OA\Items(type="string"), nullable=true),
 *     @OA\Property(property="response_hours_enabled", type="boolean"),
 *     @OA\Property(property="response_hours", type="object", nullable=true, description="Business hours per day of week"),
 *     @OA\Property(property="response_hours_timezone", type="string", example="Asia/Bangkok"),
 *     @OA\Property(property="offline_message", type="string", nullable=true),
 *     @OA\Property(property="welcome_message", type="string", nullable=true),
 *     @OA\Property(property="fallback_message", type="string", nullable=true),
 *     @OA\Property(property="typing_indicator", type="boolean"),
 *     @OA\Property(property="typing_delay_ms", type="integer", example=1000),
 *     @OA\Property(property="content_filter_enabled", type="boolean"),
 *     @OA\Property(property="blocked_keywords", type="array", @OA\Items(type="string"), nullable=true),
 *     @OA\Property(property="analytics_enabled", type="boolean"),
 *     @OA\Property(property="save_conversations", type="boolean"),
 *     @OA\Property(property="language", type="string", enum={"th", "en", "zh", "ja", "ko"}),
 *     @OA\Property(property="response_style", type="string", enum={"professional", "casual", "friendly", "formal"}),
 *     @OA\Property(property="multiple_bubbles_enabled", type="boolean"),
 *     @OA\Property(property="multiple_bubbles_min", type="integer"),
 *     @OA\Property(property="multiple_bubbles_max", type="integer"),
 *     @OA\Property(property="smart_aggregation_enabled", type="boolean"),
 *     @OA\Property(property="smart_min_wait_ms", type="integer"),
 *     @OA\Property(property="smart_max_wait_ms", type="integer"),
 *     @OA\Property(property="auto_assignment_enabled", type="boolean"),
 *     @OA\Property(property="auto_assignment_mode", type="string", enum={"round_robin", "load_balanced"})
 * )
 */
class OpenApi {}
