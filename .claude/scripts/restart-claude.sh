#!/bin/bash
# Claude Code Restart Helper
# Usage: ./.claude/scripts/restart-claude.sh [--resume]

set -e

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SETTINGS_FILE="$PROJECT_ROOT/.claude/settings.local.json"

echo "=================================================="
echo "  Claude Code Restart Helper"
echo "=================================================="

# Check if there's a pending task to resume
if [ -f "$SETTINGS_FILE" ]; then
    PENDING_TASK=$(cat "$SETTINGS_FILE" | grep -o '"pendingTask"' 2>/dev/null || echo "")
    if [ -n "$PENDING_TASK" ]; then
        echo ""
        echo "Found pending task to resume!"
        echo ""
    fi
fi

# Kill existing Claude Code processes (if any)
echo "Stopping any running Claude Code instances..."
pkill -f "claude" 2>/dev/null || true
sleep 1

# Change to project directory
cd "$PROJECT_ROOT"

echo ""
echo "Starting Claude Code..."
echo ""

# Start Claude Code
if [ "$1" == "--resume" ] || [ -n "$PENDING_TASK" ]; then
    echo "Mode: Resume pending task"
    echo ""
    # Start with resume flag - Claude will check settings.local.json
    claude --resume 2>/dev/null || claude
else
    echo "Mode: Fresh start"
    echo ""
    claude
fi
