#!/usr/bin/env node
/**
 * Learning Review Checker - SessionStart Hook
 *
 * Checks if it's time to review learnings and reminds the user.
 * Triggers every 7 days or if never reviewed.
 */

const fs = require('fs');
const path = require('path');

const STATE_FILE = path.join(__dirname, '..', 'learning-state.json');
const REVIEW_INTERVAL_DAYS = 7;

function loadState() {
  try {
    const content = fs.readFileSync(STATE_FILE, 'utf8');
    return JSON.parse(content);
  } catch (e) {
    return {
      last_review: null,
      rules_created: 0,
      total_reviews: 0
    };
  }
}

function daysSince(dateStr) {
  if (!dateStr) return Infinity;
  const then = new Date(dateStr);
  const now = new Date();
  const diffMs = now - then;
  return Math.floor(diffMs / (1000 * 60 * 60 * 24));
}

function check() {
  const state = loadState();
  const daysSinceReview = daysSince(state.last_review);

  if (daysSinceReview >= REVIEW_INTERVAL_DAYS) {
    console.log('');
    console.log('╔══════════════════════════════════════════════════════════════╗');
    console.log('║  📚 LEARNING REVIEW REMINDER                                 ║');
    console.log('╠══════════════════════════════════════════════════════════════╣');

    if (state.last_review === null) {
      console.log('║  ยังไม่เคย review learnings เลย                               ║');
    } else {
      console.log(`║  Last review: ${daysSinceReview} days ago                                      ║`);
    }

    console.log('║                                                              ║');
    console.log('║  รัน: /review-learnings                                      ║');
    console.log('║  เพื่อวิเคราะห์ patterns และสร้าง rules ใหม่                    ║');
    console.log('╚══════════════════════════════════════════════════════════════╝');
    console.log('');
  }
}

check();
