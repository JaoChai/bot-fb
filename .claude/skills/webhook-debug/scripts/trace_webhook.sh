#!/bin/bash
# Trace webhook message flow from logs

set -e

# Parse arguments
BOT_ID=""
MESSAGE_ID=""
LINES=100

while [[ "$#" -gt 0 ]]; do
    case $1 in
        --bot) BOT_ID="$2"; shift ;;
        --message) MESSAGE_ID="$2"; shift ;;
        --lines) LINES="$2"; shift ;;
        *) echo "Unknown parameter: $1"; exit 1 ;;
    esac
    shift
done

echo "🔗 Webhook Trace"
echo "================"
echo "Bot ID: ${BOT_ID:-'all'}"
echo "Message ID: ${MESSAGE_ID:-'latest'}"
echo ""

# Build filter
FILTER="webhook"
if [ -n "$BOT_ID" ]; then
    FILTER="$FILTER.*bot.*$BOT_ID"
fi
if [ -n "$MESSAGE_ID" ]; then
    FILTER="$FILTER|$MESSAGE_ID"
fi

# Get logs from Railway
echo "📜 Fetching logs..."
echo ""

railway logs --lines $LINES --filter "$FILTER" 2>/dev/null || {
    echo "Note: Run this from a Railway-linked directory"
    echo "Or check logs manually with: railway logs --filter \"webhook\""
}

echo ""
echo "📊 Trace Summary:"
echo "================="
echo ""
echo "1. Webhook Received: Check for 'webhook' or 'incoming' in logs"
echo "2. Signature Valid: Check for 'signature' or '403' errors"
echo "3. Job Dispatched: Check for 'ProcessIncomingMessage' or 'queued'"
echo "4. AI Response: Check for 'OpenRouter' or 'model' references"
echo "5. Reply Sent: Check for 'reply' or 'sent' or platform API calls"
echo ""
echo "Common Issues:"
echo "- No webhook log: Check webhook URL configuration"
echo "- 403 error: Invalid signature - check channel secret"
echo "- Job failed: Check queue:failed for details"
echo "- No reply: Check platform credentials"
