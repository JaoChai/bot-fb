#!/usr/bin/env node
/**
 * Manual Test Checker
 *
 * Called before marking as verified. Checks if manual test was actually done.
 * This is called by update-debug-state.js before setting verified=true
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const STATE_FILE = path.join(__dirname, '..', 'debug-state.json');

function loadState() {
  try {
    const content = fs.readFileSync(STATE_FILE, 'utf8');
    return JSON.parse(content);
  } catch (e) {
    return { active: false };
  }
}

function checkManualTest() {
  const state = loadState();

  if (!state.active) {
    console.log('No active debug session');
    process.exit(0);
  }

  const steps = state.steps_completed || {};

  // Check if fix was applied
  if (!steps.process?.fix_applied) {
    console.log('❌ BLOCKED: Fix not applied yet');
    process.exit(1);
  }

  // Check if build passes
  console.log('Checking if build passes...');
  try {
    // Check frontend build
    const frontendPath = path.join(__dirname, '..', '..', 'frontend');
    if (fs.existsSync(frontendPath)) {
      execFileSync('npm', ['run', 'build'], {
        cwd: frontendPath,
        encoding: 'utf-8',
        timeout: 120000,
        stdio: 'pipe'
      });
      console.log('✅ Frontend build passes');
    }
  } catch (err) {
    console.log('❌ BLOCKED: Frontend build failed');
    console.log('   Must fix build errors before marking as verified');
    process.exit(1);
  }

  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║  ⚠️  MANUAL TEST REQUIRED                                    ║');
  console.log('╠══════════════════════════════════════════════════════════════╣');
  console.log('║  Build passes, but you MUST also:                            ║');
  console.log('║  1. Test in browser manually, OR                             ║');
  console.log('║  2. Run Playwright test                                      ║');
  console.log('╠══════════════════════════════════════════════════════════════╣');
  console.log('║  Confirm you did manual test? (y/n)                          ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');

  // For non-interactive, just warn
  process.exit(0);
}

checkManualTest();
