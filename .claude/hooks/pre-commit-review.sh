#!/bin/bash
# Pre-Commit Code Review Hook
# Triggers code-reviewer agent before git commit

# Get the bash command from environment
BASH_COMMAND="${TOOL_INPUT_command:-}"

# Only trigger on git commit commands
if [[ "$BASH_COMMAND" != *"git commit"* ]]; then
    exit 0
fi

# Skip if it's amend or other special commits
if [[ "$BASH_COMMAND" == *"--amend"* ]]; then
    exit 0
fi

cat << 'EOF'

[PRE-COMMIT-REVIEW]
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔍 Git commit detected - Running pre-commit checks
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

BEFORE committing, you SHOULD spawn code-reviewer agent:

Task(subagent_type="code-reviewer", prompt="Review all staged changes before commit. Check:
1. Code follows project conventions
2. No console.log/dd() left in
3. TypeScript strict compliance
4. Laravel best practices
5. No hardcoded values
Return summary of issues found.")

If issues found → Fix before committing
If no issues → Proceed with commit

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
EOF

exit 0
