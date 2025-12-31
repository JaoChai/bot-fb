---
event: UserPromptSubmit
condition: |
  prompt matches "(?i)(bot|บอท|KB|knowledge|flow|โฟลว์|conversation|สนทนา|document|เอกสาร|ทดสอบบอท|test bot)"
---
# MCP Auto-Bot Management Hook

พบ keyword เกี่ยวกับ Bot/KB/Flow management ในข้อความของ user

## Action Required
วิเคราะห์ context และใช้ MCP tool `bot_manage` จาก botfacebook server:

## Bot Actions
- ดู bot ทั้งหมด: `bot_manage({ action: "list_bots" })`
- ดู bot เฉพาะ: `bot_manage({ action: "get_bot", bot_id: <id> })`
- สร้าง bot: `bot_manage({ action: "create_bot", data: {...} })`
- แก้ไข bot: `bot_manage({ action: "update_bot", bot_id: <id>, data: {...} })`
- ลบ bot: `bot_manage({ action: "delete_bot", bot_id: <id>, data: { confirm: true } })`
- ทดสอบ bot: `bot_manage({ action: "test_bot", bot_id: <id>, message: "..." })`
- ทดสอบ LINE: `bot_manage({ action: "test_line", bot_id: <id> })`

## Flow Actions
- ดู flow: `bot_manage({ action: "list_flows", bot_id: <id> })`
- สร้าง flow: `bot_manage({ action: "create_flow", bot_id: <id>, data: {...} })`
- แก้ไข flow: `bot_manage({ action: "update_flow", bot_id: <id>, flow_id: <id>, data: {...} })`
- duplicate: `bot_manage({ action: "duplicate_flow", bot_id: <id>, flow_id: <id> })`
- set default: `bot_manage({ action: "set_default_flow", bot_id: <id>, flow_id: <id> })`
- ทดสอบ flow: `bot_manage({ action: "test_flow", bot_id: <id>, flow_id: <id>, message: "..." })`

## Knowledge Base Actions
- ดู KB: `bot_manage({ action: "get_kb", bot_id: <id> })`
- ค้นหา KB: `bot_manage({ action: "search_kb", bot_id: <id>, query: "..." })`
- ดู documents: `bot_manage({ action: "list_documents", bot_id: <id> })`
- ลบ document: `bot_manage({ action: "delete_document", bot_id: <id>, document_id: <id> })`
- reprocess: `bot_manage({ action: "reprocess_document", bot_id: <id>, document_id: <id> })`

## Conversation Actions
- ดู conversations: `bot_manage({ action: "list_conversations", bot_id: <id> })`
- ดูรายละเอียด: `bot_manage({ action: "get_conversation", bot_id: <id>, conversation_id: <id> })`
- ส่งข้อความ: `bot_manage({ action: "send_agent_message", bot_id: <id>, conversation_id: <id>, message: "..." })`
- handover: `bot_manage({ action: "toggle_handover", bot_id: <id>, conversation_id: <id> })`
- ปิด conversation: `bot_manage({ action: "close_conversation", bot_id: <id>, conversation_id: <id> })`

## Default Behavior
- ถ้าไม่ระบุ bot_id ให้ใช้ `list_bots` ก่อนแล้วให้ user เลือก
- ถ้าพูดถึง "ทุก bot" หรือ "ทั้งหมด" ให้ list ก่อน
