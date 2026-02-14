#!/bin/bash
# Backend Dead Code Scanner for Laravel
# Usage: bash backend-scan.sh [backend_path]

BACKEND_PATH="${1:-backend}"
APP_PATH="$BACKEND_PATH/app"
ROUTES_PATH="$BACKEND_PATH/routes"

echo "=== Backend Dead Code Scan ==="
echo ""

# 1. Unused Services
echo "## Unused Services"
for file in "$APP_PATH"/Services/*.php; do
    [ -f "$file" ] || continue
    classname=$(basename "$file" .php)
    # Search for usage outside the service file itself
    count=$(grep -rl "$classname" "$APP_PATH" "$ROUTES_PATH" --include="*.php" 2>/dev/null | grep -v "$file" | wc -l | tr -d ' ')
    if [ "$count" -eq 0 ]; then
        echo "  - $classname (0 references)"
    fi
done
echo ""

# 2. Unused Models
echo "## Unused Models"
for file in "$APP_PATH"/Models/*.php; do
    [ -f "$file" ] || continue
    classname=$(basename "$file" .php)
    count=$(grep -rl "$classname" "$APP_PATH" "$ROUTES_PATH" "$BACKEND_PATH/config" "$BACKEND_PATH/database" --include="*.php" 2>/dev/null | grep -v "$file" | wc -l | tr -d ' ')
    if [ "$count" -eq 0 ]; then
        echo "  - $classname (0 references)"
    fi
done
echo ""

# 3. Unused Jobs
echo "## Unused Jobs"
for file in "$APP_PATH"/Jobs/*.php; do
    [ -f "$file" ] || continue
    classname=$(basename "$file" .php)
    count=$(grep -rl "$classname" "$APP_PATH" "$ROUTES_PATH" --include="*.php" 2>/dev/null | grep -v "$file" | wc -l | tr -d ' ')
    if [ "$count" -eq 0 ]; then
        echo "  - $classname (0 references)"
    fi
done
echo ""

# 4. Unused Controllers
echo "## Unused Controllers"
for file in "$APP_PATH"/Http/Controllers/Api/*.php; do
    [ -f "$file" ] || continue
    classname=$(basename "$file" .php)
    count=$(grep -rl "$classname" "$ROUTES_PATH" --include="*.php" 2>/dev/null | wc -l | tr -d ' ')
    if [ "$count" -eq 0 ]; then
        echo "  - $classname (0 references in routes)"
    fi
done
echo ""

# 5. Unused Middleware
echo "## Unused Middleware"
for file in "$APP_PATH"/Http/Middleware/*.php; do
    [ -f "$file" ] || continue
    classname=$(basename "$file" .php)
    count=$(grep -rl "$classname" "$APP_PATH" "$ROUTES_PATH" "$BACKEND_PATH/bootstrap" --include="*.php" 2>/dev/null | grep -v "$file" | wc -l | tr -d ' ')
    if [ "$count" -eq 0 ]; then
        echo "  - $classname (0 references)"
    fi
done
echo ""

echo "=== Scan Complete ==="
echo "Note: Verify results manually - dynamic usage (resolve(), config()) may not be detected"
