---
event: PostToolUse
trigger:
  - tool: Edit
  - tool: Write
condition: |
  (file_path matches "**/LINEService.php") OR
  (file_path matches "**/WebhookController.php") OR
  (file_path matches "**/*Flex*.php") OR
  (content contains "flex message" OR content contains "LINE" OR content contains "webhook")
---

คุณเพิ่งแก้ไขไฟล์ที่เกี่ยวกับ LINE integration

**แนะนำ:** รัน `/line-expert` เพื่อ:
- ดู Flex Message templates
- ตรวจสอบ webhook configuration
- ดู error codes reference
- ตรวจสอบ rate limits
