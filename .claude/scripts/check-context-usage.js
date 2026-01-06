#!/usr/bin/env node
/**
 * PreToolUse Hook - Context Usage Warning
 *
 * Reads context % from Claude transcript and shows warnings
 * Does NOT block - only displays reminders
 */

const fs = require('fs');
const path = require('path');
const os = require('os');

// Thresholds
const THRESHOLD_TIP = 50;      // Show tip
const THRESHOLD_WARNING = 70;  // Show warning box
const THRESHOLD_CRITICAL = 90; // Show critical alert

// Cache to avoid parsing every call
const CACHE_FILE = path.join(os.tmpdir(), 'claude-context-cache.json');
const CACHE_TTL = 5000; // 5 seconds

function getCachedUsage() {
  try {
    if (fs.existsSync(CACHE_FILE)) {
      const cache = JSON.parse(fs.readFileSync(CACHE_FILE, 'utf8'));
      if (Date.now() - cache.timestamp < CACHE_TTL) {
        return cache.usage;
      }
    }
  } catch (e) {
    // Ignore cache errors
  }
  return null;
}

function setCachedUsage(usage) {
  try {
    fs.writeFileSync(CACHE_FILE, JSON.stringify({
      timestamp: Date.now(),
      usage: usage
    }));
  } catch (e) {
    // Ignore cache errors
  }
}

function findTranscriptFile() {
  // Get project-specific directory from env or derive from cwd
  const projectDir = process.env.CLAUDE_PROJECT_DIR || process.cwd();
  // Claude uses format: -Users-jaochaio-Code-BotFacebook (leading hyphen)
  const projectSlug = projectDir.replace(/\//g, '-');
  const claudeProjectDir = path.join(os.homedir(), '.claude', 'projects', projectSlug);

  if (!fs.existsSync(claudeProjectDir)) {
    return null;
  }

  // Find most recent .jsonl file in project directory
  const files = fs.readdirSync(claudeProjectDir)
    .filter(f => f.endsWith('.jsonl'))
    .map(f => ({
      name: f,
      path: path.join(claudeProjectDir, f),
      mtime: fs.statSync(path.join(claudeProjectDir, f)).mtimeMs
    }))
    .sort((a, b) => b.mtime - a.mtime);

  return files.length > 0 ? files[0].path : null;
}

function parseContextUsage(transcriptPath) {
  try {
    const content = fs.readFileSync(transcriptPath, 'utf8');
    const lines = content.trim().split('\n');

    // Read last 50 lines for efficiency
    const recentLines = lines.slice(-50);

    let totalInputTokens = 0;
    let totalOutputTokens = 0;
    let cacheReadTokens = 0;
    const contextWindow = 200000; // Claude context window

    for (const line of recentLines) {
      try {
        const data = JSON.parse(line);

        // Look for usage data in message.usage
        if (data.message && data.message.usage) {
          const usage = data.message.usage;
          totalInputTokens = usage.input_tokens || 0;
          totalOutputTokens = usage.output_tokens || 0;
          cacheReadTokens = usage.cache_read_input_tokens || 0;
        }
      } catch (e) {
        // Skip invalid lines
      }
    }

    // Calculate total context usage
    // Context = input tokens + cached tokens (what's in context)
    const contextTokens = totalInputTokens + cacheReadTokens;

    // Calculate percentage
    if (contextTokens > 0) {
      return Math.round((contextTokens / contextWindow) * 100);
    }

    return null;
  } catch (e) {
    return null;
  }
}

function showWarning(usage) {
  if (usage === null) {
    return; // Can't determine usage, skip
  }

  if (usage >= THRESHOLD_CRITICAL) {
    console.log(`
┌─────────────────────────────────────────────────────────────────┐
│  🔴 CONTEXT CRITICAL: ${usage}%                                   │
├─────────────────────────────────────────────────────────────────┤
│  Recommend: Start new session soon                              │
│  Risk: May lose context mid-task                                │
│                                                                 │
│  Actions:                                                       │
│  • Complete current task quickly                                │
│  • Avoid large file reads                                       │
│  • Consider /compact or new session                             │
└─────────────────────────────────────────────────────────────────┘
`);
  } else if (usage >= THRESHOLD_WARNING) {
    console.log(`
┌─────────────────────────────────────────────────────────────────┐
│  ⚠️  CONTEXT: ${usage}%                                           │
├─────────────────────────────────────────────────────────────────┤
│  Tips:                                                          │
│  • Use Explore/Discovery agents for searches                    │
│  • Parallel tool calls                                          │
│  • Keep outputs concise                                         │
└─────────────────────────────────────────────────────────────────┘
`);
  } else if (usage >= THRESHOLD_TIP) {
    console.log(`💡 Context: ${usage}% - Consider using agents for heavy tasks`);
  }
  // Below 50% - no message
}

function main() {
  // Check cache first
  let usage = getCachedUsage();

  if (usage === null) {
    // Parse transcript
    const transcriptPath = findTranscriptFile();
    if (transcriptPath) {
      usage = parseContextUsage(transcriptPath);
      if (usage !== null) {
        setCachedUsage(usage);
      }
    }
  }

  showWarning(usage);

  // Always exit 0 - don't block
  process.exit(0);
}

main();
