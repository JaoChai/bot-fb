---
event: PostToolUse
trigger:
  - tool: Edit
  - tool: Write
condition: |
  (file_path matches "backend/config/tools.php") OR
  (file_path matches "**/FlowEditorPage.tsx") OR
  (content contains "system_prompt" AND file_path matches "backend/**")
---

คุณเพิ่งแก้ไขไฟล์ที่เกี่ยวกับ prompts

**แนะนำ:** รัน `/prompt-engineer` เพื่อ:
- ตรวจสอบ prompt structure
- ดู best practices
- ทดสอบ prompt injection vulnerabilities
- ดู templates ที่พร้อมใช้
