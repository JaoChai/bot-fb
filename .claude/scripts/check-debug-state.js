#!/usr/bin/env node
/**
 * PreToolUse Hook - Safety Net for Debug Workflow
 *
 * Reminds to use Process Agent for bug fixes.
 * Does NOT hard block - just shows reminder.
 */

const fs = require('fs');
const path = require('path');

// Check if there's an active debug session marker
const projectDir = process.env.CLAUDE_PROJECT_DIR || process.cwd();
const debugMarkerPath = path.join(projectDir, '.claude', 'debug-active.txt');

function main() {
  // Only show reminder if debug marker exists
  if (fs.existsSync(debugMarkerPath)) {
    const content = fs.readFileSync(debugMarkerPath, 'utf8').trim();

    console.log(`
┌─────────────────────────────────────────────────────────────────┐
│  ⚠️  DEBUG SESSION ACTIVE                                       │
├─────────────────────────────────────────────────────────────────┤
│  Bug: ${content.substring(0, 50)}
│                                                                 │
│  Reminder: Use Process Agent for bug fixes                      │
│  → discovery → diagnosis → process (if confidence >= 80%)       │
└─────────────────────────────────────────────────────────────────┘
`);
  }

  // Always exit 0 - don't block, just remind
  process.exit(0);
}

main();
