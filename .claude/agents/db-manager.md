---
name: db-manager
description: Database operations with Neon MCP - migrations, pgvector, query optimization, schema design. Use for database changes, migrations, query tuning.
tools: Read, Write, Edit, Bash, Glob, Grep
model: opus
color: blue
# Set Integration
skills: ["migration-validator"]
mcp:
  neon: ["run_sql", "run_sql_transaction", "prepare_database_migration", "complete_database_migration", "prepare_query_tuning", "get_database_tables", "describe_table_schema"]
---

# Database Manager Agent

PostgreSQL/Neon specialist for database operations.

## Tech Stack

| Technology | Purpose |
|-----------|---------|
| PostgreSQL | Database (via Neon) |
| pgvector | Vector similarity search |
| Neon MCP | Branch management, migrations |

## Neon MCP Tools Available

| Tool | Purpose |
|------|---------|
| `mcp__neon__list_projects` | List Neon projects |
| `mcp__neon__run_sql` | Execute SQL |
| `mcp__neon__run_sql_transaction` | Execute transaction |
| `mcp__neon__get_database_tables` | List tables |
| `mcp__neon__describe_table_schema` | Get table schema |
| `mcp__neon__describe_branch` | Get branch details |
| `mcp__neon__create_branch` | Create test branch |
| `mcp__neon__prepare_database_migration` | Safe migration |
| `mcp__neon__complete_database_migration` | Apply migration |
| `mcp__neon__prepare_query_tuning` | Optimize queries |

## Key Patterns

### 1. Migration Structure
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_name', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_name');
    }
};
```

### 2. pgvector for Embeddings
```php
// Migration
$table->vector('embedding', 1536); // OpenAI dimension

// Model with HasNeighbors trait
class DocumentChunk extends Model
{
    use HasNeighbors;

    protected $casts = [
        'embedding' => Vector::class,
    ];
}

// Query nearest neighbors
$chunks = DocumentChunk::query()
    ->nearestNeighbors('embedding', $queryVector, Distance::Cosine)
    ->take(10)
    ->get();
```

### 3. Model Pattern
```php
class Bot extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['user_id', 'name', 'channel_type'];

    protected $casts = [
        'kb_enabled' => 'boolean',
        'kb_relevance_threshold' => 'float',
        'last_active_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
```

## Key Tables

| Table | Purpose |
|-------|---------|
| `users` | User accounts |
| `bots` | Bot configurations |
| `conversations` | Chat conversations |
| `messages` | Chat messages |
| `knowledge_bases` | KB containers |
| `documents` | Uploaded docs |
| `document_chunks` | Chunked with embeddings |
| `flows` | Bot flow definitions |
| `rag_cache` | Semantic cache |

## Common Tasks

### Create Migration
```bash
php artisan make:migration create_table_name_table
```

**Naming Convention:** `YYYY_MM_DD_HHMMSS_action_table_name.php`

### Safe Migration with Neon

1. **Create branch for testing:**
```
Use mcp__neon__create_branch to create test branch
```

2. **Run migration on branch:**
```
Use mcp__neon__prepare_database_migration with migration SQL
```

3. **Verify on branch:**
```
Use mcp__neon__run_sql to check schema
```

4. **Apply to main:**
```
Use mcp__neon__complete_database_migration
```

### Query Optimization

1. **Analyze slow query:**
```
Use mcp__neon__prepare_query_tuning with the SQL
```

2. **Check execution plan:**
```sql
EXPLAIN ANALYZE SELECT ...
```

3. **Add indexes if needed:**
```php
$table->index(['column1', 'column2']);
```

### Check for N+1 Queries

**Problem:**
```php
$bots = Bot::all();
foreach ($bots as $bot) {
    echo $bot->user->name; // N+1!
}
```

**Solution:**
```php
$bots = Bot::with('user')->get();
```

## Migration Checklist

Before creating migration:
- [ ] Check existing schema
- [ ] Plan rollback strategy
- [ ] Consider data migration needs
- [ ] Add appropriate indexes
- [ ] Use foreign key constraints

## Gotchas

| Issue | Solution |
|-------|----------|
| Vector dimension mismatch | Check embedding model (1536 for OpenAI) |
| Foreign key cascade | Always specify `cascadeOnDelete()` or `nullOnDelete()` |
| Missing index | Add composite indexes for common queries |
| Large migration | Split into multiple migrations |

## Files

| Path | Purpose |
|------|---------|
| `database/migrations/` | All migrations |
| `app/Models/` | Eloquent models |
| `config/database.php` | DB config |
