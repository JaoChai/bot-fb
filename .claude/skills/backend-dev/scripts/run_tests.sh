#!/bin/bash
# Run backend tests with optional coverage

set -e

cd backend

echo "🧪 Running Backend Tests"
echo "========================"

# Parse arguments
COVERAGE=false
FILTER=""
PARALLEL=false

while [[ "$#" -gt 0 ]]; do
    case $1 in
        --coverage) COVERAGE=true ;;
        --filter) FILTER="$2"; shift ;;
        --parallel) PARALLEL=true ;;
        *) echo "Unknown parameter: $1"; exit 1 ;;
    esac
    shift
done

# Build command
CMD="php artisan test"

if [ "$COVERAGE" = true ]; then
    CMD="$CMD --coverage"
fi

if [ -n "$FILTER" ]; then
    CMD="$CMD --filter $FILTER"
fi

if [ "$PARALLEL" = true ]; then
    CMD="$CMD --parallel"
fi

echo "Running: $CMD"
echo ""

# Run tests
eval $CMD

# Check result
if [ $? -eq 0 ]; then
    echo ""
    echo "✅ All tests passed!"
else
    echo ""
    echo "❌ Some tests failed!"
    exit 1
fi
