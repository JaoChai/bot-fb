#!/usr/bin/env python3
"""
Find potential N+1 query issues in Laravel codebase.
Usage: python find_n1_queries.py <path>
"""

import sys
import re
from pathlib import Path


class N1QueryFinder:
    # Patterns that suggest N+1 queries
    N1_PATTERNS = [
        # Accessing relationship in loop without eager loading
        (r'foreach\s*\([^)]+\)\s*\{[^}]*->\w+(?:->|\s)', 'Loop with relationship access'),
        (r'->map\s*\([^)]*->\w+(?:->|\[)', 'Collection map with relationship'),
        (r'->each\s*\([^)]*->\w+(?:->|\[)', 'Collection each with relationship'),

        # Missing eager loading patterns
        (r'::all\(\)', 'Using ::all() without eager loading'),
        (r'::get\(\)\s*(?!->)', 'Using ::get() - check for eager loading'),
    ]

    # Good patterns (eager loading)
    GOOD_PATTERNS = [
        r'->with\s*\(',
        r'->load\s*\(',
        r'::with\s*\(',
    ]

    def __init__(self, path: str):
        self.path = Path(path)
        self.issues = []
        self.warnings = []

    def scan(self) -> bool:
        if self.path.is_file():
            self._scan_file(self.path)
        else:
            for php_file in self.path.rglob('*.php'):
                if 'vendor' not in str(php_file):
                    self._scan_file(php_file)
        return len(self.issues) == 0

    def _scan_file(self, filepath: Path):
        try:
            content = filepath.read_text()
            lines = content.split('\n')

            for i, line in enumerate(lines, 1):
                for pattern, message in self.N1_PATTERNS:
                    if re.search(pattern, line, re.IGNORECASE):
                        # Check if eager loading is nearby
                        context = '\n'.join(lines[max(0, i-10):min(len(lines), i+5)])
                        has_eager = any(re.search(p, context) for p in self.GOOD_PATTERNS)

                        if not has_eager:
                            self.issues.append({
                                'file': str(filepath),
                                'line': i,
                                'message': message,
                                'code': line.strip()[:80]
                            })

        except Exception as e:
            self.warnings.append(f"Could not read {filepath}: {e}")

    def report(self):
        print(f"\n🔍 N+1 Query Analysis: {self.path}")
        print("=" * 50)

        if self.issues:
            print(f"\n⚠️  {len(self.issues)} Potential N+1 Issues Found:")
            for issue in self.issues[:15]:
                print(f"\n  {issue['file']}:{issue['line']}")
                print(f"  → {issue['message']}")
                print(f"  → {issue['code']}")

            if len(self.issues) > 15:
                print(f"\n  ... and {len(self.issues) - 15} more issues")

            print("\n💡 Tip: Add ->with('relationship') for eager loading")

        if not self.issues:
            print("\n✅ No obvious N+1 issues found!")

        print("")
        return len(self.issues) == 0


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else 'app/'

    if not Path(path).exists():
        print(f"Path not found: {path}")
        sys.exit(1)

    finder = N1QueryFinder(path)
    finder.scan()
    finder.report()


if __name__ == "__main__":
    main()
