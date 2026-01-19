# Performance Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 13:13

## Table of Contents

**Total Rules: 20**

- [DB Optimization](#query) - 5 rules (1 CRITICAL)
- [Frontend Performance](#frontend) - 5 rules (4 HIGH)
- [Caching](#cache) - 4 rules (2 HIGH)
- [Core Web Vitals](#vitals) - 3 rules (1 HIGH)
- [React Performance](#react) - 3 rules (2 HIGH)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## DB Optimization
<a name="query"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [query-001-n-plus-one](rules/query-001-n-plus-one.md) | **CRITICAL** | N+1 Query Problem |
| [query-002-slow-queries](rules/query-002-slow-queries.md) | **HIGH** | Slow Database Queries |
| [query-003-missing-index](rules/query-003-missing-index.md) | **HIGH** | Missing Database Indexes |
| [query-004-explain-analyze](rules/query-004-explain-analyze.md) | MEDIUM | Using EXPLAIN ANALYZE Effectively |
| [query-005-connection-pool](rules/query-005-connection-pool.md) | MEDIUM | Database Connection Pool Issues |

## Frontend Performance
<a name="frontend"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [frontend-001-bundle-size](rules/frontend-001-bundle-size.md) | **HIGH** | Large Bundle Size |
| [frontend-002-code-splitting](rules/frontend-002-code-splitting.md) | **HIGH** | Missing Code Splitting |
| [frontend-003-image-optimization](rules/frontend-003-image-optimization.md) | **HIGH** | Unoptimized Images |
| [frontend-005-api-waterfall](rules/frontend-005-api-waterfall.md) | **HIGH** | API Request Waterfall |
| [frontend-004-asset-loading](rules/frontend-004-asset-loading.md) | MEDIUM | Inefficient Asset Loading |

## Caching
<a name="cache"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [cache-001-query-caching](rules/cache-001-query-caching.md) | **HIGH** | Database Query Caching |
| [cache-003-invalidation](rules/cache-003-invalidation.md) | **HIGH** | Cache Invalidation Issues |
| [cache-002-http-caching](rules/cache-002-http-caching.md) | MEDIUM | HTTP Caching Headers |
| [cache-004-react-query-cache](rules/cache-004-react-query-cache.md) | MEDIUM | React Query Cache Management |

## Core Web Vitals
<a name="vitals"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [vitals-001-lcp](rules/vitals-001-lcp.md) | **HIGH** | Largest Contentful Paint (LCP) |
| [vitals-002-fid](rules/vitals-002-fid.md) | MEDIUM | First Input Delay (FID) / INP |
| [vitals-003-cls](rules/vitals-003-cls.md) | MEDIUM | Cumulative Layout Shift (CLS) |

## React Performance
<a name="react"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [react-001-re-renders](rules/react-001-re-renders.md) | **HIGH** | Unnecessary React Re-renders |
| [react-003-virtualization](rules/react-003-virtualization.md) | **HIGH** | List Virtualization |
| [react-002-memoization](rules/react-002-memoization.md) | MEDIUM | React Memoization Patterns |

## Quick Reference by Tag

- **analyze**: query-004-explain-analyze
- **api**: frontend-005-api-waterfall
- **assets**: frontend-004-asset-loading
- **bundle**: frontend-001-bundle-size
- **cache**: cache-001-query-caching, cache-003-invalidation, cache-002-http-caching, cache-004-react-query-cache
- **cdn**: cache-002-http-caching
- **cls**: vitals-003-cls
- **code-splitting**: frontend-002-code-splitting
- **connection**: query-005-connection-pool
- **core-web-vitals**: vitals-001-lcp, vitals-002-fid, vitals-003-cls
- **css**: frontend-004-asset-loading
- **database**: query-001-n-plus-one, query-002-slow-queries, query-003-missing-index, query-004-explain-analyze, query-005-connection-pool, cache-001-query-caching
- **eager-loading**: query-001-n-plus-one
- **explain**: query-004-explain-analyze
- **fid**: vitals-002-fid
- **fonts**: frontend-004-asset-loading
- **frontend**: cache-004-react-query-cache
- **headers**: cache-002-http-caching
- **http**: cache-002-http-caching
- **images**: frontend-003-image-optimization
- **index**: query-002-slow-queries, query-003-missing-index
- **inp**: vitals-002-fid
- **interactivity**: vitals-002-fid
- **invalidation**: cache-003-invalidation
- **laravel**: query-001-n-plus-one, cache-001-query-caching
- **layout-shift**: vitals-003-cls
- **lazy-loading**: frontend-002-code-splitting, frontend-003-image-optimization
- **lcp**: vitals-001-lcp
- **list**: react-003-virtualization
- **memo**: react-002-memoization
- **n+1**: query-001-n-plus-one
- **neon**: query-005-connection-pool
- **optimization**: query-002-slow-queries, query-003-missing-index, react-001-re-renders, react-002-memoization, frontend-001-bundle-size, frontend-003-image-optimization
- **parallel**: frontend-005-api-waterfall
- **performance**: react-001-re-renders, react-003-virtualization, vitals-001-lcp
- **pool**: query-005-connection-pool
- **postgresql**: query-003-missing-index, query-004-explain-analyze
- **preload**: frontend-004-asset-loading
- **re-render**: react-001-re-renders
- **react**: react-001-re-renders, react-003-virtualization, react-002-memoization, frontend-002-code-splitting
- **react-query**: frontend-005-api-waterfall, cache-004-react-query-cache
- **redis**: cache-001-query-caching
- **slow-query**: query-002-slow-queries
- **stale-data**: cache-003-invalidation
- **stale-time**: cache-004-react-query-cache
- **useCallback**: react-002-memoization
- **useMemo**: react-002-memoization
- **ux**: vitals-001-lcp, vitals-003-cls
- **virtualization**: react-003-virtualization
- **vite**: frontend-001-bundle-size, frontend-002-code-splitting
- **waterfall**: frontend-005-api-waterfall
- **webp**: frontend-003-image-optimization
- **webpack**: frontend-001-bundle-size
