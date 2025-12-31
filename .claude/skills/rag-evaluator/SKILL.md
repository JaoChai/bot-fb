---
name: rag-evaluator
description: วัดและปรับปรุงคุณภาพ RAG pipeline - ใช้เมื่อต้องการ debug search quality, ปรับ threshold, หรือเปรียบเทียบ search modes (semantic vs hybrid vs keyword)
---

# RAG Quality Evaluator

ใช้ skill นี้เพื่อวิเคราะห์และปรับปรุงคุณภาพของ RAG pipeline

## Quick Diagnostics

### 1. ตรวจสอบ Search Results ว่างเปล่า

```bash
# ดู logs ใน Laravel
cd backend && tail -f storage/logs/laravel.log | grep -E "(SemanticSearch|HybridSearch|similarity)"
```

**สาเหตุที่พบบ่อย:**
| ปัญหา | วิธีแก้ |
|-------|--------|
| Threshold สูงเกินไป | ลด `kb_relevance_threshold` เป็น 0.5-0.6 สำหรับภาษาไทย |
| ไม่มี embeddings | ตรวจสอบ `document_chunks.embedding IS NOT NULL` |
| KB ไม่ได้เชื่อมกับ Flow | ตรวจสอบ `flow_knowledge_base` pivot table |
| API key หมดอายุ | ตรวจสอบ OpenRouter API key ใน Settings |

### 2. ตรวจสอบ Threshold ที่เหมาะสม

**แนะนำ Threshold ตาม Language:**
| Language | Recommended Threshold | Reason |
|----------|----------------------|--------|
| English | 0.70-0.75 | Embeddings ทำงานได้ดี |
| Thai | 0.50-0.60 | Thai tokenization ต่างจาก English |
| Mixed | 0.55-0.65 | ปรับตามสัดส่วน |

### 3. เปรียบเทียบ Search Modes

| Mode | Use Case | Pros | Cons |
|------|----------|------|------|
| **Semantic** | คำถาม conceptual | เข้าใจ meaning | ช้ากว่า, ต้องมี embeddings |
| **Keyword** | exact match | เร็ว, แม่นยำ | ไม่เข้าใจ synonyms |
| **Hybrid** | general use | รวมข้อดีทั้งสอง | ซับซ้อนกว่า |

---

## Retrieval Quality Checklist

### Before Deployment
- [ ] ทดสอบ queries 10+ ตัวอย่างจาก KB
- [ ] ตรวจสอบว่า threshold เหมาะสม
- [ ] ทดสอบ edge cases (typos, synonyms, mixed language)
- [ ] ดู top-3 results ว่า relevant หรือไม่

### Debug Process
1. **Get actual similarity scores**
   ```sql
   SELECT content, 1 - (embedding <=> '[query_embedding]') as similarity
   FROM document_chunks
   WHERE knowledge_base_id = ?
   ORDER BY embedding <=> '[query_embedding]'
   LIMIT 10;
   ```

2. **Compare with threshold**
   - ถ้า similarity < threshold ทุกตัว → ลด threshold
   - ถ้า irrelevant results เยอะ → เพิ่ม threshold

3. **Check RRF fusion scores** (Hybrid mode)
   - ดูว่า semantic และ keyword ให้ผลต่างกันมากไหม
   - ถ้าต่างมาก อาจต้อง weight ใหม่

---

## Integration with Evaluation System

ใช้ร่วมกับ Evaluation System ที่มีอยู่:

### Metrics ที่เกี่ยวข้อง
| Metric | Weight | Description |
|--------|--------|-------------|
| Answer Relevancy | 0.25 | คำตอบตรงคำถามหรือไม่ |
| Faithfulness | 0.25 | คำตอบ based on KB หรือไม่ |
| Context Precision | 0.15 | Retrieved chunks เกี่ยวข้องหรือไม่ |

### วิธี Run Evaluation
```bash
# ใน frontend, ไปที่ Bot > Evaluations > Run New Evaluation
# เลือก personas และจำนวน test cases
# ดูผลที่ Evaluation Report
```

---

## Common Issues & Solutions

### Issue 1: "ไม่พบข้อมูลที่เกี่ยวข้อง" ทั้งที่มีใน KB

**วิธีตรวจสอบ:**
```php
// ใน tinker
$chunks = \App\Models\DocumentChunk::where('knowledge_base_id', $kbId)
    ->whereNotNull('embedding')
    ->count();
echo "Chunks with embeddings: $chunks";
```

**Solutions:**
1. Re-process documents: ลบ document แล้ว upload ใหม่
2. Check API key: ใน Settings > API Keys
3. Lower threshold: ใน Bot Settings

### Issue 2: ได้ผลลัพธ์ที่ไม่เกี่ยวข้อง

**วิธีตรวจสอบ:**
- ดู actual chunks ที่ retrieved ใน logs
- เปรียบเทียบ similarity scores

**Solutions:**
1. เพิ่ม threshold
2. ใช้ Jina Reranking (ถ้ายังไม่ได้เปิด)
3. ปรับปรุง KB content ให้ชัดเจนขึ้น

### Issue 3: Hybrid Search ช้า

**วิธีตรวจสอบ:**
```bash
# ดู query time ใน logs
grep "search completed in" storage/logs/laravel.log
```

**Solutions:**
1. ลด `kb_max_results` (5-10 เพียงพอ)
2. ใช้ semantic only ถ้า keyword ไม่จำเป็น
3. ตรวจสอบ index ใน PostgreSQL

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `backend/app/Services/HybridSearchService.php` | Main search orchestration |
| `backend/app/Services/SemanticSearchService.php` | Vector search (pgvector) |
| `backend/app/Services/KeywordSearchService.php` | Full-text search |
| `backend/app/Services/RAGService.php` | RAG pipeline orchestration |
| `backend/config/rag.php` | RAG configuration |
