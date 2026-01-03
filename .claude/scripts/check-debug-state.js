#!/usr/bin/env node
/**
 * Debug State Checker - PreToolUse Hook
 *
 * This script BLOCKS Edit/Write operations if debug workflow prerequisites aren't met.
 * It enforces: DISCOVERY → DIAGNOSIS → PROCESS flow
 */

const fs = require('fs');
const path = require('path');

const STATE_FILE = path.join(__dirname, '..', 'debug-state.json');

// Get the tool being used from environment (passed by Claude Code)
const toolName = process.env.CLAUDE_TOOL_NAME || 'unknown';
const filePath = process.env.CLAUDE_TOOL_INPUT_FILE_PATH || process.env.CLAUDE_TOOL_INPUT_PATH || '';

// Skip check for non-code files
const SKIP_PATTERNS = [
  /debug-state\.json$/,
  /\.claude\/scripts\//,
  /\.claude\/hooks/,
  /\.md$/,
  /\.json$/,
  /\.log$/,
  /\.txt$/,
  /node_modules/,
  /\.git\//,
  /package-lock\.json$/
];

function shouldSkip(filePath) {
  return SKIP_PATTERNS.some(pattern => pattern.test(filePath));
}

function loadState() {
  try {
    const content = fs.readFileSync(STATE_FILE, 'utf8');
    return JSON.parse(content);
  } catch (e) {
    // No state file = not in debug mode, allow operation
    return { active: false };
  }
}

function formatBlockMessage(state, missing) {
  const lines = [
    '',
    '╔══════════════════════════════════════════════════════════════╗',
    '║  ❌ BLOCKED: Debug Workflow Incomplete                       ║',
    '╠══════════════════════════════════════════════════════════════╣',
  ];

  lines.push(`║  Bug: ${(state.bug_description || 'Unknown').substring(0, 50).padEnd(50)} ║`);
  lines.push(`║  Phase: ${(state.phase || 'none').toUpperCase().padEnd(48)} ║`);
  lines.push('╠══════════════════════════════════════════════════════════════╣');
  lines.push('║  Missing Steps:                                              ║');

  missing.forEach(step => {
    lines.push(`║  → ${step.padEnd(55)} ║`);
  });

  lines.push('╠══════════════════════════════════════════════════════════════╣');
  lines.push('║  Next Actions:                                               ║');

  if (!state.steps_completed?.discovery?.memory_searched) {
    lines.push('║  1. Run: mem-search "[GOTCHA] <keyword>"                     ║');
    lines.push('║     Update state: node .claude/scripts/update-debug-state.js ║');
    lines.push('║                   discovery.memory_searched true             ║');
  } else if (!state.steps_completed?.diagnosis?.root_cause_identified) {
    lines.push('║  1. Run: diagnose logs / diagnose backend                    ║');
    lines.push('║  2. Identify root cause with evidence                        ║');
    lines.push('║  3. Update state with root cause                             ║');
  } else if (!state.steps_completed?.process?.issue_created) {
    lines.push('║  1. Create GitHub Issue: gh issue create                     ║');
    lines.push('║  2. Update state with issue_id                               ║');
  } else if (!state.steps_completed?.process?.user_approved) {
    lines.push('║  1. Ask user for approval                                    ║');
    lines.push('║  2. Update state: user_approved = true                       ║');
  }

  lines.push('╚══════════════════════════════════════════════════════════════╝');
  lines.push('');

  return lines.join('\n');
}

function checkState() {
  // Skip for non-code files
  if (shouldSkip(filePath)) {
    console.log(`✅ SKIP: ${filePath} (not code file)`);
    process.exit(0);
  }

  const state = loadState();

  // If not in active debug mode, allow
  if (!state.active) {
    console.log('✅ ALLOWED: No active debug session');
    process.exit(0);
  }

  const missing = [];
  const steps = state.steps_completed || {};

  // Check DISCOVERY phase
  if (!steps.discovery?.memory_searched) {
    missing.push('DISCOVERY: Memory not searched');
  }

  // Check DIAGNOSIS phase
  if (!steps.diagnosis?.logs_checked) {
    missing.push('DIAGNOSIS: Logs not checked');
  }
  if (!steps.diagnosis?.root_cause_identified) {
    missing.push('DIAGNOSIS: Root cause not identified');
  }

  // Check PROCESS phase
  if (!steps.process?.issue_created) {
    missing.push('PROCESS: GitHub Issue not created');
  }
  if (steps.process?.confidence < 80) {
    missing.push(`PROCESS: Confidence too low (${steps.process?.confidence || 0}% < 80%)`);
  }
  if (!steps.process?.user_approved) {
    missing.push('PROCESS: User approval not received');
  }

  // If any missing, BLOCK with exit code 2!
  if (missing.length > 0) {
    console.error(formatBlockMessage(state, missing));
    process.exit(2); // Exit code 2 = BLOCK tool in PreToolUse hook
  }

  // All checks passed
  console.log('✅ ALLOWED: All debug workflow steps completed');
  console.log(`   Issue: ${steps.process?.issue_id || 'N/A'}`);
  console.log(`   Confidence: ${steps.process?.confidence}%`);
  console.log(`   Approved: Yes`);
  process.exit(0);
}

checkState();
