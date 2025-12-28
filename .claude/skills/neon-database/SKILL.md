---
name: neon-database
description: Query Neon.tech PostgreSQL database, run migrations, debug database issues, and inspect schema. Use when working with database, running migrations, seeing database connection errors, or querying data.
---

# Neon Database Operations

## Quick Access

Use MCP tools for direct database operations:

```
mcp__neon__run_sql          - Execute SQL queries
mcp__neon__get_database_tables - List all tables
mcp__neon__describe_table_schema - Get table structure
mcp__laravel__inspect_database_schema - Laravel-specific schema
mcp__laravel__run_tinker    - Run PHP in Laravel context
```

---

## Common Queries

### Check Tables
```sql
SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'public';
```

### Check Connections (Bots)
```sql
SELECT id, name, page_id, is_active, created_at
FROM bots
ORDER BY created_at DESC
LIMIT 10;
```

### Check Flows
```sql
SELECT id, bot_id, name, is_active, config
FROM flows
WHERE bot_id = 15;
```

### Check Users
```sql
SELECT id, name, email, created_at
FROM users
ORDER BY created_at DESC;
```

---

## Laravel Migrations

```bash
# Run migrations
php artisan migrate

# Rollback last migration
php artisan migrate:rollback

# Check migration status
php artisan migrate:status

# Fresh database (DANGEROUS)
php artisan migrate:fresh --seed
```

---

## Debug Connection Issues

### Check from Laravel
```php
// In tinker
>>> DB::connection()->getPdo()  // Should return PDO object
>>> DB::connection()->getDatabaseName()  // Should return "neondb"
```

### Check Environment
```bash
# Required env vars
DATABASE_URL=postgres://user:pass@host/neondb
DB_CONNECTION=pgsql
DB_HOST=your-host.neon.tech
DB_DATABASE=neondb
```

---

## Common Errors

| Error | Cause | Fix |
|-------|-------|-----|
| Connection refused | Wrong host/credentials | Check DATABASE_URL |
| SSL required | Missing sslmode | Add `?sslmode=require` to URL |
| Relation not found | Migration not run | Run `php artisan migrate` |
| Permission denied | Wrong user role | Check Neon dashboard |

---

## Project Info

| Field | Value |
|-------|-------|
| Provider | Neon.tech |
| Database | PostgreSQL |
| Default DB | neondb |
| SSL | Required |

---

## Schema Quick Reference

### Key Tables
- `users` - User accounts
- `bots` - Facebook page connections
- `flows` - Bot conversation flows
- `documents` - Knowledge base documents
- `document_chunks` - Embedded document segments

### Relationships
```
users 1--* bots
bots 1--* flows
bots 1--* documents
documents 1--* document_chunks
```
