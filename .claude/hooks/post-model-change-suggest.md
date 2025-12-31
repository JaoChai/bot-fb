---
event: PostToolUse
trigger:
  - tool: Edit
  - tool: Write
condition: |
  (content contains "primary_chat_model" OR content contains "decision_model" OR content contains "fallback_model") OR
  (file_path matches "**/OpenRouterService.php") OR
  (file_path matches "backend/config/services.php" AND content contains "openrouter")
---

คุณเพิ่งแก้ไข LLM model configuration

**แนะนำ:** รัน `/cost-monitor` เพื่อ:
- เปรียบเทียบราคา models
- ประมาณ cost per conversation
- ดู optimization tips
- วางแผน monthly budget
