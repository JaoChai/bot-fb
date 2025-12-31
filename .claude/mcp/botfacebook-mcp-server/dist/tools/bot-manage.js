/**
 * Bot Manage Tool Implementation
 * Unified interface for Bot, Flow, KB, and Conversation management
 */
import { createSuccessResult, createErrorResult } from "../types/common.js";
import { LaravelAPIClient } from "../clients/laravel-api.js";
import { validateConfirmation, checkRateLimit, } from "../safety/confirmation.js";
import { validateBotId, validateMessage, validateSearchQuery, validatePagination, } from "../safety/validators.js";
export async function handleBotManage(input, config) {
    const { action, bot_id, flow_id, conversation_id, document_id, data, query, message, limit, page, } = input;
    try {
        // Rate limiting for test actions
        if (action === "test_bot" || action === "test_flow") {
            checkRateLimit("bot_manage", action);
        }
        // Confirmation for delete actions
        if (action.startsWith("delete_")) {
            validateConfirmation("bot_manage", action, input.data?.confirm);
        }
        const client = new LaravelAPIClient(config);
        // Route to appropriate handler
        switch (action) {
            // ============================================
            // BOT ACTIONS
            // ============================================
            case "list_bots": {
                const response = await client.listBots();
                return createSuccessResult(response, "Bots retrieved successfully");
            }
            case "get_bot": {
                const id = validateBotId(bot_id, true);
                const response = await client.getBot(id);
                return createSuccessResult(response, "Bot retrieved");
            }
            case "create_bot": {
                if (!data) {
                    return createErrorResult("data is required for create_bot");
                }
                const response = await client.createBot(data);
                return createSuccessResult(response, "Bot created successfully");
            }
            case "update_bot": {
                const id = validateBotId(bot_id, true);
                if (!data) {
                    return createErrorResult("data is required for update_bot");
                }
                const response = await client.updateBot(id, data);
                return createSuccessResult(response, "Bot updated successfully");
            }
            case "delete_bot": {
                const id = validateBotId(bot_id, true);
                const response = await client.deleteBot(id);
                return createSuccessResult(response, "Bot deleted successfully");
            }
            case "test_bot": {
                const id = validateBotId(bot_id, true);
                const msg = validateMessage(message, true);
                const response = await client.testBot(id, msg);
                return createSuccessResult(response, "Bot test completed");
            }
            case "test_line": {
                const id = validateBotId(bot_id, true);
                const response = await client.get(`/api/bots/${id}/test-line`);
                return createSuccessResult(response, "LINE connection test completed");
            }
            // ============================================
            // FLOW ACTIONS
            // ============================================
            case "list_flows": {
                const id = validateBotId(bot_id, true);
                const response = await client.listFlows(id);
                return createSuccessResult(response, "Flows retrieved");
            }
            case "get_flow": {
                const bId = validateBotId(bot_id, true);
                if (!flow_id) {
                    return createErrorResult("flow_id is required");
                }
                const response = await client.getFlow(bId, flow_id);
                return createSuccessResult(response, "Flow retrieved");
            }
            case "create_flow": {
                const id = validateBotId(bot_id, true);
                if (!data) {
                    return createErrorResult("data is required for create_flow");
                }
                const response = await client.createFlow(id, data);
                return createSuccessResult(response, "Flow created successfully");
            }
            case "update_flow": {
                const bId = validateBotId(bot_id, true);
                if (!flow_id) {
                    return createErrorResult("flow_id is required");
                }
                if (!data) {
                    return createErrorResult("data is required for update_flow");
                }
                const response = await client.updateFlow(bId, flow_id, data);
                return createSuccessResult(response, "Flow updated successfully");
            }
            case "delete_flow": {
                const bId = validateBotId(bot_id, true);
                if (!flow_id) {
                    return createErrorResult("flow_id is required");
                }
                const response = await client.deleteFlow(bId, flow_id);
                return createSuccessResult(response, "Flow deleted successfully");
            }
            case "duplicate_flow": {
                const bId = validateBotId(bot_id, true);
                if (!flow_id) {
                    return createErrorResult("flow_id is required");
                }
                const response = await client.post(`/api/bots/${bId}/flows/${flow_id}/duplicate`);
                return createSuccessResult(response, "Flow duplicated successfully");
            }
            case "set_default_flow": {
                const bId = validateBotId(bot_id, true);
                if (!flow_id) {
                    return createErrorResult("flow_id is required");
                }
                const response = await client.post(`/api/bots/${bId}/flows/${flow_id}/set-default`);
                return createSuccessResult(response, "Default flow set successfully");
            }
            case "test_flow": {
                const bId = validateBotId(bot_id, true);
                if (!flow_id) {
                    return createErrorResult("flow_id is required");
                }
                const msg = validateMessage(message, true);
                const response = await client.testFlow(bId, flow_id, msg);
                return createSuccessResult(response, "Flow test completed");
            }
            // ============================================
            // KNOWLEDGE BASE ACTIONS
            // ============================================
            case "get_kb": {
                const id = validateBotId(bot_id, true);
                const response = await client.getKnowledgeBase(id);
                return createSuccessResult(response, "Knowledge base retrieved");
            }
            case "search_kb": {
                const id = validateBotId(bot_id, true);
                const q = validateSearchQuery(query);
                if (!q) {
                    return createErrorResult("query is required for search_kb");
                }
                const response = await client.searchKnowledgeBase(id, q);
                return createSuccessResult(response, "Knowledge base search completed");
            }
            case "list_documents": {
                const id = validateBotId(bot_id, true);
                const response = await client.listDocuments(id);
                return createSuccessResult(response, "Documents retrieved");
            }
            case "upload_document": {
                return createErrorResult("Document upload requires file handling. Use the web interface or API directly.");
            }
            case "delete_document": {
                const bId = validateBotId(bot_id, true);
                if (!document_id) {
                    return createErrorResult("document_id is required");
                }
                const response = await client.deleteDocument(bId, document_id);
                return createSuccessResult(response, "Document deleted successfully");
            }
            case "reprocess_document": {
                const bId = validateBotId(bot_id, true);
                if (!document_id) {
                    return createErrorResult("document_id is required");
                }
                const response = await client.reprocessDocument(bId, document_id);
                return createSuccessResult(response, "Document queued for reprocessing");
            }
            // ============================================
            // CONVERSATION ACTIONS
            // ============================================
            case "list_conversations": {
                const id = validateBotId(bot_id, true);
                const pagination = validatePagination(page, limit);
                const response = await client.listConversations(id, pagination.page, pagination.limit);
                return createSuccessResult(response, "Conversations retrieved");
            }
            case "get_conversation": {
                const bId = validateBotId(bot_id, true);
                if (!conversation_id) {
                    return createErrorResult("conversation_id is required");
                }
                const response = await client.getConversation(bId, conversation_id);
                return createSuccessResult(response, "Conversation retrieved");
            }
            case "send_agent_message": {
                const bId = validateBotId(bot_id, true);
                if (!conversation_id) {
                    return createErrorResult("conversation_id is required");
                }
                const msg = validateMessage(message, true);
                const response = await client.sendAgentMessage(bId, conversation_id, msg);
                return createSuccessResult(response, "Agent message sent");
            }
            case "toggle_handover": {
                const bId = validateBotId(bot_id, true);
                if (!conversation_id) {
                    return createErrorResult("conversation_id is required");
                }
                const response = await client.toggleHandover(bId, conversation_id);
                return createSuccessResult(response, "Handover toggled");
            }
            case "close_conversation": {
                const bId = validateBotId(bot_id, true);
                if (!conversation_id) {
                    return createErrorResult("conversation_id is required");
                }
                const response = await client.closeConversation(bId, conversation_id);
                return createSuccessResult(response, "Conversation closed");
            }
            default:
                return createErrorResult(`Unknown bot_manage action: ${action}`);
        }
    }
    catch (error) {
        return createErrorResult(error instanceof Error ? error.message : String(error));
    }
}
//# sourceMappingURL=bot-manage.js.map