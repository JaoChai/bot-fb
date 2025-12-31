---
event: UserPromptSubmit
condition: |
  prompt matches "(?i)(แก้|fix|restart|clear|cache|migrate|rebuild|reindex|optimize|refresh|reset)"
---
# MCP Auto-Fix Hook

พบ keyword เกี่ยวกับการแก้ไขระบบในข้อความของ user

## Action Required
วิเคราะห์ context และใช้ MCP tool `fix` จาก botfacebook server:

## Safe Actions (ทำได้เลย)
- `fix({ action: "clear_cache", confirm: true })`
- `fix({ action: "clear_routes", confirm: true })`
- `fix({ action: "clear_views", confirm: true })`
- `fix({ action: "clear_config", confirm: true })`
- `fix({ action: "optimize", confirm: true })`

## Moderate Actions (แจ้ง user ก่อน)
- `fix({ action: "restart_queue", confirm: true })`
- `fix({ action: "rebuild_frontend", confirm: true })`
- `fix({ action: "migrate", confirm: true })`

## Dangerous Actions (ต้อง confirm จาก user)
- `fix({ action: "migrate_fresh", confirm: true })` - DROP ทุก table!
- `fix({ action: "seed", confirm: true })` - เขียนทับ data

## Keyword Mapping
| Keyword | Suggested Action |
|---------|-----------------|
| cache, clear | clear_cache หรือ clear_all |
| route | clear_routes |
| view | clear_views |
| config | clear_config |
| optimize | optimize |
| queue, restart | restart_queue |
| migrate | migrate |
| rebuild, frontend | rebuild_frontend |
| reindex, kb | reindex_kb (ต้องมี bot_id) |
