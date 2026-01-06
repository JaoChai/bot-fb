#!/usr/bin/env node
/**
 * UserPromptSubmit Hook - Detect Bug Keywords
 *
 * แสดง reminder เมื่อ user พูดถึง bug/error
 * และสร้าง debug-active.txt marker
 */

const fs = require('fs');
const path = require('path');

const BUG_KEYWORDS = [
  'error', 'bug', 'fix', 'crash', '500', '404', '502',
  'ล่ม', 'แก้', 'ปัญหา', 'ไม่ทำงาน', 'ไม่ตอบ', 'พัง',
  'failed', 'failure', 'broken', 'issue', 'wrong'
];

function main() {
  const userPrompt = process.env.CLAUDE_USER_PROMPT || '';
  const lowerPrompt = userPrompt.toLowerCase();
  const projectDir = process.env.CLAUDE_PROJECT_DIR || process.cwd();

  const foundKeyword = BUG_KEYWORDS.find(kw => lowerPrompt.includes(kw));

  if (foundKeyword) {
    // Create debug marker
    const markerPath = path.join(projectDir, '.claude', 'debug-active.txt');
    try {
      fs.writeFileSync(markerPath, userPrompt.substring(0, 100));
    } catch (e) {
      // Ignore errors
    }

    console.log(`
┌─────────────────────────────────────────────────────────────────┐
│  🐛 BUG DETECTED: "${foundKeyword}"
├─────────────────────────────────────────────────────────────────┤
│  AUTO DEBUG WORKFLOW:                                           │
│                                                                 │
│  1. Discovery Agent → ค้นหา memory                              │
│  2. Diagnosis Agent → วิเคราะห์ logs/code                       │
│  3. Process Agent   → แก้ไข (ถ้า confidence >= 80%)             │
│                                                                 │
│  ⚡ FULLY AUTOMATIC - No manual steps required                  │
└─────────────────────────────────────────────────────────────────┘
`);
  }
}

main();
