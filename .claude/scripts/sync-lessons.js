#!/usr/bin/env node
/**
 * Auto-sync LESSONS.md from claude-mem database
 * ทำงานเบื้องหลัง ไม่เปลือง tokens
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

// Paths
const DB_PATH = path.join(process.env.HOME, '.claude-mem', 'claude-mem.db');
const PROJECT_ROOT = path.resolve(__dirname, '../..');
const LESSONS_PATH = path.join(PROJECT_ROOT, '.claude', 'LESSONS.md');
const SYNC_STATE_PATH = path.join(PROJECT_ROOT, '.claude', '.lessons-sync-state.json');

// Get last synced observation ID
function getLastSyncedId() {
  try {
    if (fs.existsSync(SYNC_STATE_PATH)) {
      const state = JSON.parse(fs.readFileSync(SYNC_STATE_PATH, 'utf8'));
      return state.lastObservationId || 0;
    }
  } catch {}
  return 0;
}

// Save sync state
function saveSyncState(lastId) {
  fs.writeFileSync(SYNC_STATE_PATH, JSON.stringify({
    lastObservationId: lastId,
    lastSyncAt: new Date().toISOString()
  }, null, 2));
}

// Query new bugfixes from database using execFileSync (safe)
function getNewBugfixes(lastId) {
  if (!fs.existsSync(DB_PATH)) {
    console.log('Database not found');
    return [];
  }

  const query = `SELECT id, title, COALESCE(subtitle, '') as subtitle, COALESCE(files_modified, '[]') as files_modified FROM observations WHERE project = 'BotFacebook' AND type = 'bugfix' AND id > ${lastId} ORDER BY id ASC LIMIT 10;`;

  try {
    const result = execFileSync('sqlite3', ['-json', DB_PATH, query], {
      encoding: 'utf8',
      stdio: ['pipe', 'pipe', 'pipe']
    });
    return JSON.parse(result || '[]');
  } catch {
    // Fallback for older sqlite3 without -json
    try {
      const fallbackQuery = `SELECT id || '|' || title || '|' || COALESCE(subtitle, '') FROM observations WHERE project = 'BotFacebook' AND type = 'bugfix' AND id > ${lastId} ORDER BY id ASC LIMIT 10;`;
      const result = execFileSync('sqlite3', [DB_PATH, fallbackQuery], {
        encoding: 'utf8',
        stdio: ['pipe', 'pipe', 'pipe']
      });

      return result.trim().split('\n').filter(Boolean).map(line => {
        const parts = line.split('|');
        return { id: parseInt(parts[0]), title: parts[1] || '', subtitle: parts[2] || '' };
      });
    } catch {
      return [];
    }
  }
}

// Format bugfix for LESSONS.md
function formatBugfix(bug) {
  const title = (bug.title || '').substring(0, 50);
  const subtitle = (bug.subtitle || '-').substring(0, 40);
  return `| ${title} | ${subtitle} | #${bug.id} |`;
}

// Update LESSONS.md with new entries
function updateLessons(bugfixes) {
  if (bugfixes.length === 0) {
    console.log('No new bugfixes to sync');
    return false;
  }

  let content = fs.readFileSync(LESSONS_PATH, 'utf8');
  const now = new Date().toLocaleString('th-TH');

  const newSection = `

### Auto-synced (${now})

| ผิดพลาด | วิธีแก้ | อ้างอิง |
|---------|--------|---------|
${bugfixes.map(formatBugfix).join('\n')}`;

  // Insert before "## Preferences" or "## Patterns" section
  const insertPoint = content.search(/## (Preferences|Patterns)/);
  if (insertPoint > -1) {
    content = content.slice(0, insertPoint) + newSection + '\n\n' + content.slice(insertPoint);
  } else {
    content += newSection;
  }

  // Update timestamp
  content = content.replace(
    /\*อัพเดทล่าสุด:.*\*/,
    `*อัพเดทล่าสุด: Auto-synced ${now}*`
  );

  fs.writeFileSync(LESSONS_PATH, content);
  console.log(`Synced ${bugfixes.length} bugfixes`);
  return true;
}

// Main
function main() {
  if (!fs.existsSync(LESSONS_PATH)) {
    console.log('LESSONS.md not found');
    process.exit(0);
  }

  const lastId = getLastSyncedId();
  const bugfixes = getNewBugfixes(lastId);

  if (updateLessons(bugfixes)) {
    const maxId = Math.max(...bugfixes.map(b => b.id));
    saveSyncState(maxId);
  }
}

main();
