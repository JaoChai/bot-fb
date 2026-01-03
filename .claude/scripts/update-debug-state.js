#!/usr/bin/env node
/**
 * Debug State Manager
 *
 * Usage:
 *   node update-debug-state.js <command> [args...]
 *
 * Commands:
 *   start <description>           - Start new debug session
 *   discovery.memory_searched     - Mark memory as searched
 *   discovery.similar_found <id>  - Mark similar issue found (optional)
 *   diagnosis.logs_checked        - Mark logs as checked
 *   diagnosis.root_cause <cause>  - Set root cause
 *   diagnosis.add_evidence <ev>   - Add evidence
 *   process.issue <id> <url>      - Set GitHub issue
 *   process.confidence <number>   - Set confidence (0-100)
 *   process.approved              - Mark user approved
 *   process.fixed                 - Mark fix applied
 *   process.verified              - Mark fix verified
 *   process.learned               - Mark learning recorded
 *   process.closed                - Mark issue closed
 *   complete                      - Complete and reset state
 *   reset                         - Force reset state
 *   status                        - Show current status
 */

const fs = require('fs');
const path = require('path');

const STATE_FILE = path.join(__dirname, '..', 'debug-state.json');

const INITIAL_STATE = {
  active: false,
  bug_description: null,
  started_at: null,
  phase: null,
  steps_completed: {
    discovery: {
      memory_searched: false,
      similar_found: null
    },
    diagnosis: {
      logs_checked: false,
      root_cause_identified: false,
      root_cause: null,
      evidence: []
    },
    process: {
      issue_created: false,
      issue_id: null,
      issue_url: null,
      confidence: 0,
      user_approved: false,
      fix_applied: false,
      verified: false,
      learning_recorded: false,
      issue_closed: false
    }
  },
  attempt_count: 0,
  last_updated: null
};

function loadState() {
  try {
    const content = fs.readFileSync(STATE_FILE, 'utf8');
    return JSON.parse(content);
  } catch (e) {
    return { ...INITIAL_STATE };
  }
}

function saveState(state) {
  state.last_updated = new Date().toISOString();
  fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
}

function updatePhase(state) {
  const d = state.steps_completed.discovery;
  const di = state.steps_completed.diagnosis;
  const p = state.steps_completed.process;

  if (p.verified && p.learning_recorded) {
    state.phase = 'complete';
  } else if (p.user_approved) {
    state.phase = 'process';
  } else if (di.root_cause_identified) {
    state.phase = 'process';
  } else if (d.memory_searched) {
    state.phase = 'diagnosis';
  } else {
    state.phase = 'discovery';
  }
}

function showStatus(state) {
  const lines = [
    '',
    '╔══════════════════════════════════════════════════════════════╗',
    '║                    DEBUG SESSION STATUS                      ║',
    '╠══════════════════════════════════════════════════════════════╣',
  ];

  const active = state.active ? '🟢 ACTIVE' : '⚪ INACTIVE';
  lines.push(`║  Status: ${active.padEnd(49)} ║`);

  if (state.active) {
    lines.push(`║  Bug: ${(state.bug_description || '').substring(0, 52).padEnd(52)} ║`);
    lines.push(`║  Phase: ${(state.phase || 'unknown').toUpperCase().padEnd(50)} ║`);
    lines.push(`║  Started: ${(state.started_at || '').padEnd(48)} ║`);
    lines.push('╠══════════════════════════════════════════════════════════════╣');

    const d = state.steps_completed.discovery;
    const di = state.steps_completed.diagnosis;
    const p = state.steps_completed.process;

    lines.push('║  DISCOVERY:                                                  ║');
    lines.push(`║    ${d.memory_searched ? '✅' : '⬜'} Memory searched                                        ║`);
    lines.push(`║    ${d.similar_found ? '✅' : '⬜'} Similar issue found: ${(d.similar_found || 'none').toString().padEnd(29)} ║`);

    lines.push('║  DIAGNOSIS:                                                  ║');
    lines.push(`║    ${di.logs_checked ? '✅' : '⬜'} Logs checked                                           ║`);
    lines.push(`║    ${di.root_cause_identified ? '✅' : '⬜'} Root cause: ${(di.root_cause || 'not identified').substring(0, 36).padEnd(36)} ║`);
    lines.push(`║    Evidence count: ${(di.evidence?.length || 0).toString().padEnd(39)} ║`);

    lines.push('║  PROCESS:                                                    ║');
    lines.push(`║    ${p.issue_created ? '✅' : '⬜'} Issue: ${(p.issue_id || 'not created').padEnd(45)} ║`);
    lines.push(`║    ${p.confidence >= 80 ? '✅' : '⬜'} Confidence: ${p.confidence}%${' '.repeat(Math.max(0, 41 - p.confidence.toString().length))} ║`);
    lines.push(`║    ${p.user_approved ? '✅' : '⬜'} User approved                                          ║`);
    lines.push(`║    ${p.fix_applied ? '✅' : '⬜'} Fix applied                                            ║`);
    lines.push(`║    ${p.verified ? '✅' : '⬜'} Verified working                                       ║`);
    lines.push(`║    ${p.learning_recorded ? '✅' : '⬜'} Learning recorded                                      ║`);
    lines.push(`║    ${p.issue_closed ? '✅' : '⬜'} Issue closed                                           ║`);
  }

  lines.push('╚══════════════════════════════════════════════════════════════╝');
  lines.push('');

  console.log(lines.join('\n'));
}

