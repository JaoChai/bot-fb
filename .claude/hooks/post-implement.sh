#!/bin/bash

# Post-Implementation Auto-Spawn Hook
# Automatically triggers testing/review agents after code changes

# Get file path from environment
FILE_PATH="${TOOL_INPUT_file_path:-}"
TOOL_NAME="${TOOL_NAME:-}"

# Only trigger on Edit/Write completion
if [[ "$TOOL_NAME" != "Edit" && "$TOOL_NAME" != "Write" ]]; then
    exit 0
fi

# Skip if no file path
if [[ -z "$FILE_PATH" ]]; then
    exit 0
fi

# Skip non-code files
if [[ "$FILE_PATH" == *.md || "$FILE_PATH" == *.json || "$FILE_PATH" == *.yml || "$FILE_PATH" == *.yaml || "$FILE_PATH" == *.sh ]]; then
    exit 0
fi

# Skip test files
if [[ "$FILE_PATH" == *test* || "$FILE_PATH" == *spec* || "$FILE_PATH" == *Test.php ]]; then
    exit 0
fi

# Skip agent/hook files
if [[ "$FILE_PATH" == */.claude/* ]]; then
    exit 0
fi

echo ""
echo "[AUTO-AGENT-SPAWN]"
echo "Implementation completed: $FILE_PATH"
echo ""

# Detect and output spawn instructions
if [[ "$FILE_PATH" == */frontend/* ]]; then
    if [[ "$FILE_PATH" == *.tsx || "$FILE_PATH" == *.ts || "$FILE_PATH" == *.css ]]; then
        cat << 'EOF'
SPAWN REQUIRED - Frontend change detected:

1. IMMEDIATELY spawn ui-tester agent:
   Task(subagent_type="ui-tester", prompt="Test the UI changes in: $FILE_PATH. Check responsive design at 375px, 768px, 1280px. Verify accessibility. If issues found, load ui-ux-pro-max skill to fix.")

2. THEN spawn security-reviewer agent:
   Task(subagent_type="security-reviewer", prompt="Security scan frontend changes in: $FILE_PATH. Check for XSS vulnerabilities, exposed sensitive data, unsafe patterns.")

ACTION: Use Task tool NOW to spawn these agents.
EOF
    fi

elif [[ "$FILE_PATH" == */backend/* ]]; then
    if [[ "$FILE_PATH" == */migrations/* ]]; then
        cat << 'EOF'
SPAWN REQUIRED - Migration detected:

1. IMMEDIATELY spawn db-manager agent:
   Task(subagent_type="db-manager", prompt="Validate migration: $FILE_PATH. Check schema design, indexes, foreign keys. Use Neon MCP to test on branch before applying.")

ACTION: Use Task tool NOW to spawn this agent.
EOF

    elif [[ "$FILE_PATH" == */routes/* ]]; then
        cat << 'EOF'
SPAWN REQUIRED - API route change detected:

1. IMMEDIATELY spawn api-designer agent:
   Task(subagent_type="api-designer", prompt="Review API changes in routes. Check RESTful conventions, response format consistency, proper HTTP status codes.")

2. THEN spawn backend-tester agent:
   Task(subagent_type="backend-tester", prompt="Test the new/modified endpoints. Run PHPUnit tests, verify request validation, check authorization.")

3. ALSO spawn security-reviewer agent:
   Task(subagent_type="security-reviewer", prompt="Security audit API routes. Check authorization, rate limiting, input validation.")

ACTION: Use Task tool NOW to spawn these agents.
EOF

    elif [[ "$FILE_PATH" == *.php ]]; then
        cat << 'EOF'
SPAWN REQUIRED - Backend PHP change detected:

1. IMMEDIATELY spawn backend-tester agent:
   Task(subagent_type="backend-tester", prompt="Run tests for changes in: $FILE_PATH. Execute PHPUnit, verify functionality, check edge cases.")

2. THEN spawn security-reviewer agent:
   Task(subagent_type="security-reviewer", prompt="Security scan: $FILE_PATH. Check for SQL injection, command injection, auth bypass, data exposure.")

ACTION: Use Task tool NOW to spawn these agents.
EOF
    fi
fi

echo ""
echo "[END-AUTO-AGENT-SPAWN]"
