/**
 * Bot Manage Tool Implementation
 * Unified interface for Bot, Flow, KB, and Conversation management
 */
import type { BotManageInput } from "../types/inputs.js";
import type { ServerConfig } from "../utils/config.js";
import { type ToolResult } from "../types/common.js";
export declare function handleBotManage(input: BotManageInput, config: ServerConfig): Promise<ToolResult>;
//# sourceMappingURL=bot-manage.d.ts.map