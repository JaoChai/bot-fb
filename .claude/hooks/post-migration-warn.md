---
event: PostToolUse
trigger:
  - tool: Write
condition: file_path matches "backend/database/migrations/*.php"
---

คุณเพิ่งสร้าง migration file ใหม่

**สำคัญ:** ก่อน run `php artisan migrate` ให้รัน `/migration-validator` เพื่อตรวจสอบ:
- Breaking changes (drop columns, type changes)
- Foreign key integrity
- Data loss prevention
- Rollback strategy
