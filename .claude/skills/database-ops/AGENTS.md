# Database Ops Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 12:02

## Table of Contents

**Total Rules: 48**

- [Migrations](#migration) - 10 rules (3 CRITICAL)
- [Dangerous Operations](#safety) - 8 rules (4 CRITICAL)
- [pgvector Operations](#vector) - 7 rules (1 CRITICAL)
- [Index Strategy](#index) - 6 rules (2 HIGH)
- [Performance](#perf) - 6 rules (3 HIGH)
- [Gotchas](#gotcha) - 8 rules (2 CRITICAL)
- [Neon-Specific](#neon) - 3 rules (2 HIGH)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## Migrations
<a name="migration"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [migration-001-nullable-columns](rules/migration-001-nullable-columns.md) | **CRITICAL** | New Columns Must Be Nullable or Have Defaults |
| [migration-002-rollback-strategy](rules/migration-002-rollback-strategy.md) | **CRITICAL** | Always Include Rollback (down) Method |
| [migration-003-two-phase-drops](rules/migration-003-two-phase-drops.md) | **CRITICAL** | Two-Phase Column Drops for Production Safety |
| [migration-004-add-column-safe](rules/migration-004-add-column-safe.md) | **HIGH** | Safe Column Addition Pattern |
| [migration-005-add-index-concurrent](rules/migration-005-add-index-concurrent.md) | **HIGH** | Non-Blocking Index Creation |
| [migration-006-foreign-key](rules/migration-006-foreign-key.md) | **HIGH** | Foreign Key Best Practices |
| [migration-007-rename-column](rules/migration-007-rename-column.md) | **HIGH** | Column Rename Strategy |
| [migration-008-large-table-batch](rules/migration-008-large-table-batch.md) | MEDIUM | Batch Operations for Large Tables |
| [migration-009-testing-migrations](rules/migration-009-testing-migrations.md) | MEDIUM | Testing Migrations Before Production |
| [migration-010-change-column-type](rules/migration-010-change-column-type.md) | MEDIUM | Safe Column Type Changes |

**migration-001-nullable-columns**: Adding a NOT NULL column without a default to a table with existing rows will fail.

**migration-002-rollback-strategy**: The `down()` method is your emergency exit.

**migration-003-two-phase-drops**: If you drop a column while code still references it, you'll get instant 500 errors across your application.

**migration-004-add-column-safe**: Adding columns safely requires considering existing data, application code timing, and rollback scenarios.

**migration-005-add-index-concurrent**: Creating an index on a large table locks it for writes until the index is built.

**migration-006-foreign-key**: Foreign keys enforce referential integrity - they prevent orphaned records and ensure relationships are valid.

**migration-007-rename-column**: When you rename a column, there's a window where some servers have old code expecting the old name, while the database has the new name.

**migration-008-large-table-batch**: Updating millions of rows in a single transaction locks the table, exhausts memory, and can timeout.

**migration-009-testing-migrations**: Migrations that work locally often fail in production due to data differences, constraints, or scale.

**migration-010-change-column-type**: Changing column types in PostgreSQL can be simple (widening) or complex (narrowing/incompatible).

## Dangerous Operations
<a name="safety"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [safety-001-not-null-constraint](rules/safety-001-not-null-constraint.md) | **CRITICAL** | NOT NULL Constraint on Existing Data |
| [safety-002-drop-column-production](rules/safety-002-drop-column-production.md) | **CRITICAL** | Dropping Columns in Production |
| [safety-003-column-type-change](rules/safety-003-column-type-change.md) | **CRITICAL** | Column Type Changes Risk Data Loss |
| [safety-004-cascade-deletes](rules/safety-004-cascade-deletes.md) | **CRITICAL** | Cascade Deletes Can Cause Mass Data Loss |
| [safety-005-long-running-migration](rules/safety-005-long-running-migration.md) | **HIGH** | Long-Running Migrations and Lock Timeouts |
| [safety-006-orphaned-records](rules/safety-006-orphaned-records.md) | **HIGH** | Handling Orphaned Records |
| [safety-007-enum-changes](rules/safety-007-enum-changes.md) | **HIGH** | PostgreSQL Enum Modifications |
| [safety-008-backup-before-migration](rules/safety-008-backup-before-migration.md) | **HIGH** | Backup Before Destructive Migrations |

**safety-001-not-null-constraint**: You cannot add a NOT NULL constraint to a column that already contains NULL values.

**safety-002-drop-column-production**: `DROP COLUMN` is irreversible.

**safety-003-column-type-change**: Changing column types in PostgreSQL can cause silent data loss or corruption.

**safety-004-cascade-deletes**: `ON DELETE CASCADE` automatically deletes all child records when a parent is deleted.

**safety-005-long-running-migration**: Long-running migrations hold locks that block other operations.

**safety-006-orphaned-records**: Adding a foreign key constraint validates all existing data.

**safety-007-enum-changes**: PostgreSQL enums are immutable once created.

**safety-008-backup-before-migration**: Before any migration that modifies or deletes data, you need a backup.

## pgvector Operations
<a name="vector"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [vector-001-create-extension](rules/vector-001-create-extension.md) | **CRITICAL** | Enable pgvector Extension Before Use |
| [vector-002-choose-dimension](rules/vector-002-choose-dimension.md) | **HIGH** | Vector Dimension Must Match Embedding Model |
| [vector-003-model-consistency](rules/vector-003-model-consistency.md) | **HIGH** | Use Same Embedding Model for Index and Query |
| [vector-004-similarity-threshold](rules/vector-004-similarity-threshold.md) | **HIGH** | Similarity Threshold Tuning |
| [vector-005-distance-functions](rules/vector-005-distance-functions.md) | MEDIUM | Choosing the Right Distance Function |
| [vector-006-vector-to-string](rules/vector-006-vector-to-string.md) | MEDIUM | Vector Format for SQL Queries |
| [vector-007-hybrid-search](rules/vector-007-hybrid-search.md) | MEDIUM | Hybrid Search (Semantic + Keyword) |

**vector-001-create-extension**: The `vector` type is not built into PostgreSQL - it comes from the pgvector extension.

**vector-002-choose-dimension**: Each embedding model produces vectors of a specific dimension.

**vector-003-model-consistency**: Embeddings from different models are NOT comparable.

**vector-004-similarity-threshold**: The similarity threshold filters results by relevance.

**vector-005-distance-functions**: pgvector supports multiple distance functions.

**vector-006-vector-to-string**: pgvector expects vectors in a specific string format: `[0.

**vector-007-hybrid-search**: Semantic search finds conceptually similar content but may miss exact keyword matches.

## Index Strategy
<a name="index"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [index-001-hnsw-vs-ivfflat](rules/index-001-hnsw-vs-ivfflat.md) | **HIGH** | HNSW vs IVFFlat Index Selection |
| [index-002-hnsw-params](rules/index-002-hnsw-params.md) | **HIGH** | HNSW Index Parameters (m and ef_construction) |
| [index-003-ivfflat-lists](rules/index-003-ivfflat-lists.md) | MEDIUM | IVFFlat Lists Parameter |
| [index-004-probes-and-ef](rules/index-004-probes-and-ef.md) | MEDIUM | Query-Time Index Tuning (probes/ef_search) |
| [index-005-when-no-index](rules/index-005-when-no-index.md) | MEDIUM | When Vector Index Is Unnecessary |
| [index-006-index-maintenance](rules/index-006-index-maintenance.md) | MEDIUM | Vector Index Maintenance After Bulk Operations |

**index-001-hnsw-vs-ivfflat**: pgvector offers two index types: IVFFlat and HNSW.

**index-002-hnsw-params**: HNSW has two key parameters: `m` (connections per node) and `ef_construction` (build quality).

**index-003-ivfflat-lists**: IVFFlat divides vectors into `lists` (clusters).

**index-004-probes-and-ef**: Vector indexes have query-time parameters that affect search quality.

**index-005-when-no-index**: Creating indexes on small tables wastes resources.

**index-006-index-maintenance**: IVFFlat indexes are built with a specific data distribution.

## Performance
<a name="perf"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [perf-001-explain-analyze](rules/perf-001-explain-analyze.md) | **HIGH** | Use EXPLAIN ANALYZE for Query Optimization |
| [perf-002-sequential-scan](rules/perf-002-sequential-scan.md) | **HIGH** | Avoid Sequential Scans on Large Tables |
| [perf-003-filter-before-search](rules/perf-003-filter-before-search.md) | **HIGH** | Filter Before Vector Search |
| [perf-004-batch-insert](rules/perf-004-batch-insert.md) | MEDIUM | Efficient Batch Inserts |
| [perf-005-connection-pool](rules/perf-005-connection-pool.md) | MEDIUM | Connection Pooling Configuration |
| [perf-006-query-timeout](rules/perf-006-query-timeout.md) | MEDIUM | Set Appropriate Query Timeouts |

**perf-001-explain-analyze**: EXPLAIN ANALYZE shows the actual execution plan and timing of queries.

**perf-002-sequential-scan**: Sequential scans read every row in a table.

**perf-003-filter-before-search**: Vector similarity search is expensive.

**perf-004-batch-insert**: Each INSERT statement has overhead: parse, plan, execute, commit.

**perf-005-connection-pool**: Database connections are expensive to create.

**perf-006-query-timeout**: Queries without timeouts can run forever, blocking connections and potentially causing cascading failures.

## Gotchas
<a name="gotcha"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [gotcha-001-pool-exhaustion](rules/gotcha-001-pool-exhaustion.md) | **CRITICAL** | Connection Pool Exhaustion |
| [gotcha-002-migration-production-fail](rules/gotcha-002-migration-production-fail.md) | **CRITICAL** | Migration Works Locally, Fails in Production |
| [gotcha-003-decimal-precision](rules/gotcha-003-decimal-precision.md) | **HIGH** | Decimal Precision Loss with Float |
| [gotcha-004-deadlock](rules/gotcha-004-deadlock.md) | **HIGH** | Deadlocks from Concurrent Updates |
| [gotcha-005-enum-immutable](rules/gotcha-005-enum-immutable.md) | **HIGH** | PostgreSQL Enums Are Immutable |
| [gotcha-006-foreign-key-error](rules/gotcha-006-foreign-key-error.md) | **HIGH** | Foreign Key Constraint Errors |
| [gotcha-007-slow-vector-search](rules/gotcha-007-slow-vector-search.md) | MEDIUM | Slow Vector Search Without Index |
| [gotcha-008-connection-timeout](rules/gotcha-008-connection-timeout.md) | MEDIUM | Connection Timeout vs Query Timeout |

**gotcha-001-pool-exhaustion**: Connection pools have limits (typically 100 for Neon pooler).

**gotcha-002-migration-production-fail**: Your local database is often empty or has minimal test data.

**gotcha-003-decimal-precision**: Float/double types use binary representation and cannot exactly represent many decimal values.

**gotcha-004-deadlock**: When two transactions try to update the same rows in different orders, they can deadlock - each waiting for the other's lock.

**gotcha-005-enum-immutable**: PostgreSQL enum types can only have values added, not removed or renamed.

**gotcha-006-foreign-key-error**: Foreign key constraints prevent deleting parent records when children exist.

**gotcha-007-slow-vector-search**: Without a vector index, similarity search must compare the query vector against every row in the table.

**gotcha-008-connection-timeout**: There are two types of timeouts: connection timeout (how long to wait to establish connection) and query timeout (how long a query can run).

## Neon-Specific
<a name="neon"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [neon-001-branching](rules/neon-001-branching.md) | **HIGH** | Use Neon Branches for Safe Testing |
| [neon-002-connection-pooling](rules/neon-002-connection-pooling.md) | **HIGH** | Neon Connection Pooling vs Direct Connection |
| [neon-003-serverless-limits](rules/neon-003-serverless-limits.md) | MEDIUM | Neon Serverless Compute Limits |

**neon-001-branching**: Neon branches are instant, copy-on-write clones of your database.

**neon-002-connection-pooling**: Neon offers two connection types: pooled (via PgBouncer) and direct.

**neon-003-serverless-limits**: Neon's serverless compute has limits on connections, compute time, and storage.

## Quick Reference by Tag

- **alter**: migration-010-change-column-type
- **analyze**: perf-001-explain-analyze
- **backup**: safety-008-backup-before-migration
- **batch**: perf-004-batch-insert, migration-008-large-table-batch
- **binding**: vector-006-vector-to-string
- **branch**: migration-009-testing-migrations, neon-001-branching
- **bulk**: perf-004-batch-insert, index-006-index-maintenance
- **cascade**: safety-004-cascade-deletes
- **column**: migration-001-nullable-columns, migration-004-add-column-safe, migration-007-rename-column, migration-010-change-column-type
- **compute**: neon-003-serverless-limits
- **concurrent**: migration-005-add-index-concurrent, gotcha-004-deadlock
- **connection**: perf-005-connection-pool, perf-006-query-timeout, neon-002-connection-pooling, gotcha-001-pool-exhaustion, gotcha-008-connection-timeout
- **consistency**: vector-003-model-consistency
- **constraint**: migration-006-foreign-key, safety-001-not-null-constraint, gotcha-006-foreign-key-error
- **conversion**: safety-003-column-type-change
- **cosine**: vector-005-distance-functions
- **data-integrity**: safety-001-not-null-constraint, safety-006-orphaned-records
- **data-loss**: safety-002-drop-column-production, safety-003-column-type-change, safety-004-cascade-deletes
- **data-protection**: safety-008-backup-before-migration
- **deadlock**: gotcha-004-deadlock
- **decimal**: gotcha-003-decimal-precision
- **default**: migration-004-add-column-safe
- **delete**: gotcha-006-foreign-key-error
- **deployment**: migration-002-rollback-strategy, migration-003-two-phase-drops, migration-007-rename-column
- **dimension**: vector-002-choose-dimension
- **distance**: vector-005-distance-functions
- **down**: migration-002-rollback-strategy
- **drop-column**: migration-003-two-phase-drops, safety-002-drop-column-production
- **ef_search**: index-004-probes-and-ef
- **embedding**: vector-002-choose-dimension, vector-003-model-consistency
- **enum**: safety-007-enum-changes, gotcha-005-enum-immutable
- **euclidean**: vector-005-distance-functions
- **explain**: perf-001-explain-analyze
- **extension**: vector-001-create-extension
- **filter**: perf-003-filter-before-search
- **float**: gotcha-003-decimal-precision
- **foreign-key**: migration-006-foreign-key, safety-004-cascade-deletes, safety-006-orphaned-records, gotcha-006-foreign-key-error
- **format**: vector-006-vector-to-string
- **gotcha**: gotcha-001-pool-exhaustion, gotcha-002-migration-production-fail, gotcha-003-decimal-precision, gotcha-004-deadlock, gotcha-005-enum-immutable, gotcha-006-foreign-key-error, gotcha-007-slow-vector-search, gotcha-008-connection-timeout
- **hnsw**: index-001-hnsw-vs-ivfflat, index-002-hnsw-params
- **hybrid**: vector-007-hybrid-search
- **immutable**: gotcha-005-enum-immutable
- **index**: perf-002-sequential-scan, index-001-hnsw-vs-ivfflat, index-002-hnsw-params, index-003-ivfflat-lists, index-004-probes-and-ef, index-005-when-no-index, index-006-index-maintenance, migration-005-add-index-concurrent, gotcha-007-slow-vector-search
- **insert**: perf-004-batch-insert
- **ivfflat**: index-001-hnsw-vs-ivfflat, index-003-ivfflat-lists
- **keyword**: vector-007-hybrid-search
- **large-table**: migration-008-large-table-batch
- **limits**: neon-003-serverless-limits
- **lists**: index-003-ivfflat-lists
- **lock**: migration-005-add-index-concurrent, safety-005-long-running-migration, gotcha-004-deadlock
- **maintenance**: index-006-index-maintenance
- **migration**: migration-001-nullable-columns, migration-002-rollback-strategy, migration-003-two-phase-drops, migration-004-add-column-safe, migration-005-add-index-concurrent, migration-006-foreign-key, migration-007-rename-column, migration-008-large-table-batch, migration-009-testing-migrations, migration-010-change-column-type, safety-005-long-running-migration, safety-008-backup-before-migration, neon-001-branching, gotcha-002-migration-production-fail
- **model**: vector-002-choose-dimension, vector-003-model-consistency
- **money**: gotcha-003-decimal-precision
- **neon**: perf-005-connection-pool, migration-009-testing-migrations, neon-001-branching, neon-002-connection-pooling, neon-003-serverless-limits, gotcha-001-pool-exhaustion
- **not-null**: safety-001-not-null-constraint
- **nullable**: migration-001-nullable-columns, migration-004-add-column-safe
- **optimization**: perf-001-explain-analyze, perf-002-sequential-scan, perf-003-filter-before-search, index-005-when-no-index
- **orphan**: safety-006-orphaned-records
- **parameters**: index-002-hnsw-params
- **performance**: perf-001-explain-analyze, perf-002-sequential-scan, perf-003-filter-before-search, perf-004-batch-insert, perf-005-connection-pool, perf-006-query-timeout, index-001-hnsw-vs-ivfflat, index-005-when-no-index, migration-008-large-table-batch, gotcha-007-slow-vector-search
- **pgvector**: vector-001-create-extension
- **pool**: perf-005-connection-pool, gotcha-001-pool-exhaustion
- **pooling**: neon-002-connection-pooling
- **postgresql**: safety-007-enum-changes, gotcha-005-enum-immutable
- **precision**: gotcha-003-decimal-precision
- **probes**: index-004-probes-and-ef
- **production**: migration-001-nullable-columns, migration-003-two-phase-drops, safety-002-drop-column-production, gotcha-002-migration-production-fail
- **query**: perf-001-explain-analyze, perf-006-query-timeout, index-004-probes-and-ef, gotcha-008-connection-timeout
- **reindex**: index-006-index-maintenance
- **relationship**: migration-006-foreign-key
- **rename**: migration-007-rename-column
- **rollback**: migration-002-rollback-strategy
- **safety**: safety-001-not-null-constraint, safety-002-drop-column-production, safety-003-column-type-change, safety-004-cascade-deletes, safety-005-long-running-migration, safety-006-orphaned-records, safety-007-enum-changes, safety-008-backup-before-migration
- **search**: vector-007-hybrid-search
- **sequential-scan**: perf-002-sequential-scan
- **serverless**: neon-002-connection-pooling, neon-003-serverless-limits, gotcha-001-pool-exhaustion
- **setup**: vector-001-create-extension
- **similarity**: vector-004-similarity-threshold
- **small-table**: index-005-when-no-index
- **sql**: vector-006-vector-to-string
- **testing**: migration-009-testing-migrations, neon-001-branching, gotcha-002-migration-production-fail
- **threshold**: vector-004-similarity-threshold
- **timeout**: perf-006-query-timeout, safety-005-long-running-migration, gotcha-008-connection-timeout
- **transaction**: gotcha-004-deadlock
- **tuning**: index-002-hnsw-params, index-003-ivfflat-lists, index-004-probes-and-ef, vector-004-similarity-threshold
- **type**: safety-007-enum-changes
- **type-change**: migration-010-change-column-type, safety-003-column-type-change
- **vector**: perf-003-filter-before-search, index-001-hnsw-vs-ivfflat, vector-001-create-extension, vector-002-choose-dimension, vector-003-model-consistency, vector-004-similarity-threshold, vector-005-distance-functions, vector-006-vector-to-string, vector-007-hybrid-search, gotcha-007-slow-vector-search
