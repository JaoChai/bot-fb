---
event: PostToolUse
trigger:
  - tool: mcp__botfacebook__bot_manage
condition: |
  (output contains "search_kb") AND
  ((output contains "\"results\": []") OR
   (output contains "\"results\":[]") OR
   (output contains "ไม่พบข้อมูล") OR
   (output contains "No results found") OR
   (output contains "\"count\": 0"))
---

# Auto-Trigger: Knowledge Base Agent

Empty KB search results detected.

**Invoking Knowledge Base Agent** to diagnose the issue.

The agent will:
1. Verify embeddings exist for documents
2. Check current threshold settings
3. Test with simpler/broader queries
4. Analyze similarity scores
5. Recommend threshold adjustments
6. Suggest content improvements if needed

**Diagnosing empty search results...**
