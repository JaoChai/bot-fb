#!/bin/bash
# Post-Implementation Hook (Suggestion Only)
# Suggests relevant skills after code changes

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

# Skip agent/hook/skill files
if [[ "$FILE_PATH" == */.claude/* ]]; then
    exit 0
fi

# Suggest relevant skills based on file type
if [[ "$FILE_PATH" == */frontend/* ]]; then
    echo ""
    echo "💡 Frontend change detected. Available skills:"
    echo "   /testing - UI/responsive testing"
    echo "   /code-review - Security + quality review"
    echo ""

elif [[ "$FILE_PATH" == */backend/* ]]; then
    if [[ "$FILE_PATH" == */migrations/* ]]; then
        echo ""
        echo "💡 Migration detected. Available skills:"
        echo "   /database-ops - Validate migration safety"
        echo ""
    elif [[ "$FILE_PATH" == */routes/* ]]; then
        echo ""
        echo "💡 API route change detected. Available skills:"
        echo "   /code-review - API design + security review"
        echo "   /testing - Backend API tests"
        echo ""
    else
        echo ""
        echo "💡 Backend change detected. Available skills:"
        echo "   /testing - PHPUnit tests"
        echo "   /code-review - Security + quality review"
        echo ""
    fi
fi

exit 0
