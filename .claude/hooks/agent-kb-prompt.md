---
event: UserPromptSubmit
condition: |
  prompt matches "(?i)(KB|knowledge base|embedding|vector|search quality|ค้นหา|RAG|semantic search|hybrid search|keyword search|threshold|ไม่พบข้อมูล|no results|relevance|chunk|document processing|reindex|similarity)"
---

# Auto-Trigger: Knowledge Base Agent

Detected KB/RAG keywords in user prompt.

**Invoking Knowledge Base Agent** to optimize search quality.

The agent will:
1. Check KB structure and health
2. Test search with sample queries
3. Analyze search result quality
4. Diagnose embedding issues
5. Recommend threshold adjustments
6. Explain search mode trade-offs

**Agent capabilities:**
- KB health diagnostics
- Search quality testing
- Threshold optimization (Thai: 0.50-0.60, English: 0.70-0.75)
- Embedding status verification
- Chunking analysis
- Database-level diagnostics

Please specify which bot's KB you want to analyze, or describe the search issue.
