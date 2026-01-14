# Database Refactoring Guide

PostgreSQL + Neon + pgvector refactoring patterns.

## Add Missing Index

### Identify Slow Query
```sql
-- Check slow queries
SELECT query, calls, mean_time, total_time
FROM pg_stat_statements
ORDER BY mean_time DESC
LIMIT 10;
```

### Before (Missing Index)
```php
// Slow query - full table scan
Bot::where('user_id', $userId)
    ->where('platform', 'line')
    ->where('is_active', true)
    ->get();
```

### After (Add Index Migration)
```php
// Migration
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->index(['user_id', 'platform', 'is_active'], 'bots_user_platform_active_idx');
    });
}

public function down(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->dropIndex('bots_user_platform_active_idx');
    });
}
```

## Optimize N+1 with Eager Loading

### Before (N+1 Problem)
```php
// Controller
$bots = Bot::where('user_id', $userId)->get();

// View - N+1 queries
@foreach ($bots as $bot)
    {{ $bot->user->name }}          // Query per bot
    {{ $bot->settings->language }}  // Query per bot
    {{ $bot->conversations->count() }} // Query per bot
@endforeach
```

### After (Eager Loading)
```php
// Controller
$bots = Bot::where('user_id', $userId)
    ->with(['user', 'settings'])
    ->withCount('conversations')
    ->get();

// View - No additional queries
@foreach ($bots as $bot)
    {{ $bot->user->name }}
    {{ $bot->settings->language }}
    {{ $bot->conversations_count }}
@endforeach
```

## Split Large Table

### Before (Monolithic Table)
```php
// One huge table with 50+ columns
Schema::create('bots', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    // ... 20 frequently accessed columns
    // ... 30 rarely accessed settings columns
});
```

### After (Split Tables)
```php
// Main table - frequently accessed
Schema::create('bots', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->foreignId('user_id');
    $table->string('platform');
    $table->boolean('is_active');
    $table->timestamps();
});

// Settings table - less frequent
Schema::create('bot_settings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
    $table->string('language')->default('th');
    $table->json('ai_config')->nullable();
    $table->json('notification_settings')->nullable();
    // ... other settings
    $table->timestamps();
});
```

## Add Computed Column

### Before (Calculate on Query)
```php
// Slow - calculates every query
$bots = Bot::withCount('conversations')
    ->withCount('messages')
    ->having('conversations_count', '>', 10)
    ->get();
```

### After (Computed Column + Trigger)
```php
// Migration - add computed column
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->unsignedInteger('conversation_count')->default(0);
        $table->unsignedInteger('message_count')->default(0);
    });

    // Add trigger to update counts
    DB::unprepared('
        CREATE OR REPLACE FUNCTION update_bot_conversation_count()
        RETURNS TRIGGER AS $$
        BEGIN
            IF TG_OP = \'INSERT\' THEN
                UPDATE bots SET conversation_count = conversation_count + 1
                WHERE id = NEW.bot_id;
            ELSIF TG_OP = \'DELETE\' THEN
                UPDATE bots SET conversation_count = conversation_count - 1
                WHERE id = OLD.bot_id;
            END IF;
            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER conversation_count_trigger
        AFTER INSERT OR DELETE ON conversations
        FOR EACH ROW EXECUTE FUNCTION update_bot_conversation_count();
    ');
}

// Fast query
$bots = Bot::where('conversation_count', '>', 10)->get();
```

## Normalize Repeated Data

### Before (Denormalized)
```php
Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->string('sender_name');      // Repeated
    $table->string('sender_avatar');    // Repeated
    $table->string('sender_platform');  // Repeated
    $table->text('content');
});
```

### After (Normalized)
```php
// Senders table
Schema::create('senders', function (Blueprint $table) {
    $table->id();
    $table->string('platform_id')->unique();
    $table->string('name');
    $table->string('avatar')->nullable();
    $table->string('platform');
    $table->timestamps();
});

// Messages reference sender
Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sender_id')->constrained();
    $table->text('content');
    $table->timestamps();
});
```

## Optimize JSON Queries

### Before (Slow JSON Query)
```php
// Slow - scans all JSON
Bot::whereJsonContains('settings->features', 'auto-reply')->get();
```

### After (Generated Column + Index)
```php
// Migration
public function up(): void
{
    // Add generated column
    DB::statement("
        ALTER TABLE bots
        ADD COLUMN has_auto_reply boolean
        GENERATED ALWAYS AS ((settings->'features')::jsonb ? 'auto-reply') STORED
    ");

    // Index the generated column
    Schema::table('bots', function (Blueprint $table) {
        $table->index('has_auto_reply');
    });
}

// Fast query
Bot::where('has_auto_reply', true)->get();
```

## Partition Large Table

### Before (Single Large Table)
```php
// 100M+ rows, slow queries
Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('bot_id');
    $table->text('content');
    $table->timestamp('created_at');
});
```

### After (Partitioned Table)
```php
// Migration using raw SQL for partitioning
public function up(): void
{
    DB::statement("
        CREATE TABLE messages (
            id BIGSERIAL,
            bot_id BIGINT NOT NULL,
            content TEXT,
            created_at TIMESTAMP NOT NULL,
            PRIMARY KEY (id, created_at)
        ) PARTITION BY RANGE (created_at);
    ");

    // Create monthly partitions
    DB::statement("
        CREATE TABLE messages_2026_01 PARTITION OF messages
        FOR VALUES FROM ('2026-01-01') TO ('2026-02-01');
    ");

    DB::statement("
        CREATE TABLE messages_2026_02 PARTITION OF messages
        FOR VALUES FROM ('2026-02-01') TO ('2026-03-01');
    ");
}
```

## pgvector Index Optimization

### Before (Slow Vector Search)
```php
// No index - sequential scan
$results = DB::select("
    SELECT * FROM knowledge_chunks
    ORDER BY embedding <=> ?
    LIMIT 10
", [$queryVector]);
```

### After (HNSW Index)
```php
// Migration - add HNSW index
public function up(): void
{
    DB::statement("
        CREATE INDEX knowledge_chunks_embedding_idx
        ON knowledge_chunks
        USING hnsw (embedding vector_cosine_ops)
        WITH (m = 16, ef_construction = 64)
    ");
}

// Query uses index automatically
$results = DB::select("
    SELECT * FROM knowledge_chunks
    ORDER BY embedding <=> ?
    LIMIT 10
", [$queryVector]);
```

## Safe Migration Workflow

```bash
# 1. Create migration
php artisan make:migration add_index_to_bots_table

# 2. Write migration with down() method
# 3. Test locally
php artisan migrate

# 4. Test rollback
php artisan migrate:rollback

# 5. Deploy to staging first
# 6. Monitor query performance
# 7. Deploy to production
```

## Checklist Before DB Refactor

- [ ] Backup database
- [ ] Test migration locally
- [ ] Test rollback works
- [ ] Check index size impact
- [ ] Consider table locks
- [ ] Plan maintenance window
- [ ] Monitor after deployment
