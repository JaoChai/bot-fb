#!/bin/bash
# Pre-Commit Code Review Hook (Suggestion Only)
# Suggests code-review skill before git commit

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

echo ""
echo "💡 Tip: Consider using /code-review skill before committing for:"
echo "   - Security audit (OWASP Top 10)"
echo "   - API design review"
echo "   - Code quality check"
echo ""

exit 0
