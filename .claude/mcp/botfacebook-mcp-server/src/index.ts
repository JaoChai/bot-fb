#!/usr/bin/env node

/**
 * BotFacebook MCP Server - Entry Point
 *
 * A custom MCP server that provides 5 composite tools for managing
 * the entire BotFacebook project (backend + frontend).
 *
 * Tools:
 * 1. diagnose() - System diagnostics
 * 2. fix() - System repairs
 * 3. bot_manage() - Bot/KB/Flow management
 * 4. evaluate() - Bot evaluation
 * 5. execute() - General actions (cost, deploy, security)
 */

import { BotFacebookMCPServer } from "./server.js";

async function main(): Promise<void> {
  try {
    const server = new BotFacebookMCPServer();
    await server.run();
  } catch (error) {
    console.error("Failed to start BotFacebook MCP Server:", error);
    process.exit(1);
  }
}

main();
