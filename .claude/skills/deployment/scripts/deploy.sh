#!/bin/bash
# Deploy to Railway with pre-checks

set -e

echo "🚀 BotFacebook Deployment"
echo "========================="
echo ""

# Pre-deploy checks
echo "📋 Pre-deploy Checklist:"
echo ""

# Check git status
echo "1. Checking git status..."
if [ -n "$(git status --porcelain)" ]; then
    echo "   ⚠️  Uncommitted changes detected!"
    git status --short
    echo ""
    read -p "   Continue anyway? (y/N) " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
else
    echo "   ✅ Working directory clean"
fi

# Check branch
echo ""
echo "2. Checking branch..."
BRANCH=$(git branch --show-current)
echo "   Current branch: $BRANCH"
if [ "$BRANCH" != "main" ]; then
    echo "   ⚠️  Not on main branch!"
    read -p "   Continue anyway? (y/N) " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
else
    echo "   ✅ On main branch"
fi

# Run tests
echo ""
echo "3. Running tests..."
cd backend
if php artisan test --parallel 2>/dev/null; then
    echo "   ✅ All tests passed"
else
    echo "   ❌ Tests failed!"
    read -p "   Continue anyway? (y/N) " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi
cd ..

# Build frontend
echo ""
echo "4. Building frontend..."
cd frontend
if npm run build 2>/dev/null; then
    echo "   ✅ Build successful"
else
    echo "   ❌ Build failed!"
    exit 1
fi
cd ..

# Deploy
echo ""
echo "🚀 Deploying to Railway..."
echo ""

railway up --detach

echo ""
echo "✅ Deployment triggered!"
echo ""
echo "📊 Monitor deployment:"
echo "   railway logs -f"
echo ""
echo "🔍 After deployment, verify:"
echo "   curl https://api.botjao.com/health"
