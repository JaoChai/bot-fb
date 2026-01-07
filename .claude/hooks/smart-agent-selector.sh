#!/bin/bash
# Smart Agent + Skill + MCP Selector Hook
# Analyzes user intent and auto-fetches relevant memory for bugs/features

# Read user prompt from stdin
USER_PROMPT=$(cat)

# Detect if this is a bug/feature request that needs memory search
NEEDS_MEMORY=false
SEARCH_QUERY=""

# Bug patterns (Thai + English)
if echo "$USER_PROMPT" | grep -qiE 'bug|error|fix|แก้|ผิดพลาด|ไม่ทำงาน|ไม่ได้|พัง|crash|fail|broken|issue|problem|ปัญหา'; then
  NEEDS_MEMORY=true
  # Extract key terms for search
  SEARCH_QUERY=$(echo "$USER_PROMPT" | sed 's/[^a-zA-Z0-9ก-๙ ]//g' | head -c 100)
  MEMORY_REASON="🔴 Detected bug/fix request"
fi

# Feature patterns (Thai + English)
if echo "$USER_PROMPT" | grep -qiE 'feature|เพิ่ม|สร้าง|implement|add|create|develop|พัฒนา|ทำ.*ใหม่'; then
  NEEDS_MEMORY=true
  SEARCH_QUERY=$(echo "$USER_PROMPT" | sed 's/[^a-zA-Z0-9ก-๙ ]//g' | head -c 100)
  MEMORY_REASON="🟣 Detected feature request"
fi

# Output the smart-set system info
cat << 'EOF'
[SMART-SET-SYSTEM]

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🎯 UNIFIED AGENT + SKILL + MCP SELECTION
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Read .claude/sets.json for full configuration.

📋 AVAILABLE SETS (15):

🎨 frontend-dev    │ Agent: frontend-developer + Skills: ui-ux-pro-max, react-query-expert
⚙️ backend-dev     │ Agent: backend-developer + MCP: Context7, Neon
🗄️ database        │ Agent: db-manager + Skills: migration-validator + MCP: Neon
🔍 rag-debug       │ Agent: rag-debugger + Skills: rag-evaluator, thai-nlp + MCP: Neon
🔗 webhook-debug   │ Agent: webhook-tracer + Skills: line-expert, websocket-debugger
⚡ performance     │ Agent: performance-analyzer + Skills: react-query-expert + MCP: Neon
📝 code-review     │ Agent: code-reviewer + MCP: Context7, Memory
🔒 security        │ Agent: security-reviewer + MCP: Context7
🌐 api-design      │ Agent: api-designer + MCP: Context7
📱 ui-test         │ Agent: ui-tester + Skills: ui-ux-pro-max + MCP: Chrome
🧪 backend-test    │ Agent: backend-tester + MCP: Neon
🔄 integration-test│ Agent: integration-tester + Skills: e2e-test + MCP: Chrome, Neon
🚀 deployment      │ Skills: railway-deployer + MCP: Railway
💬 prompt-eng      │ Skills: prompt-engineer + MCP: Memory
📚 memory          │ Agent: memory-search + MCP: Memory

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🧠 DECISION PROCESS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

1. วิเคราะห์ user intent (ไม่ใช่แค่ keywords)
2. เลือก SET ที่เหมาะสมจาก sets.json
3. Execute ตาม set:

   IF agent has agentMode == "methodology":
      → Read .claude/agents/[name].md แล้วทำตาม methodology
      → Load skills ที่เกี่ยวข้อง (ใช้ Skill tool: /skill-name)
      → ใช้ MCP tools ตามที่กำหนด

   IF agent exists (no methodology mode):
      → Spawn agent: Task(subagent_type="[name]", prompt="...")
      → Agent จะ load skills และใช้ MCP เอง (ดูจาก agent file)

   IF agent == null:
      → Load skills โดยตรง (ใช้ Skill tool: /skill-name)
      → ใช้ MCP tools ตามที่กำหนด

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚡ AUTO-TRIGGER RULES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

• Before bug fix/feature → memory set ก่อน (หางานเก่า)
• After frontend edit → ui-test + security sets
• After backend edit → backend-test + security sets
• After route edit → api-design + backend-test + security sets
• After migration → database set (validate)
• Before commit → code-review set

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📌 EXECUTION FORMAT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

เมื่อเลือก set แล้ว ให้แสดงสั้นๆ:

🎯 Set: [icon] [set_name]
   Agent: [name] | Skills: [list] | MCP: [list]

แล้ว execute ทันที ไม่ต้องถาม confirm

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🚫 SKIP IF
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

• Simple greeting (hi, hello, สวัสดี)
• Questions about the system itself
• General questions unrelated to development
• User explicitly says "ไม่ต้องใช้ agent"

EOF

# If bug/feature detected, add memory search instruction
if [ "$NEEDS_MEMORY" = true ]; then
  cat << EOF

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🧠 AUTO-MEMORY SEARCH TRIGGERED
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$MEMORY_REASON

📋 MANDATORY ACTION: ก่อนตอบ user ต้องค้นหา memory ก่อน!

ใช้ MCP tool นี้ทันที:
\`\`\`
mcp__plugin_claude-mem_mem-search__search({
  query: "[extract key terms from user request]",
  project: "bot-fb",
  limit: 10,
  obs_type: "bugfix,feature,decision"
})
\`\`\`

ถ้าเจอ relevant results:
1. ดึง full observation ด้วย get_observations(ids=[...])
2. แสดง "📚 Found similar past work:" พร้อมสรุปสั้นๆ
3. แนะนำ solution จากงานเก่าถ้ามี
4. แล้วค่อยทำงานต่อ

ถ้าไม่เจอ:
1. แสดง "📚 No similar past work found"
2. ทำงานตามปกติ

EOF
fi

exit 0
