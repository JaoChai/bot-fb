---
event: PostToolUse
trigger:
  - tool: Edit
  - tool: Write
condition: |
  (file_path matches "**/PersonaService.php") OR
  (file_path matches "**/QueryEnhancementService.php") OR
  (content contains "detectLanguage") OR
  (content contains "Thai" AND file_path matches "backend/**") OR
  (content contains "ภาษาไทย")
---

คุณเพิ่งแก้ไขไฟล์ที่เกี่ยวกับ Thai language processing

**แนะนำ:** รัน `/thai-nlp` เพื่อ:
- ตรวจสอบ similarity threshold สำหรับภาษาไทย (แนะนำ 0.5-0.6)
- ดู Thai abbreviation mappings
- ตรวจสอบ complexity keywords
- Debug language detection
