#!/usr/bin/env node

const { execFileSync } = require('child_process');
const path = require('path');
const os = require('os');

const DB_PATH = path.join(os.homedir(), '.claude-mem', 'claude-mem.db');

const RULES = [
  // WORKFLOW Rules
  {
    title: '[RULE:WORKFLOW] Debug Flow - DISCOVERY DIAGNOSIS PROCESS',
    text: `[RULE:WORKFLOW][PROJECT:BotFacebook] Debug Workflow Flow

เมื่อ user แจ้ง bug/error ต้องทำตาม flow นี้:

1. DISCOVERY: ค้นหา memory ว่าเคยเจอปัญหานี้ไหม
2. DIAGNOSIS: หา root cause, อ่าน logs, ระบุ evidence
3. PROCESS: สร้าง GitHub Issue, ขอ approval, แก้ไข, verify, record learning

ห้าม Edit/Write ก่อนผ่าน workflow (มี PreToolUse hook บังคับ)`
  },
  {
    title: '[RULE:WORKFLOW] GitHub Issue First',
    text: `[RULE:WORKFLOW][PROJECT:BotFacebook] GitHub Issue First

ก่อนแก้ bug ต้องสร้าง GitHub Issue ก่อนเสมอ:
- Issue ต้องมี: title, description, root cause, proposed fix
- ต้องขอ user approval ก่อน edit code
- หลัง fix ต้อง close issue พร้อม reference commit`
  },
  {
    title: '[RULE:WORKFLOW] Record Learning After Fix',
    text: `[RULE:WORKFLOW][PROJECT:BotFacebook] Record Learning

หลังจาก fix bug เสร็จต้อง record learning:
- ใช้ tag ที่เหมาะสม: [PATTERN], [MISTAKE], [GOTCHA]
- สรุปสิ่งที่เรียนรู้ให้ชัดเจน
- เพื่อไม่ให้ทำผิดซ้ำ`
  },
  {
    title: '[RULE:WORKFLOW] Manual Test Before Verify',
    text: `[RULE:WORKFLOW][PROJECT:BotFacebook] Manual Test Required

ก่อน mark task เป็น verified ต้อง:
- รัน build ให้ผ่าน (npm run build)
- ทดสอบ manual ในเบราว์เซอร์จริง หรือใช้ Playwright
- ไม่ใช่แค่รัน build อย่างเดียว`
  },

  // BUDGET Rules
  {
    title: '[RULE:BUDGET] Use Agent for Exploration',
    text: `[RULE:BUDGET][UNIVERSAL] Agent for Exploration

เมื่อต้องค้นหาข้อมูลที่ไม่แน่ใจว่าอยู่ที่ไหน:
- ใช้ Task tool กับ subagent_type=Explore
- ไม่ใช่ Glob/Grep หลายรอบเอง
- ประหยัด token และได้ผลลัพธ์ดีกว่า`
  },
  {
    title: '[RULE:BUDGET] Memory Search Before File Read',
    text: `[RULE:BUDGET][PROJECT:BotFacebook] Memory First

ก่อนอ่านไฟล์เพื่อหาข้อมูล:
- ค้นหา memory ก่อน (mem-search)
- ถ้ามี observation ที่เกี่ยวข้อง ใช้ได้เลย
- ประหยัด token ไม่ต้องอ่านไฟล์ซ้ำ`
  },
  {
    title: '[RULE:BUDGET] Parallel Tool Calls',
    text: `[RULE:BUDGET][UNIVERSAL] Parallel Tool Calls

เมื่อต้องเรียก tools หลายตัวที่ไม่ depend กัน:
- เรียกพร้อมกันใน message เดียว
- ไม่ใช่เรียกทีละตัวรอผล
- ลด round trips ประหยัด token`
  },

  // AGENT Rules
  {
    title: '[RULE:AGENT] Explore Agent for Codebase Questions',
    text: `[RULE:AGENT][UNIVERSAL] When to Use Explore Agent

ใช้ Explore agent เมื่อ:
- ถาม "X อยู่ที่ไหน?"
- ถาม "codebase structure เป็นยังไง?"
- ต้องการหา pattern ในหลายไฟล์
- ไม่รู้ชื่อไฟล์/class ที่แน่นอน`
  },
  {
    title: '[RULE:AGENT] Plan Agent for Complex Tasks',
    text: `[RULE:AGENT][UNIVERSAL] When to Use Plan Agent

ใช้ Plan agent เมื่อ:
- ต้อง implement feature ใหม่
- task มีหลาย steps
- ต้องคิด architecture ก่อน
- ไม่แน่ใจว่าจะทำยังไง`
  },

  // OPTIMIZATION Rules
  {
    title: '[RULE:OPTIMIZATION] Thai Language for Thai Users',
    text: `[RULE:OPTIMIZATION][PROJECT:BotFacebook] Thai Language

BotFacebook เป็น project สำหรับ user ไทย:
- Error messages ควรเป็นภาษาไทย
- Validation messages ควรเป็นภาษาไทย
- UI text ควรเป็นภาษาไทย (ยกเว้น technical terms)`
  },
  {
    title: '[RULE:OPTIMIZATION] Laravel Config Null Gotcha',
    text: `[RULE:OPTIMIZATION][TECHNOLOGY:Laravel] Config Null Gotcha

config('key', '') ไม่ทำงานถ้า key มีค่า null:
- ใช้ config('key') ?? '' แทน
- หรือ env('KEY', '') ตรงๆ`
  },
  {
    title: '[RULE:OPTIMIZATION] API Response Wrapper',
    text: `[RULE:OPTIMIZATION][PROJECT:BotFacebook] API Response Wrapper

Backend return response ถูก wrap ด้วย {data: X}:
- Frontend ต้องใช้ response.data
- ไม่ใช่ response ตรงๆ`
  }
];

try {
  // Get a session ID to use
  const sessionResult = execFileSync('sqlite3', [
    DB_PATH,
    "SELECT sdk_session_id FROM sdk_sessions WHERE project LIKE '%BotFacebook%' ORDER BY id DESC LIMIT 1"
  ], { encoding: 'utf-8' }).trim();

  const sessionId = sessionResult || 'seed-rules-session';
  const now = new Date().toISOString();
  const nowEpoch = Math.floor(Date.now() / 1000);

  console.log('Seeding rules into memory...');
  console.log(`Using session: ${sessionId}`);
  console.log('');

  let seeded = 0;
  for (const rule of RULES) {
    // Escape single quotes for SQL
    const title = rule.title.replace(/'/g, "''");
    const text = rule.text.replace(/'/g, "''");

    const insertSQL = `INSERT INTO observations (sdk_session_id, project, title, text, type, created_at, created_at_epoch) VALUES ('${sessionId}', 'BotFacebook', '${title}', '${text}', 'decision', '${now}', ${nowEpoch})`;

    try {
      execFileSync('sqlite3', [DB_PATH, insertSQL], { encoding: 'utf-8' });
      console.log(`✓ ${rule.title}`);
      seeded++;
    } catch (err) {
      console.log(`✗ Failed: ${rule.title}`);
    }
  }

  console.log('');
  console.log(`Seeded ${seeded}/${RULES.length} rules successfully.`);

} catch (err) {
  console.error('Error:', err.message);
  process.exit(1);
}
