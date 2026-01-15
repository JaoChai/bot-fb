# Database Migration Guide

## Safe Migration Practices

### Pre-Migration Checklist

- [ ] Backup database before migration
- [ ] Test migration on staging first
- [ ] Check for long-running queries that might block
- [ ] Plan rollback strategy
- [ ] Estimate migration time for large tables

### Creating Migrations

```bash
# Create migration
php artisan make:migration create_bots_table
php artisan make:migration add_status_to_bots_table

# Run migrations
php artisan migrate

# Rollback
php artisan migrate:rollback
php artisan migrate:rollback --step=2
```

## Migration Patterns

### Create Table

```php
public function up(): void
{
    Schema::create('bots', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->enum('platform', ['line', 'telegram', 'messenger']);
        $table->text('description')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();

        // Indexes
        $table->index(['user_id', 'is_active']);
        $table->index('platform');
    });
}

public function down(): void
{
    Schema::dropIfExists('bots');
}
```

### Add Column (Safe)

```php
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        // Nullable column - safe to add
        $table->string('webhook_url')->nullable()->after('platform');
    });
}

public function down(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->dropColumn('webhook_url');
    });
}
```

### Add Column with Default (PostgreSQL)

```php
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        // PostgreSQL can add with default without table lock
        $table->integer('message_count')->default(0);
    });
}
```

### Add Index (Non-Blocking)

```php
public function up(): void
{
    // Use CONCURRENTLY for non-blocking index creation
    DB::statement('CREATE INDEX CONCURRENTLY idx_messages_bot_created
                   ON messages (bot_id, created_at)');
}

public function down(): void
{
    DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_messages_bot_created');
}
```

### Rename Column

```php
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->renameColumn('old_name', 'new_name');
    });
}
```

### Change Column Type

```php
public function up(): void
{
    Schema::table('messages', function (Blueprint $table) {
        // Change varchar to text
        $table->text('content')->change();
    });
}
```

## Dangerous Operations

### Adding NOT NULL Column

```php
// ❌ DANGEROUS - will fail if table has data
$table->string('status');

// ✅ SAFE - add nullable first, then backfill
$table->string('status')->nullable();

// Then in separate migration:
DB::table('bots')->whereNull('status')->update(['status' => 'active']);
Schema::table('bots', function (Blueprint $table) {
    $table->string('status')->nullable(false)->change();
});
```

### Dropping Column in Production

```php
// ❌ DANGEROUS - may break running code
Schema::table('bots', function (Blueprint $table) {
    $table->dropColumn('deprecated_field');
});

// ✅ SAFE - Two-phase approach:
// Phase 1: Stop using column in code, deploy
// Phase 2: Drop column after code is deployed
```

### Changing Column Type (Data Loss)

```php
// ❌ DANGEROUS - may lose data
$table->integer('count')->change(); // was string

// ✅ SAFE - add new column, migrate data, drop old
$table->integer('count_new');
// Migrate data with raw SQL
DB::statement('UPDATE bots SET count_new = CAST(count AS INTEGER)');
// After verification, drop old column
```

## Foreign Keys

### Adding Foreign Key

```php
$table->foreignId('bot_id')->constrained()->cascadeOnDelete();

// Or manually:
$table->unsignedBigInteger('bot_id');
$table->foreign('bot_id')
      ->references('id')
      ->on('bots')
      ->onDelete('cascade');
```

### Adding Foreign Key to Existing Column

```php
public function up(): void
{
    Schema::table('messages', function (Blueprint $table) {
        $table->foreign('bot_id')
              ->references('id')
              ->on('bots')
              ->onDelete('cascade');
    });
}

public function down(): void
{
    Schema::table('messages', function (Blueprint $table) {
        $table->dropForeign(['bot_id']);
    });
}
```

## Large Table Migrations

### Batched Updates

```php
public function up(): void
{
    // Add column first
    Schema::table('messages', function (Blueprint $table) {
        $table->string('status')->nullable();
    });

    // Update in batches to avoid locks
    Message::query()
        ->whereNull('status')
        ->chunkById(1000, function ($messages) {
            foreach ($messages as $message) {
                $message->update(['status' => 'sent']);
            }
        });
}
```

### Using Raw SQL for Performance

```php
public function up(): void
{
    // More efficient for large tables
    DB::statement("
        UPDATE messages
        SET status = 'sent'
        WHERE status IS NULL
    ");
}
```

## Rollback Strategies

### Self-Contained Migrations

```php
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('new_field')->nullable();
    });
}

public function down(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->dropColumn('new_field');
    });
}
```

### Data-Preserving Rollback

```php
public function down(): void
{
    // Preserve data before dropping
    DB::statement("
        CREATE TABLE bots_backup AS
        SELECT * FROM bots
    ");

    Schema::dropIfExists('bots');
}
```

## Testing Migrations

```bash
# Test migrate and rollback
php artisan migrate:fresh
php artisan migrate:rollback

# Check migration status
php artisan migrate:status

# Run specific migration
php artisan migrate --path=/database/migrations/2026_01_14_000000_create_bots_table.php
```

## Neon-Specific Considerations

### Connection Pooling

```php
// For long migrations, use direct connection
// In .env: DATABASE_URL with ?sslmode=require (not pooled)
```

### Branch-Based Development

```bash
# Create branch for testing migrations
neon branches create --name feature-new-tables

# Test migration on branch
php artisan migrate

# After testing, merge or delete branch
```

## Common Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| Lock timeout | Long-running query | Run during low traffic |
| Foreign key violation | Orphaned records | Clean up data first |
| Out of memory | Too many records | Batch the operation |
| Slow migration | No index | Add index first |
