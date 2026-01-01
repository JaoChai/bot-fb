---
name: bot-manage
enabled: true
event: prompt
conditions:
  - field: user_prompt
    operator: regex_match
    pattern: (?i)(bot|flow|บอท|โฟลว์|conversation|สนทนา|webhook|KB|knowledge|document)
---

## Auto-Trigger: Bot Management Tools Available

Detected bot/flow management keywords in user's message.

**Use MCP tools for bot operations:**

| Action | Command |
|--------|---------|
| List bots | `bot_manage({ action: "list_bots" })` |
| Get bot details | `bot_manage({ action: "get_bot", bot_id: X })` |
| Test bot | `bot_manage({ action: "test_bot", bot_id: X, message: "..." })` |
| List flows | `bot_manage({ action: "list_flows", bot_id: X })` |
| Search KB | `bot_manage({ action: "search_kb", kb_id: X, query: "..." })` |
| List conversations | `bot_manage({ action: "list_conversations", bot_id: X })` |

**For evaluation:** Use `mcp__botfacebook__evaluate()` instead.
