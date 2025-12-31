---
name: migration-validator
description: ตรวจสอบความปลอดภัยของ database migrations - ใช้ก่อน run php artisan migrate เพื่อ detect breaking changes, data loss, หรือ foreign key issues
---

# Migration Validator

ใช้ skill นี้ก่อน run migrations เพื่อตรวจสอบความปลอดภัย

## Pre-Migration Checklist

### Before Running Migrate

- [ ] **Backup database** (production)
- [ ] ตรวจสอบ migration code
- [ ] ระบุ breaking changes
- [ ] เตรียม rollback plan
- [ ] ทดสอบใน staging ก่อน

### Migration Commands
```bash
# ดู pending migrations
php artisan migrate:status

# Run migrations (dev)
php artisan migrate

# Run with confirmation (prod)
php artisan migrate --force

# Rollback
php artisan migrate:rollback --step=1
```

---

## Safety Rules

### 1. Column Modifications

| Operation | Risk Level | Notes |
|-----------|------------|-------|
| Add nullable column | Safe | ไม่กระทบ existing data |
| Add column with default | Safe | Laravel handles default |
| Drop column | DANGEROUS | Data loss ถ้าไม่ backup |
| Rename column | Medium | Apps อาจพัง |
| Change type | DANGEROUS | Data อาจ truncate/error |

### 2. Table Operations

| Operation | Risk Level | Notes |
|-----------|------------|-------|
| Create table | Safe | New table |
| Drop table | DANGEROUS | Data loss |
| Rename table | Medium | Apps อาจพัง |

### 3. Constraint Operations

| Operation | Risk Level | Notes |
|-----------|------------|-------|
| Add foreign key | Medium | Might fail if data violates |
| Drop foreign key | Safe | ไม่ลบ data |
| Add unique constraint | Medium | Might fail if duplicates exist |
| Add index | Safe | Performance improvement |

---

## Breaking Changes Detection

### Red Flags (STOP & Review)

```php
// ❌ Dropping columns with data
Schema::table('users', function (Blueprint $table) {
    $table->dropColumn('important_field');
});

// ❌ Changing column type unsafely
$table->string('price')->change(); // was decimal

// ❌ Adding non-nullable without default
$table->string('required_field'); // no ->nullable() or ->default()

// ❌ Dropping tables with data
Schema::dropIfExists('orders');
```

### Safe Patterns

```php
// ✅ Add nullable column
$table->string('new_field')->nullable();

// ✅ Add with default
$table->boolean('is_active')->default(true);

// ✅ Add index
$table->index('email');

// ✅ Soft delete instead of drop
$table->softDeletes();
```

---

## Foreign Key Guidelines

### Check Before Adding FK

```sql
-- ตรวจสอบว่ามี orphan records ไหม
SELECT child.id
FROM child_table child
LEFT JOIN parent_table parent ON child.parent_id = parent.id
WHERE parent.id IS NULL;
```

### Safe FK Addition

```php
// 1. ตรวจสอบ orphans ก่อน
// 2. ลบ orphans หรือ set NULL
// 3. แล้วค่อย add FK

Schema::table('posts', function (Blueprint $table) {
    $table->foreign('user_id')
          ->references('id')
          ->on('users')
          ->onDelete('cascade'); // or 'set null'
});
```

### Common FK Issues

| Issue | Solution |
|-------|----------|
| Orphan records | ลบหรือ fix ก่อน add FK |
| Wrong data type | ให้ FK และ PK type ตรงกัน |
| Missing index | FK ควรมี index |

---

## Rollback Strategy

### Always Have a Rollback

```php
public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('new_column');
    });
}
```

### Testing Rollback

```bash
# Test rollback works
php artisan migrate
php artisan migrate:rollback --step=1
php artisan migrate
```

### Emergency Rollback (Production)

```bash
# 1. Stop deployments
# 2. Rollback
php artisan migrate:rollback --step=1

# 3. If rollback fails, restore from backup
pg_restore -d database_name backup.sql
```

---

## Data Loss Prevention

### Before Dropping Columns/Tables

1. **Check if column has data**
   ```sql
   SELECT COUNT(*) FROM table WHERE column IS NOT NULL;
   ```

2. **Backup if needed**
   ```sql
   CREATE TABLE backup_table AS SELECT * FROM original_table;
   ```

3. **Consider soft delete**
   ```php
   $table->softDeletes();
   ```

### Before Type Changes

1. **Check data range**
   ```sql
   SELECT MAX(LENGTH(field)) FROM table;
   SELECT MIN(field), MAX(field) FROM table;
   ```

2. **Test conversion**
   ```sql
   SELECT field::new_type FROM table LIMIT 10;
   ```

---

## PostgreSQL Specific

### Online Schema Changes

PostgreSQL ล็อคตารางระหว่าง ALTER TABLE:
- Add column: Quick (metadata only)
- Add index: CONCURRENTLY ไม่ lock
- Drop column: Quick (metadata only)
- Change type: May lock for long time

### Safe Index Creation

```php
// Use CONCURRENTLY to avoid locking
DB::statement('CREATE INDEX CONCURRENTLY idx_name ON table(column)');
```

### pgvector Considerations

```php
// Embedding columns are large
$table->vector('embedding', 1536);

// Index for similarity search
DB::statement('CREATE INDEX idx_embedding ON document_chunks
    USING ivfflat (embedding vector_cosine_ops)');
```

---

## Quick Validation Commands

### Check Migration Status
```bash
php artisan migrate:status
```

### Preview SQL (Dry Run)
```bash
php artisan migrate --pretend
```

### Check Table Structure
```sql
\d+ table_name  -- PostgreSQL
```

### Check Foreign Keys
```sql
SELECT * FROM information_schema.table_constraints
WHERE constraint_type = 'FOREIGN KEY'
AND table_name = 'your_table';
```

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `backend/database/migrations/` | All migration files |
| `backend/database/schema/` | Schema snapshots |
| `.env` | Database connection |
