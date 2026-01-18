---
name: rag-debug
description: RAG pipeline debugger for semantic search and knowledge base issues. Diagnoses embedding problems, search not finding results, reranker filtering too much, context not being used in responses. Use when search returns wrong results, knowledge base queries fail, Thai language search has problems, or AI responses ignore retrieved context.
---

# RAG Pipeline Debugger

Debug และวิเคราะห์ RAG pipeline สำหรับ BotFacebook.

## Quick Start

เมื่อมีปัญหา RAG ให้ถามตัวเอง:
1. **Search เจอไหม?** → ตรวจ embedding + threshold
2. **ผลลัพธ์ตรงไหม?** → ตรวจ chunking + reranker
3. **AI ใช้ context ไหม?** → ตรวจ context injection

## MCP Tools Available

- **neon**: `run_sql`, `explain_sql_statement` - Check embeddings and search queries
- **sentry**: `search_issues`, `analyze_issue_with_seer` - Find RAG pipeline errors
- **claude-mem**: `search`, `get_observations` - Search past RAG fixes

## Memory Search (Before Starting)

**Always search memory first** to find past RAG issues, threshold tuning, and search fixes.

### Recommended Searches

```
# Search for past RAG fixes
search(query="RAG search fix", project="bot-fb", type="bugfix", limit=5)

# Find threshold tuning decisions
search(query="threshold tuning", project="bot-fb", concepts=["trade-off"], limit=5)

# Search for embedding issues
search(query="embedding problem", project="bot-fb", concepts=["problem-solution"], limit=5)
```

### Search by Scenario

| Scenario | Search Query |
|----------|--------------|
| Search not finding results | `search(query="search no results threshold", project="bot-fb", type="bugfix", limit=5)` |
| Thai language issues | `search(query="Thai search embedding", project="bot-fb", concepts=["problem-solution"], limit=5)` |
| Reranker filtering too much | `search(query="reranker threshold", project="bot-fb", concepts=["trade-off"], limit=5)` |
| Chunking problems | `search(query="chunking size overlap", project="bot-fb", type="bugfix", limit=5)` |

### Using Search Results

1. Run relevant searches based on the RAG issue
2. Use `get_observations(ids=[...])` for full details on past fixes
3. Check if similar threshold or config changes were made before
4. Apply learnings to current debugging

## RAG Pipeline Overview

```
Query → QueryEnhancement → Search → Rerank → Context → LLM Response
         (optional)     (Semantic/   (Jina)
                        Keyword/
                        Hybrid)
```

## Debug Steps

### 1. Check Search Results

```sql
-- Check if document exists
SELECT id, content,
       1 - (embedding <=> $query_embedding::vector) as similarity
FROM knowledge_base_documents
WHERE bot_id = $bot_id
ORDER BY embedding <=> $query_embedding::vector
LIMIT 10;
```

**Expected:** Similarity > 0.7 for relevant docs

### 2. Check Threshold Settings

```php
// In config/rag.php or bot settings
'semantic_threshold' => 0.7,  // Too high = miss relevant
'rerank_threshold' => 0.5,    // Too high = filter too much
'max_context_tokens' => 4000, // Too low = incomplete context
```

### 3. Check Chunking Quality

```sql
-- Check chunk sizes
SELECT
    LENGTH(content) as chars,
    array_length(regexp_split_to_array(content, '\s+'), 1) as words
FROM knowledge_base_chunks
WHERE document_id = $doc_id;
```

**Expected:** 200-500 words per chunk

## Common Issues & Solutions

| Symptom | Likely Cause | Fix |
|---------|--------------|-----|
| ไม่เจอ document | Similarity threshold สูงเกินไป | ลด `semantic_threshold` เป็น 0.6 |
| เจอแต่ไม่ relevant | Chunking ไม่ดี | ปรับ chunk size, overlap |
| เจอแต่ถูก filter | Reranker threshold สูง | ลด `rerank_threshold` |
| Context ไม่พอ | Token limit ต่ำ | เพิ่ม `max_context_tokens` |
| ภาษาไทยไม่เจอ | Embedding model ไม่รองรับ | ใช้ multilingual model |

