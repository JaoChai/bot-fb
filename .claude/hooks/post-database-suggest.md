---
event: PostToolUse
trigger:
  - tool: Edit
  - tool: Write
condition: |
  (file_path matches "**/Models/*.php") OR
  (file_path matches "**/*Repository*.php") OR
  (content contains "DB::" OR content contains "->where(" OR content contains "->select(") OR
  (content contains "SQLSTATE" OR content contains "QueryException")
---

คุณเพิ่งแก้ไขไฟล์ที่เกี่ยวกับ Database

**แนะนำ:** รัน `/neon-database` เพื่อ:
- Query database ด้วย Neon MCP
- ตรวจสอบ schema
- Run migrations
- Debug database connection issues
