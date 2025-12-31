/**
 * BotFacebook MCP Server - Main Server Class
 */
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { CallToolRequestSchema, ListToolsRequestSchema, } from "@modelcontextprotocol/sdk/types.js";
import { loadConfig } from "./utils/config.js";
import { getToolDefinitions } from "./tools/index.js";
import { handleDiagnose } from "./tools/diagnose.js";
import { handleFix } from "./tools/fix.js";
import { handleBotManage } from "./tools/bot-manage.js";
import { handleEvaluate } from "./tools/evaluate.js";
import { handleExecute } from "./tools/execute.js";
export class BotFacebookMCPServer {
    server;
    config;
    constructor() {
        this.config = loadConfig();
        this.server = new Server({
            name: "botfacebook-mcp-server",
            version: "1.0.0",
        }, {
            capabilities: {
                tools: {},
            },
        });
        this.setupHandlers();
    }
    setupHandlers() {
        // List available tools
        this.server.setRequestHandler(ListToolsRequestSchema, async () => {
            return {
                tools: getToolDefinitions(),
            };
        });
        // Handle tool calls
        this.server.setRequestHandler(CallToolRequestSchema, async (request) => {
            const { name, arguments: args } = request.params;
            try {
                const result = await this.handleToolCall(name, args);
                return {
                    content: [
                        {
                            type: "text",
                            text: JSON.stringify(result, null, 2),
                        },
                    ],
                };
            }
            catch (error) {
                const errorMessage = error instanceof Error ? error.message : String(error);
                return {
                    content: [
                        {
                            type: "text",
                            text: JSON.stringify({
                                success: false,
                                error: errorMessage,
                                timestamp: new Date().toISOString(),
                            }),
                        },
                    ],
                    isError: true,
                };
            }
        });
    }
    async handleToolCall(name, args) {
        switch (name) {
            case "diagnose":
                return handleDiagnose(args, this.config);
            case "fix":
                return handleFix(args, this.config);
            case "bot_manage":
                return handleBotManage(args, this.config);
            case "evaluate":
                return handleEvaluate(args, this.config);
            case "execute":
                return handleExecute(args, this.config);
            default:
                throw new Error(`Unknown tool: ${name}`);
        }
    }
    async run() {
        const transport = new StdioServerTransport();
        await this.server.connect(transport);
        console.error("BotFacebook MCP Server running on stdio");
    }
}
//# sourceMappingURL=server.js.map