# Decision Trees for RAG Debugging

## 1. Search Not Finding Results

```
Search returns no results
├── Embedding exists for query?
│   ├── NO → Check embedding generation
│   │   ├── API key valid?
│   │   └── Model available?
│   └── YES → Continue...
├── Documents exist in knowledge base?
│   ├── NO → Ingest documents first
│   └── YES → Continue...
├── Documents have embeddings?
│   ├── NO → Generate embeddings
│   │   └── Check for NULL embedding records
│   └── YES → Continue...
├── Similarity score > 0?
│   ├── NO → Check embedding dimensions match
│   └── YES → Continue...
├── Similarity score > threshold?
│   ├── NO → Lower semantic_threshold
│   │   └── Try 0.6, then 0.5, then 0.4
│   └── YES → Continue...
└── Reranker filtering?
    ├── YES → Check rerank_threshold
    │   └── Try lowering to 0.3
    └── NO → Issue elsewhere
```

## 2. Wrong Results (Relevance Issues)

```
Search returns wrong results
├── Query understanding issue?
│   ├── YES → Check query enhancement
│   │   ├── Query expansion enabled?
│   │   └── Synonyms configured?
│   └── NO → Continue...
├── Chunking issue?
│   ├── Chunks too large? → Reduce chunk_size
│   ├── Chunks too small? → Increase chunk_size
│   └── No overlap? → Add chunk_overlap
├── Embedding model issue?
│   ├── Wrong model for language? → Use multilingual
│   └── Model mismatch? → Verify same model for index/query
├── Reranker helping?
│   ├── NO → Enable Jina reranker
│   └── YES → Check reranker config
└── Context injection issue?
    └── Check prompt template
```

## 3. Thai Language Search

```
Thai search not working
├── Using Thai-compatible embedding?
│   ├── NO → Switch to text-embedding-3-large
│   └── YES → Continue...
├── Query normalized?
│   ├── NO → Add Thai normalization
│   └── YES → Continue...
├── Using hybrid search?
│   ├── NO → Enable hybrid (semantic + keyword)
│   └── YES → Continue...
├── Keyword boost configured?
│   ├── NO → Add Thai keyword boost
│   └── YES → Continue...
└── Threshold appropriate for Thai?
    └── Try lowering to 0.65
```

## 4. Performance Issues

```
Search is slow
├── Index exists?
│   ├── NO → Create ivfflat/hnsw index
│   └── YES → Continue...
├── Index type appropriate?
│   ├── < 100k vectors → Use hnsw
│   └── > 100k vectors → Use ivfflat
├── Query returning too many?
│   ├── YES → Lower limit, increase threshold
│   └── NO → Continue...
├── Reranking slow?
│   └── Reduce candidates before rerank
└── Connection pooling?
    └── Check Neon pool configuration
```

## 5. Reranker Issues

```
Reranker problems
├── Reranker enabled?
│   ├── NO → Enable in config
│   └── YES → Continue...
├── API key valid?
│   └── Check JINA_API_KEY
├── Results filtered out?
│   ├── YES → Lower rerank_threshold
│   └── NO → Continue...
├── Wrong ordering?
│   ├── Check model version
│   └── Verify score interpretation
└── Timeout issues?
    └── Reduce candidate count
```

## 6. Threshold Tuning Guide

| Scenario | semantic_threshold | rerank_threshold | Notes |
|----------|-------------------|------------------|-------|
| High precision | 0.8 | 0.6 | Strict matching |
| Balanced | 0.7 | 0.5 | Default |
| High recall | 0.6 | 0.4 | More results |
| Thai content | 0.65 | 0.45 | Accommodate language |
| Short queries | 0.6 | 0.4 | Single words need flexibility |

## 7. Embedding Model Selection

| Model | Dimensions | Languages | Speed | Quality |
|-------|------------|-----------|-------|---------|
| text-embedding-3-small | 1536 | Multi | Fast | Good |
| text-embedding-3-large | 3072 | Multi | Medium | Best |
| text-embedding-ada-002 | 1536 | Multi | Fast | Legacy |

**Recommendation:** Use `text-embedding-3-large` for Thai content.

## 8. Chunking Strategy

```
Determine chunk settings
├── Document type?
│   ├── FAQ → Small chunks (100-200 words)
│   ├── Articles → Medium chunks (200-400 words)
│   └── Technical docs → Larger chunks (400-600 words)
├── Query length expected?
│   ├── Short queries → Smaller chunks
│   └── Long queries → Larger chunks
└── Overlap needed?
    ├── Standalone content → 10-20% overlap
    └── Connected content → 30-50% overlap
```

## 9. Pipeline Debug Checklist

```markdown
□ 1. Query Received
  - Log original query
  - Check for empty/malformed input

□ 2. Query Enhancement
  - Check if expansion enabled
  - Verify synonyms applied

□ 3. Embedding Generation
  - Confirm vector generated
  - Verify dimensions correct

□ 4. Vector Search
  - Check candidates returned
  - Log similarity scores

□ 5. Reranking
  - Verify reranker called
  - Log pre/post rerank counts

□ 6. Context Assembly
  - Check token count
  - Verify context injection

□ 7. LLM Response
  - Confirm context used
  - Check for hallucination
```
