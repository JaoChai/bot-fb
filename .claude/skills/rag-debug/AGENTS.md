# Rag Debug Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 12:56

## Table of Contents

**Total Rules: 20**

- [Embeddings](#embed) - 4 rules (3 CRITICAL)
- [Search Issues](#search) - 5 rules (2 HIGH)
- [Reranker](#rerank) - 4 rules (1 HIGH)
- [Thai NLP](#thai) - 4 rules (2 HIGH)
- [Thresholds](#thresh) - 3 rules (2 HIGH)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## Embeddings
<a name="embed"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [embed-001-null-embedding](rules/embed-001-null-embedding.md) | **CRITICAL** | Embedding is NULL in Database |
| [embed-002-model-consistency](rules/embed-002-model-consistency.md) | **CRITICAL** | Embedding Model Mismatch Between Index and Query |
| [embed-003-dimension-mismatch](rules/embed-003-dimension-mismatch.md) | **CRITICAL** | Embedding Dimension Mismatch |
| [embed-004-generation-failure](rules/embed-004-generation-failure.md) | **HIGH** | Embedding Generation Fails |

## Search Issues
<a name="search"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [search-001-no-results](rules/search-001-no-results.md) | **HIGH** | Search Returns No Results |
| [search-002-wrong-results](rules/search-002-wrong-results.md) | **HIGH** | Search Returns Wrong/Irrelevant Results |
| [search-003-slow-search](rules/search-003-slow-search.md) | MEDIUM | Search is Slow |
| [search-004-index-missing](rules/search-004-index-missing.md) | MEDIUM | Vector Index Missing or Invalid |
| [search-005-hybrid-config](rules/search-005-hybrid-config.md) | MEDIUM | Hybrid Search Configuration Issues |

## Reranker
<a name="rerank"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [rerank-001-filter-too-much](rules/rerank-001-filter-too-much.md) | **HIGH** | Reranker Filtering Too Many Results |
| [rerank-002-scoring-wrong](rules/rerank-002-scoring-wrong.md) | MEDIUM | Reranker Scoring Seems Wrong |
| [rerank-003-jina-api-error](rules/rerank-003-jina-api-error.md) | MEDIUM | Jina Reranker API Errors |
| [rerank-004-ordering-issues](rules/rerank-004-ordering-issues.md) | MEDIUM | Reranker Result Ordering Issues |

## Thai NLP
<a name="thai"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [thai-001-query-normalization](rules/thai-001-query-normalization.md) | **HIGH** | Thai Query Normalization Issues |
| [thai-002-thai-embedding](rules/thai-002-thai-embedding.md) | **HIGH** | Thai Text Embedding Quality Issues |
| [thai-003-keyword-boost](rules/thai-003-keyword-boost.md) | MEDIUM | Thai Keyword Boosting Not Working |
| [thai-004-tokenization](rules/thai-004-tokenization.md) | MEDIUM | Thai Tokenization Issues |

## Thresholds
<a name="thresh"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [thresh-001-semantic-threshold](rules/thresh-001-semantic-threshold.md) | **HIGH** | Semantic Search Threshold Issues |
| [thresh-002-rerank-threshold](rules/thresh-002-rerank-threshold.md) | **HIGH** | Reranker Threshold Configuration |
| [thresh-003-context-limit](rules/thresh-003-context-limit.md) | MEDIUM | Context Window and Token Limits |

## Quick Reference by Tag

- **api**: embed-004-generation-failure, rerank-003-jina-api-error
- **boost**: thai-003-keyword-boost
- **chunking**: search-002-wrong-results
- **consistency**: embed-002-model-consistency
- **context**: thresh-003-context-limit
- **database**: embed-001-null-embedding
- **dimensions**: embed-003-dimension-mismatch
- **embedding**: embed-001-null-embedding, embed-002-model-consistency, embed-003-dimension-mismatch, embed-004-generation-failure, thai-002-thai-embedding
- **empty**: search-001-no-results
- **encoding**: thai-001-query-normalization
- **error**: rerank-003-jina-api-error
- **filtering**: thresh-001-semantic-threshold, thresh-002-rerank-threshold, rerank-001-filter-too-much
- **fusion**: search-005-hybrid-config
- **generation**: embed-001-null-embedding, embed-004-generation-failure
- **hybrid**: search-005-hybrid-config, thai-003-keyword-boost
- **index**: search-003-slow-search, search-004-index-missing
- **jina**: thresh-002-rerank-threshold, rerank-001-filter-too-much, rerank-003-jina-api-error
- **keyword**: search-005-hybrid-config, thai-003-keyword-boost
- **limit**: thresh-003-context-limit
- **model**: embed-002-model-consistency, thai-002-thai-embedding
- **multilingual**: thai-002-thai-embedding
- **nlp**: thai-004-tokenization
- **normalization**: thai-001-query-normalization
- **null**: embed-001-null-embedding
- **openai**: embed-004-generation-failure
- **optimization**: search-003-slow-search
- **ordering**: rerank-004-ordering-issues
- **performance**: search-003-slow-search, search-004-index-missing
- **pgvector**: embed-003-dimension-mismatch, search-004-index-missing
- **quality**: rerank-002-scoring-wrong
- **relevance**: search-002-wrong-results, rerank-002-scoring-wrong
- **reranker**: thresh-002-rerank-threshold, search-002-wrong-results, rerank-001-filter-too-much, rerank-002-scoring-wrong, rerank-003-jina-api-error, rerank-004-ordering-issues
- **results**: rerank-004-ordering-issues
- **scoring**: rerank-002-scoring-wrong
- **search**: search-001-no-results, search-002-wrong-results, search-003-slow-search, search-004-index-missing, search-005-hybrid-config, thai-003-keyword-boost
- **semantic**: thresh-001-semantic-threshold, search-001-no-results, search-005-hybrid-config
- **similarity**: thresh-001-semantic-threshold
- **sorting**: rerank-004-ordering-issues
- **thai**: thai-001-query-normalization, thai-002-thai-embedding, thai-003-keyword-boost, thai-004-tokenization
- **threshold**: thresh-001-semantic-threshold, thresh-002-rerank-threshold, thresh-003-context-limit, search-001-no-results, rerank-001-filter-too-much
- **tokenization**: thai-004-tokenization
- **tokens**: thresh-003-context-limit
- **truncation**: thresh-003-context-limit
- **unicode**: thai-001-query-normalization
- **vector**: embed-002-model-consistency, embed-003-dimension-mismatch
- **word-segmentation**: thai-004-tokenization
