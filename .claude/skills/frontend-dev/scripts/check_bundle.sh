#!/bin/bash
# Check frontend bundle size

set -e

cd frontend

echo "📦 Building production bundle..."
npm run build 2>/dev/null

echo ""
echo "📊 Bundle Analysis:"
echo "==================="

# Check total size
TOTAL_SIZE=$(du -sh dist | cut -f1)
echo "Total dist size: $TOTAL_SIZE"

# Check JS files
echo ""
echo "JavaScript files:"
find dist -name "*.js" -exec du -h {} \; | sort -rh | head -10

# Check CSS files
echo ""
echo "CSS files:"
find dist -name "*.css" -exec du -h {} \; | sort -rh

# Check for large chunks (>200KB)
echo ""
echo "⚠️  Large chunks (>200KB):"
find dist -name "*.js" -size +200k -exec du -h {} \;

# Summary
echo ""
echo "📋 Summary:"
JS_SIZE=$(find dist -name "*.js" -exec cat {} \; | wc -c | awk '{printf "%.2f", $1/1024}')
CSS_SIZE=$(find dist -name "*.css" -exec cat {} \; | wc -c | awk '{printf "%.2f", $1/1024}')

echo "Total JS: ${JS_SIZE}KB"
echo "Total CSS: ${CSS_SIZE}KB"

# Check against target
TARGET_SIZE=500
if (( $(echo "$JS_SIZE > $TARGET_SIZE" | bc -l) )); then
    echo "❌ JS bundle exceeds ${TARGET_SIZE}KB target!"
    exit 1
else
    echo "✅ JS bundle within ${TARGET_SIZE}KB target"
fi