## Thai Language Specific

### Check Thai Query Processing

```php
// Ensure query is normalized
$query = normalize_thai($query);

// Check if using Thai-aware embedding
// Recommended: text-embedding-3-large (supports Thai)
```

### Thai Search Tips
- ใช้ hybrid search (semantic + keyword) สำหรับภาษาไทย
- เพิ่ม keyword boost สำหรับคำเฉพาะ
- ลด similarity threshold เล็กน้อย (0.65-0.7)

## Key Services

| File | Purpose |
|------|---------|
| `app/Services/RAGService.php` | Main orchestrator |
| `app/Services/SemanticSearchService.php` | pgvector cosine similarity |
| `app/Services/KeywordSearchService.php` | Full-text search |
| `app/Services/HybridSearchService.php` | Combined approach |
| `app/Services/JinaRerankerService.php` | Reranking results |
| `app/Services/EmbeddingService.php` | Generate embeddings |
| `app/Services/ChunkingService.php` | Document chunking |

## Detailed Guides

- **Pipeline Analysis**: See [PIPELINE_GUIDE.md](PIPELINE_GUIDE.md)
- **Threshold Tuning**: See [THRESHOLD_TUNING.md](THRESHOLD_TUNING.md)
- **Thai NLP**: See [THAI_NLP.md](THAI_NLP.md)

## Debug Output Format

```
🔍 RAG Debug Report
━━━━━━━━━━━━━━━━━━━━━━━
Query: [user query]
Bot ID: [bot_id]

📊 Pipeline Analysis:
1. Embedding: ✅/❌ [vector generated, dimension: 1536]
2. Search: ✅/❌ [found X results, top similarity: 0.XX]
3. Rerank: ✅/❌ [kept Y of X, top score: 0.XX]
4. Context: ✅/❌ [injected Z tokens]

🎯 Root Cause: [identified issue]

💡 Recommended Fix:
- [specific action with config values]
```

## Common Tasks

### Debug Search Not Finding Results

```markdown
1. Check document exists in knowledge_base
2. Verify embedding was generated (not null)
3. Run manual similarity query in SQL
4. Check semantic_threshold setting
5. Try lowering threshold to 0.5
6. Check if reranker filters too much
```

### Fix Thai Language Search

```markdown
1. Verify using multilingual embedding model
2. Enable hybrid search (semantic + keyword)
3. Lower similarity threshold (0.65)
4. Add keyword boost for Thai terms
5. Test with normalized Thai query
```

### Improve Context Quality

```markdown
1. Review chunking settings (200-500 words)
2. Increase chunk overlap (50-100 words)
3. Raise max_context_tokens limit
4. Check reranker is ordering correctly
5. Verify context injection in prompt
```

### Reindex Documents

```markdown
1. Delete existing embeddings: DELETE FROM embeddings WHERE bot_id = X
2. Re-chunk documents with new settings
3. Generate new embeddings
4. Rebuild ivfflat index
5. Test search quality
```

## Gotchas

| Problem | Cause | Solution |
|---------|-------|----------|
| Embedding null | Generation failed | Check OpenAI API key, retry |
| Search returns 0 | Threshold too high | Lower `semantic_threshold` to 0.6 |
| Wrong results first | Missing reranker | Enable Jina reranker |
| Context ignored by AI | Prompt issue | Check context injection template |
| Slow search | Missing index | Create ivfflat index |
| Thai search bad | English-only model | Use `text-embedding-3-large` |
| Duplicate results | Same content chunks | Improve chunking, add dedup |

## Utility Scripts

- `scripts/test_search.py` - Test search with sample queries
- `scripts/analyze_embedding.py` - Analyze embedding quality
