---
event: PostToolUse
trigger:
  - tool: Edit
  - tool: Write
condition: |
  (file_path matches "backend/app/Services/*Search*.php") OR
  (file_path matches "backend/app/Services/RAGService.php") OR
  (file_path matches "backend/app/Services/EmbeddingService.php") OR
  (file_path matches "backend/config/rag.php")
---

คุณเพิ่งแก้ไขไฟล์ที่เกี่ยวกับ RAG pipeline

**แนะนำ:** รัน `/rag-evaluator` เพื่อตรวจสอบผลกระทบต่อ search quality
- ดู threshold ว่าเหมาะสมหรือไม่
- ทดสอบ retrieval quality
- เปรียบเทียบ search modes
