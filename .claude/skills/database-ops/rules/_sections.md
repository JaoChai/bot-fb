# Database Operations Decision Trees

Quick decision guides for common database scenarios.

---

## 1. Index Type Selection (Vector Search)

```
Need vector search index?
│
├─ How many records?
│   │
│   ├─ < 10K records
│   │   └─ ✅ No index needed (linear scan is fine)
│   │
│   ├─ 10K - 100K records
│   │   └─ ✅ IVFFlat (lists = sqrt(n))
│   │       └─ Faster to build, good recall
│   │
│   ├─ 100K - 1M records
│   │   └─ ✅ HNSW (m=16, ef_construction=64)
│   │       └─ Slower build, faster query
│   │
│   └─ > 1M records
│       └─ ✅ HNSW (m=32, ef_construction=128)
│           └─ Best quality at scale
```

**Quick Reference:**
| Records | Index | Build Time | Query Speed |
|---------|-------|------------|-------------|
| < 10K | None | - | Fast |
| 10K-100K | IVFFlat | Fast | Medium |
| > 100K | HNSW | Slow | Fast |

---

## 2. Migration Safety Decision

```
Adding/Changing column?
│
├─ New column?
│   │
│   ├─ Has existing data?
│   │   ├─ Yes → ✅ Make nullable OR add default
│   │   └─ No → ⚠️ Can use NOT NULL
│   │
│   └─ Frequently queried?
│       └─ Yes → ✅ Add index
│
├─ Dropping column?
│   │
│   ├─ Still used in code?
│   │   ├─ Yes → ❌ STOP! Remove from code first
│   │   └─ No → ✅ Safe to drop
│   │
│   └─ Two-phase approach:
│       1. Deploy code without column usage
│       2. Then drop column
│
├─ Changing type?
│   │
│   ├─ Widening? (varchar(50) → varchar(100))
│   │   └─ ✅ Usually safe
│   │
│   └─ Narrowing? (text → varchar(50))
│       └─ ❌ Data loss risk! Use migration pattern:
│           1. Add new column
│           2. Copy data with validation
│           3. Swap usage
│           4. Drop old column
│
└─ Adding NOT NULL?
    └─ Has null values?
        ├─ Yes → ❌ Backfill first, then add constraint
        └─ No → ✅ Safe to add
```

---

## 3. Query Performance Diagnosis

```
Query is slow?
│
├─ Run EXPLAIN ANALYZE
│   │
│   ├─ Sequential Scan?
│   │   └─ ✅ Add index on WHERE columns
│   │
│   ├─ Nested Loop (high cost)?
│   │   └─ ✅ Add composite index or JOIN optimization
│   │
│   ├─ Index Scan but still slow?
│   │   └─ ✅ Check if index covers all columns
│   │       └─ Consider covering index
│   │
│   └─ Sort operation?
│       └─ ✅ Add index with ORDER BY columns
│
└─ Still slow?
    ├─ Too many rows returned?
    │   └─ ✅ Add pagination (LIMIT/OFFSET)
    │
    ├─ Complex JOINs?
    │   └─ ✅ Consider denormalization or materialized view
    │
    └─ Connection issues?
        └─ ✅ Check pool settings, use pooler
```

---

## 4. Vector Search Not Finding Results

```
Semantic search returns nothing?
│
├─ Check embedding
│   │
│   ├─ Is embedding null?
│   │   └─ ✅ Check embedding generation
│   │
│   ├─ Wrong dimension?
│   │   └─ ✅ Must match model (1536 for OpenAI)
│   │
│   └─ Different model used?
│       └─ ❌ Embeddings incompatible!
│           └─ Re-embed all documents
│
├─ Check threshold
│   │
│   └─ Too high? (> 0.8)
│       └─ ✅ Lower to 0.6-0.7 for text
│
├─ Check index
│   │
│   └─ Index exists?
│       ├─ No → Create HNSW index
│       └─ Yes → Check probes/ef_search settings
│
└─ Check filters
    └─ WHERE clause too restrictive?
        └─ ✅ Remove filters, test, add back
```

---

## 5. Connection Issues

```
Connection failing?
│
├─ Check URL
│   │
│   ├─ Using pooler URL?
│   │   └─ ?pooler=true for serverless
│   │
│   └─ Direct connection?
│       └─ Use for long-running migrations
│
├─ Connection limit?
│   │
│   ├─ > 100 connections?
│   │   └─ ❌ Pool exhausted
│   │       └─ Check for connection leaks
│   │
│   └─ < 100 but failing?
│       └─ ✅ Check timeout settings
│
└─ Timeout?
    ├─ Query timeout → Optimize query
    └─ Connection timeout → Network/pool issue
```

---

## Quick Commands

### Check Migration Safety
```bash
# Preview migration SQL
php artisan migrate --pretend

# Check for NOT NULL without default
grep -r "nullable(false)" database/migrations/
```

### Check Index Usage
```sql
-- See if index is being used
EXPLAIN ANALYZE SELECT * FROM table WHERE column = 'value';

-- List all indexes
SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'table_name';
```

### Check Vector Index
```sql
-- Check HNSW index
SELECT * FROM pg_indexes WHERE indexdef LIKE '%hnsw%';

-- Check IVFFlat index
SELECT * FROM pg_indexes WHERE indexdef LIKE '%ivfflat%';
```

### Check Connections
```sql
-- Active connections
SELECT count(*) FROM pg_stat_activity;

-- Connection details
SELECT pid, usename, application_name, state, query_start
FROM pg_stat_activity
WHERE datname = current_database();
```
