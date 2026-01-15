#!/bin/bash
# Run E2E tests with Playwright

set -e

cd frontend

echo "🎭 Running E2E Tests (Playwright)"
echo "================================="
echo ""

# Parse arguments
HEADED=false
DEBUG=false
UI=false
GREP=""

while [[ "$#" -gt 0 ]]; do
    case $1 in
        --headed) HEADED=true ;;
        --debug) DEBUG=true ;;
        --ui) UI=true ;;
        --grep) GREP="$2"; shift ;;
        *) echo "Unknown parameter: $1"; exit 1 ;;
    esac
    shift
done

# Build command
CMD="npx playwright test"

if [ "$HEADED" = true ]; then
    CMD="$CMD --headed"
fi

if [ "$DEBUG" = true ]; then
    CMD="$CMD --debug"
fi

if [ "$UI" = true ]; then
    CMD="$CMD --ui"
fi

if [ -n "$GREP" ]; then
    CMD="$CMD --grep \"$GREP\""
fi

echo "Running: $CMD"
echo ""

# Run tests
eval $CMD

# Show report option
echo ""
echo "📊 To view the HTML report, run:"
echo "   npx playwright show-report"
