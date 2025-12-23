# Laravel MCP Server

A Model Context Protocol (MCP) server for Laravel that provides Claude with database schema introspection, route listing, and PHP code execution capabilities.

## Features

- **Database Schema Inspection**: Query database tables, columns, types, and nullability
- **Route Listing**: View all Laravel routes with controllers, methods, and middleware
- **Tinker Execution**: Execute PHP code in Laravel context for debugging and data inspection

## Installation

```bash
npm install
npm run build
```

## Usage

The server is configured in `.mcp.json`:

```json
{
  "mcpServers": {
    "laravel": {
      "command": "node",
      "args": ["/path/to/.claude/mcp/laravel-mcp-server/dist/index.js"],
      "env": {
        "LARAVEL_PATH": "./backend"
      }
    }
  }
}
```

## Tools

### inspect_database_schema

Inspect Laravel database tables and columns.

**Examples:**
- List all tables: `inspect_database_schema` (no arguments)
- Inspect specific table: `inspect_database_schema table="users"`

### list_routes

List all Laravel routes with optional filtering.

**Examples:**
- List all routes: `list_routes` (no arguments)
- Filter routes: `list_routes filter="api"`

### run_tinker

Execute PHP code in Laravel Tinker context.

**Example:**
```
run_tinker command="User::count()"
```

## Environment Variables

- `LARAVEL_PATH`: Path to Laravel project root (default: `./backend`)

## Development

```bash
# Development mode (with hot reload)
npm run dev

# Production build
npm run build

# Run built server
npm start
```

## Security

- Uses `execFile` instead of `exec` to prevent shell injection
- All user input is properly escaped before execution
- Runs in isolated Tinker context

## Architecture

```
src/index.ts
├── LaravelMCPServer (main class)
│   ├── setupHandlers() - Register MCP request handlers
│   ├── getTools() - List available tools
│   ├── handleToolCall() - Route tool calls to handlers
│   ├── inspectDatabaseSchema() - Database introspection
│   ├── listRoutes() - Route listing
│   └── runTinker() - PHP execution
```

## Error Handling

All tools return structured responses:
- Success: Returns `stdout` from Laravel commands
- Failure: Returns descriptive error message with context

## Performance

- Database queries are optimized for large tables
- Route listing uses Laravel's native `route:list` command
- Tinker execution is isolated and timeout-safe
