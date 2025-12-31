---
event: PostToolUse
trigger:
  - tool: Edit
  - tool: Write
condition: |
  (file_path matches "**/FlowController.php") OR
  (file_path matches "**/BotController.php") OR
  (file_path matches "**/WebhookController.php") OR
  (file_path matches "**/AIService.php") OR
  (file_path matches "**/ToolService.php") OR
  (content contains "agentic_mode" OR content contains "flow_id")
---

คุณเพิ่งแก้ไขไฟล์ที่เกี่ยวกับ Bot Flow หรือ AI Pipeline

**แนะนำ:** รัน `/facebook-bot-testing` เพื่อ:
- ทดสอบ bot flow ด้วย Playwright MCP
- ตรวจสอบ AI pipeline (decision model, KB, chat model)
- Verify chat responses
- Debug bot behavior
