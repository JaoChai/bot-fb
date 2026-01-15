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

## Utility Scripts

- `scripts/test_search.py` - Test search with sample queries
- `scripts/analyze_embedding.py` - Analyze embedding quality
