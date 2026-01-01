#!/usr/bin/env node
/**
 * Auto-sync USER-PROFILE.md from claude-mem
 * เรียนรู้ patterns การทำงานของ user อัตโนมัติ
 *
 * จับ:
 * 1. Decisions - การตัดสินใจ
 * 2. Skill patterns - skills ที่ใช้กับ context ไหน
 * 3. User corrections - เมื่อ user บอกว่าควรทำอะไร
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

// Paths
const DB_PATH = path.join(process.env.HOME, '.claude-mem', 'claude-mem.db');
const PROJECT_ROOT = path.resolve(__dirname, '../..');
const PROFILE_PATH = path.join(PROJECT_ROOT, '.claude', 'USER-PROFILE.md');
const SYNC_STATE_PATH = path.join(PROJECT_ROOT, '.claude', '.profile-sync-state.json');

// Known skills and their triggers
const SKILL_MAPPINGS = {
  'ui-ux-pro-max': ['ui', 'ux', 'design', 'หน้าตา', 'frontend', 'component', 'style'],
  'commit': ['commit', 'push', 'git'],
  'code-review': ['review', 'pr', 'pull request'],
  'feature-dev': ['feature', 'implement', 'สร้าง', 'เพิ่ม'],
  'e2e-test': ['test', 'e2e', 'testing']
};

// Get last synced ID
function getLastSyncedId() {
  try {
    if (fs.existsSync(SYNC_STATE_PATH)) {
      const state = JSON.parse(fs.readFileSync(SYNC_STATE_PATH, 'utf8'));
      return {
        decision: state.lastDecisionId || 0,
        skill: state.lastSkillObservationId || 0
      };
    }
  } catch {}
  return { decision: 0, skill: 0 };
}

// Save sync state
function saveSyncState(lastDecisionId, lastSkillId, stats) {
  fs.writeFileSync(SYNC_STATE_PATH, JSON.stringify({
    lastDecisionId,
    lastSkillObservationId: lastSkillId,
    lastSyncAt: new Date().toISOString(),
    ...stats
  }, null, 2));
}

// Query database
function queryDb(query) {
  if (!fs.existsSync(DB_PATH)) return [];

  try {
    const result = execFileSync('sqlite3', ['-json', DB_PATH, query], {
      encoding: 'utf8',
      stdio: ['pipe', 'pipe', 'pipe']
    });
    return JSON.parse(result || '[]');
  } catch {
    return [];
  }
}

// Get new decisions
function getNewDecisions(lastId) {
  return queryDb(`
    SELECT id, title, subtitle
    FROM observations
    WHERE project = 'BotFacebook'
      AND type = 'decision'
      AND id > ${lastId}
    ORDER BY id ASC
    LIMIT 10;
  `);
}

// Get observations that might contain skill patterns
function getSkillObservations(lastId) {
  // Look for observations that mention skills or user corrections
  return queryDb(`
    SELECT id, title, subtitle, content
    FROM observations
    WHERE project = 'BotFacebook'
      AND id > ${lastId}
      AND (
        title LIKE '%skill%'
        OR title LIKE '%ควร%'
        OR title LIKE '%ต้อง%'
        OR content LIKE '%เรียก skill%'
        OR content LIKE '%ใช้ skill%'
        OR content LIKE '%ui-ux%'
        OR subtitle LIKE '%auto%'
      )
    ORDER BY id ASC
    LIMIT 20;
  `);
}

// Extract skill patterns from observations
function extractSkillPatterns(observations) {
  const patterns = [];

  observations.forEach(obs => {
    const text = `${obs.title} ${obs.subtitle || ''} ${obs.content || ''}`.toLowerCase();

    // Check each skill
    for (const [skill, triggers] of Object.entries(SKILL_MAPPINGS)) {
      const matchedTriggers = triggers.filter(t => text.includes(t));

      if (matchedTriggers.length > 0 && text.includes('skill')) {
        patterns.push({
          skill,
          triggers: matchedTriggers,
          context: obs.title
        });
      }
    }

    // Check for user corrections (ควรใช้, ต้องใช้)
    const correctionMatch = text.match(/(ควร|ต้อง)(ใช้|เรียก)\s*(skill\s+)?([a-z0-9-]+)/);
    if (correctionMatch) {
      patterns.push({
        skill: correctionMatch[4],
        triggers: ['user-correction'],
        context: obs.title
      });
    }
  });

  return patterns;
}

// Analyze decision patterns
function analyzePatterns(decisions) {
  const patterns = {
    planFirst: 0,
    confirmation: 0,
    automation: 0,
    thai: 0
  };

  decisions.forEach(d => {
    const title = (d.title || '').toLowerCase();
    if (title.includes('plan') || title.includes('design')) patterns.planFirst++;
    if (title.includes('confirm') || title.includes('request')) patterns.confirmation++;
    if (title.includes('auto') || title.includes('workflow')) patterns.automation++;
    if (title.includes('thai') || title.includes('ภาษา')) patterns.thai++;
  });

  return patterns;
}

// Update USER-PROFILE.md
function updateProfile(decisions, skillPatterns) {
  if (decisions.length === 0 && skillPatterns.length === 0) {
    return false;
  }

  let content = fs.readFileSync(PROFILE_PATH, 'utf8');
  const now = new Date().toLocaleString('th-TH');

  // Update timestamp
  const statsText = `+${decisions.length} decisions, +${skillPatterns.length} skill patterns`;
  content = content.replace(
    /\*อัพเดทล่าสุด:.*\*/,
    `*อัพเดทล่าสุด: ${now} | ${statsText}*`
  );

  // Log skill patterns found (for debugging)
  if (skillPatterns.length > 0) {
    console.log('Skill patterns found:');
    skillPatterns.forEach(p => {
      console.log(`  - ${p.skill}: ${p.triggers.join(', ')}`);
    });
  }

  fs.writeFileSync(PROFILE_PATH, content);
  console.log(`Synced: ${decisions.length} decisions, ${skillPatterns.length} skill patterns`);
  return true;
}

// Main
function main() {
  if (!fs.existsSync(PROFILE_PATH)) {
    console.log('USER-PROFILE.md not found');
    process.exit(0);
  }

  const lastIds = getLastSyncedId();

  // Get new data
  const decisions = getNewDecisions(lastIds.decision);
  const skillObs = getSkillObservations(lastIds.skill);
  const skillPatterns = extractSkillPatterns(skillObs);

  if (updateProfile(decisions, skillPatterns)) {
    const maxDecisionId = decisions.length > 0
      ? Math.max(...decisions.map(d => d.id))
      : lastIds.decision;
    const maxSkillId = skillObs.length > 0
      ? Math.max(...skillObs.map(o => o.id))
      : lastIds.skill;

    saveSyncState(maxDecisionId, maxSkillId, {
      decisionsCount: decisions.length,
      skillPatternsCount: skillPatterns.length
    });
  }
}

main();