function main() {
  const args = process.argv.slice(2);
  const command = args[0];

  if (!command) {
    console.log('Usage: node update-debug-state.js <command> [args...]');
    console.log('Run with "help" for full command list');
    process.exit(1);
  }

  let state = loadState();

  switch (command) {
    case 'start':
      const description = args.slice(1).join(' ') || 'Unknown bug';
      state = { ...INITIAL_STATE };
      state.active = true;
      state.bug_description = description;
      state.started_at = new Date().toISOString();
      state.phase = 'discovery';
      saveState(state);
      console.log(`✅ Debug session started: ${description}`);
      break;

    case 'discovery.memory_searched':
      state.steps_completed.discovery.memory_searched = true;
      updatePhase(state);
      saveState(state);
      console.log('✅ Marked: memory_searched = true');
      break;

    case 'discovery.similar_found':
      state.steps_completed.discovery.similar_found = args[1] || null;
      saveState(state);
      console.log(`✅ Marked: similar_found = ${args[1]}`);
      break;

    case 'diagnosis.logs_checked':
      state.steps_completed.diagnosis.logs_checked = true;
      updatePhase(state);
      saveState(state);
      console.log('✅ Marked: logs_checked = true');
      break;

    case 'diagnosis.root_cause':
      const cause = args.slice(1).join(' ');
      state.steps_completed.diagnosis.root_cause = cause;
      state.steps_completed.diagnosis.root_cause_identified = true;
      updatePhase(state);
      saveState(state);
      console.log(`✅ Set root cause: ${cause}`);
      break;

    case 'diagnosis.add_evidence':
      const evidence = args.slice(1).join(' ');
      state.steps_completed.diagnosis.evidence.push(evidence);
      saveState(state);
      console.log(`✅ Added evidence: ${evidence}`);
      break;

    case 'process.issue':
      state.steps_completed.process.issue_id = args[1];
      state.steps_completed.process.issue_url = args[2] || null;
      state.steps_completed.process.issue_created = true;
      updatePhase(state);
      saveState(state);
      console.log(`✅ Set issue: ${args[1]}`);
      break;

    case 'process.confidence':
      const confidence = parseInt(args[1]) || 0;
      state.steps_completed.process.confidence = confidence;
      saveState(state);
      console.log(`✅ Set confidence: ${confidence}%`);
      if (confidence < 80) {
        console.log('⚠️  Warning: Confidence < 80%, need more evidence');
      }
      break;

    case 'process.approved':
      state.steps_completed.process.user_approved = true;
      updatePhase(state);
      saveState(state);
      console.log('✅ Marked: user_approved = true');
      break;

    case 'process.fixed':
      state.steps_completed.process.fix_applied = true;
      state.attempt_count++;
      saveState(state);
      console.log(`✅ Marked: fix_applied = true (attempt #${state.attempt_count})`);
      break;

    case 'process.verified':
      state.steps_completed.process.verified = true;
      updatePhase(state);
      saveState(state);
      console.log('✅ Marked: verified = true');
      break;

    case 'process.learned':
      state.steps_completed.process.learning_recorded = true;
      updatePhase(state);
      saveState(state);
      console.log('✅ Marked: learning_recorded = true');
      break;

    case 'process.closed':
      state.steps_completed.process.issue_closed = true;
      saveState(state);
      console.log('✅ Marked: issue_closed = true');
      break;

    case 'complete':
      console.log('🎉 Debug session complete!');
      showStatus(state);
      state = { ...INITIAL_STATE };
      saveState(state);
      console.log('✅ State reset for next session');
      break;

    case 'reset':
      state = { ...INITIAL_STATE };
      saveState(state);
      console.log('✅ State forcefully reset');
      break;

    case 'status':
      showStatus(state);
      break;

    case 'help':
      console.log(`
Debug State Manager Commands:
────────────────────────────────────────────────────────────────
  start <description>           Start new debug session

  discovery.memory_searched     Mark memory as searched
  discovery.similar_found <id>  Mark similar issue found

  diagnosis.logs_checked        Mark logs as checked
  diagnosis.root_cause <cause>  Set root cause
  diagnosis.add_evidence <ev>   Add evidence

  process.issue <id> <url>      Set GitHub issue
  process.confidence <0-100>    Set confidence level
  process.approved              Mark user approved
  process.fixed                 Mark fix applied
  process.verified              Mark fix verified
  process.learned               Mark learning recorded
  process.closed                Mark issue closed

  complete                      Complete and reset
  reset                         Force reset
  status                        Show current status
────────────────────────────────────────────────────────────────
      `);
      break;

    default:
      console.error(`Unknown command: ${command}`);
      console.log('Run with "help" for command list');
      process.exit(1);
  }
}

main();
