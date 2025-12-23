#!/usr/bin/env node

import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
  Tool,
} from "@modelcontextprotocol/sdk/types.js";
import { execFile } from "child_process";
import { promisify } from "util";
import path from "path";

const execFileAsync = promisify(execFile);

// Get Laravel path from environment or use default
const LARAVEL_PATH = process.env.LARAVEL_PATH || "./backend";
// Get PHP path from environment or use default
const PHP_PATH = process.env.PHP_PATH || "php";

interface ToolInput {
  table?: string;
  query?: string;
  command?: string;
  filter?: string;
}

class LaravelMCPServer {
  private server: Server;

  constructor() {
    this.server = new Server(
      {
        name: "laravel-mcp-server",
        version: "1.0.0",
      },
      {
        capabilities: {
          tools: {},
        },
      }
    );

    this.setupHandlers();
  }

  private setupHandlers(): void {
    // Handle list tools request
    this.server.setRequestHandler(ListToolsRequestSchema, async () => {
      return {
        tools: this.getTools(),
      };
    });

    // Handle call tool request
    this.server.setRequestHandler(CallToolRequestSchema, async (request) => {
      return await this.handleToolCall(
        request.params.name,
        request.params.arguments as ToolInput
      );
    });
  }

  private getTools(): Tool[] {
    return [
      {
        name: "inspect_database_schema",
        description:
          "Inspect Laravel database schema - get tables, columns, types, and relationships",
        inputSchema: {
          type: "object" as const,
          properties: {
            table: {
              type: "string",
              description: "Optional: specific table name to inspect. If omitted, lists all tables",
            },
          },
        },
      },
      {
        name: "list_routes",
        description:
          "List all Laravel routes with controller, method, and middleware information",
        inputSchema: {
          type: "object" as const,
          properties: {
            filter: {
              type: "string",
              description:
                "Optional: filter routes by name, controller, or HTTP method",
            },
          },
        },
      },
      {
        name: "run_tinker",
        description:
          "Execute PHP code in Laravel Tinker context (for debugging, inspecting data)",
        inputSchema: {
          type: "object" as const,
          properties: {
            command: {
              type: "string",
              description: "PHP code to execute in Tinker",
            },
          },
        },
      },
    ];
  }

  private async handleToolCall(
    toolName: string,
    args: ToolInput
  ): Promise<{ content: { type: string; text: string }[] }> {
    try {
      switch (toolName) {
        case "inspect_database_schema":
          return await this.inspectDatabaseSchema(args.table);
        case "list_routes":
          return await this.listRoutes(args.filter);
        case "run_tinker":
          return await this.runTinker(args.command || "");
        default:
          return {
            content: [
              {
                type: "text",
                text: `Unknown tool: ${toolName}`,
              },
            ],
          };
      }
    } catch (error) {
      return {
        content: [
          {
            type: "text",
            text: `Error: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
      };
    }
  }

  private async inspectDatabaseSchema(
    tableName?: string
  ): Promise<{ content: { type: string; text: string }[] }> {
    try {
      let phpCode: string;

      if (tableName) {
        // Inspect specific table
        phpCode = `
$table = '${tableName.replace(/'/g, "\\'")}';
$columns = DB::getSchemaBuilder()->getConnection()->getSchemaBuilder()->getColumns($table);
echo "Table: $table\\n";
echo str_repeat('-', 60) . "\\n";
foreach ($columns as $col) {
  echo sprintf("  %-25s %-15s %-10s\\n", $col->getName(), $col->getType(), $col->isNullable() ? 'nullable' : 'required');
}
`;
      } else {
        // List all tables
        phpCode = `
$tables = DB::getSchemaBuilder()->getConnection()->getSchemaBuilder()->getTables();
echo "Database Tables:\\n";
echo str_repeat('-', 60) . "\\n";
foreach ($tables as $table) {
  $name = $table['name'];
  $columns = DB::getSchemaBuilder()->getConnection()->getSchemaBuilder()->getColumnListing($name);
  echo sprintf("  %-30s (columns: %d)\\n", $name, count($columns));
}
`;
      }

      const { stdout } = await execFileAsync(
        PHP_PATH,
        ["artisan", "tinker", "--execute", phpCode],
        {
          cwd: LARAVEL_PATH,
          encoding: "utf-8",
        }
      );

      return {
        content: [
          {
            type: "text",
            text:
              stdout ||
              "No schema information available. Ensure database is configured.",
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text",
            text: `Database inspection failed: ${error instanceof Error ? error.message : String(error)}. Make sure the Laravel app is properly configured.`,
          },
        ],
      };
    }
  }

  private async listRoutes(
    filter?: string
  ): Promise<{ content: { type: string; text: string }[] }> {
    try {
      const args = ["route:list"];

      if (filter) {
        args.push("--name=" + filter);
      }

      const { stdout } = await execFileAsync(PHP_PATH, ["artisan", ...args], {
        cwd: LARAVEL_PATH,
        encoding: "utf-8",
      });

      return {
        content: [
          {
            type: "text",
            text: stdout || "No routes found.",
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text",
            text: `Route listing failed: ${error instanceof Error ? error.message : String(error)}. Make sure the Laravel app is properly configured.`,
          },
        ],
      };
    }
  }

  private async runTinker(
    phpCode: string
  ): Promise<{ content: { type: string; text: string }[] }> {
    if (!phpCode.trim()) {
      return {
        content: [
          {
            type: "text",
            text: "Error: No PHP code provided",
          },
        ],
      };
    }

    try {
      const { stdout } = await execFileAsync(
        PHP_PATH,
        ["artisan", "tinker", "--execute", phpCode],
        {
          cwd: LARAVEL_PATH,
          encoding: "utf-8",
        }
      );

      return {
        content: [
          {
            type: "text",
            text: stdout || "(No output)",
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text",
            text: `Tinker execution failed: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
      };
    }
  }

  async run(): Promise<void> {
    const transport = new StdioServerTransport();
    await this.server.connect(transport);
    console.error("Laravel MCP Server running on stdio");
  }
}

const server = new LaravelMCPServer();
server.run().catch((error) => {
  console.error("Failed to start server:", error);
  process.exit(1);
});
