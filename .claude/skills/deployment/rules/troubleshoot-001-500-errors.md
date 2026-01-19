---
id: troubleshoot-001-500-errors
title: Debugging 500 Internal Server Errors
impact: CRITICAL
impactDescription: "Users seeing 500 errors, service partially broken"
category: troubleshoot
tags: [500, error, debugging, production]
relatedRules: [railway-003-log-analysis, env-001-required-vars]
---

## Symptom

- Users seeing "500 Internal Server Error"
- API endpoints returning 500 status
- Errors in Sentry dashboard
- Partial functionality broken

## Root Cause

1. Uncaught exception in code
2. Missing environment variable
3. Database connection failure
4. Memory exhaustion
5. Syntax error in code

## Diagnosis

### Quick Check

```bash
# Check for errors in logs
railway logs --filter "error|exception|500" --lines 100

# Check Sentry for details
# Use MCP: search_issues with "500 error"

# Test specific endpoint
curl -v https://api.botjao.com/api/failing-endpoint
```

### Detailed Analysis

```bash
# Get full error details
railway logs --filter "Exception\|Error\|Stack trace" --lines 200

# Check specific timeframe
railway logs --since "30 minutes ago" --filter "error" --lines 100

# Check environment
railway variables | grep -E "APP_ENV|APP_DEBUG|DATABASE_URL"
```

## Solution

### Fix Steps

1. **Enable debugging temporarily**
```bash
# Temporarily enable debug (REVERT AFTER!)
railway variables set APP_DEBUG=true

# Reproduce error
curl https://api.botjao.com/api/failing-endpoint

# Check detailed error in logs
railway logs --lines 50

# IMPORTANT: Revert debug mode
railway variables set APP_DEBUG=false
```

2. **Check common causes**
```bash
# Missing env var
railway logs --filter "undefined\|not found\|missing" --lines 50

# Database issues
railway logs --filter "SQLSTATE\|connection\|database" --lines 50

# Memory issues
railway logs --filter "memory\|exhausted\|allowed" --lines 50
```

3. **Use Sentry for details**
```
# Via MCP tool
Use get_issue_details with issueId

# Or analyze with Seer
Use analyze_issue_with_seer with issueUrl
```

4. **Fix specific issues**
```php
// Example: Missing null check
// Before (causes 500)
$user = User::find($id);
$name = $user->name;  // 500 if $user is null

// After (safe)
$user = User::find($id);
if (!$user) {
    return response()->json(['error' => 'User not found'], 404);
}
$name = $user->name;
```

### Common 500 Error Causes

| Error Type | Log Pattern | Fix |
|------------|-------------|-----|
| Null reference | "on null" | Add null checks |
| Missing class | "not found" | Check autoloading |
| DB connection | "SQLSTATE" | Check DATABASE_URL |
| Missing file | "failed to open" | Check file paths |
| Memory | "exhausted" | Increase limit or optimize |
| Syntax | "Parse error" | Fix code syntax |

### Debug Workflow

```bash
# 1. Get error message
railway logs --filter "error" --lines 50 | tail -20

# 2. Find stack trace
railway logs --filter "Stack trace" --lines 100

# 3. Identify file and line
# Look for: at /app/app/Http/Controllers/XXX.php:123

# 4. Check that code
cat app/Http/Controllers/XXX.php | head -130 | tail -20

# 5. Fix and deploy
git commit -m "fix: 500 error in XXX controller"
railway up
```

## Verification

```bash
# Test failing endpoint
curl -sf https://api.botjao.com/api/previously-failing-endpoint && echo "FIXED" || echo "STILL BROKEN"

# Check error logs
railway logs --filter "error" --lines 20 --since "5 minutes ago"
# Should be empty or reduced

# Monitor Sentry
# Check if error rate decreased
```

## Prevention

- Use proper error handling
- Add null checks
- Validate input data
- Use Laravel form requests
- Set up proper logging
- Monitor error rates in Sentry

## Project-Specific Notes

**BotFacebook Context:**
- Error tracking: Sentry
- Debug mode: Only enable temporarily
- Common issues: Null references, DB connections
- Monitoring: Sentry dashboard
