#!/usr/bin/env node

const { execFileSync } = require('child_process');
const path = require('path');
const os = require('os');

const DB_PATH = path.join(os.homedir(), '.claude-mem', 'claude-mem.db');

try {
  // Use custom separator to handle multiline text
  const queryWithSep = `
    SELECT '<<<RULE>>>' || id || '<<<SEP>>>' || title || '<<<SEP>>>' || text
    FROM observations
    WHERE text LIKE '%[RULE:%'
      AND text IS NOT NULL
      AND length(text) > 50
    ORDER BY id DESC
    LIMIT 30
  `.replace(/\n/g, ' ').trim();

  const result = execFileSync('sqlite3', [DB_PATH, queryWithSep], {
    encoding: 'utf-8',
    timeout: 5000
  }).trim();

  if (!result) {
    console.log('[RULES:EMPTY] No rules found in memory. Rules need to be seeded.');
    process.exit(0);
  }

  // Split by our custom marker
  const ruleEntries = result.split('<<<RULE>>>').filter(Boolean);

  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                    RULES FROM MEMORY                         ║');
  console.log('╠══════════════════════════════════════════════════════════════╣');
  console.log(`║  Found: ${ruleEntries.length} rules - APPLY THROUGHOUT SESSION             ║`);
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');

  ruleEntries.forEach((entry, i) => {
    const parts = entry.split('<<<SEP>>>');
    const id = parts[0];
    const title = parts[1] || '';
    const text = parts[2] || '';

    // Get first line of text (the tag line)
    const firstLine = text.split('\n')[0] || '';

    console.log(`[${i + 1}] ${title}`);
    console.log(`    ${firstLine}`);
    console.log('');
  });

} catch (err) {
  console.log('[RULES:ERROR] ' + (err.message || 'Unknown error'));
  process.exit(0); // Don't fail the hook
}
