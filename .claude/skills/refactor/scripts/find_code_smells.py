#!/usr/bin/env python3
"""
Find code smells and refactoring opportunities.
Usage: python find_code_smells.py <path>
"""

import sys
import re
from pathlib import Path


class CodeSmellFinder:
    SMELLS = {
        'long_method': {
            'threshold': 50,  # lines
            'message': 'Long method (>{} lines) - consider extracting'
        },
        'long_class': {
            'threshold': 300,  # lines
            'message': 'Long class (>{} lines) - consider splitting'
        },
        'deep_nesting': {
            'threshold': 4,  # levels
            'message': 'Deep nesting (>{} levels) - consider early return'
        },
        'too_many_params': {
            'threshold': 5,
            'message': 'Too many parameters (>{}) - use object/DTO'
        }
    }

    def __init__(self, path: str):
        self.path = Path(path)
        self.issues = []

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

            # Check class length
            if len(lines) > self.SMELLS['long_class']['threshold']:
                self.issues.append({
                    'file': str(filepath),
                    'type': 'long_class',
                    'message': f"Class has {len(lines)} lines",
                    'suggestion': 'Extract related methods to services'
                })

            # Check methods
            self._check_methods(filepath, content)

            # Check nesting depth
            self._check_nesting(filepath, lines)

        except Exception as e:
            pass

    def _check_methods(self, filepath: Path, content: str):
        # Find function definitions
        method_pattern = r'(public|private|protected)\s+function\s+(\w+)\s*\(([^)]*)\)'

        for match in re.finditer(method_pattern, content):
            method_name = match.group(2)
            params = match.group(3)

            # Check parameter count
            param_count = len([p for p in params.split(',') if p.strip()])
            if param_count > self.SMELLS['too_many_params']['threshold']:
                self.issues.append({
                    'file': str(filepath),
                    'type': 'too_many_params',
                    'message': f"Method {method_name} has {param_count} parameters",
                    'suggestion': 'Use a DTO or Request object'
                })

            # Check method length
            start_pos = match.end()
            method_lines = self._count_method_lines(content, start_pos)
            if method_lines > self.SMELLS['long_method']['threshold']:
                self.issues.append({
                    'file': str(filepath),
                    'type': 'long_method',
                    'message': f"Method {method_name} has {method_lines} lines",
                    'suggestion': 'Extract helper methods'
                })

    def _count_method_lines(self, content: str, start_pos: int) -> int:
        """Count lines in method body."""
        brace_count = 0
        started = False
        lines = 0

        for char in content[start_pos:]:
            if char == '{':
                brace_count += 1
                started = True
            elif char == '}':
                brace_count -= 1
                if started and brace_count == 0:
                    break
            elif char == '\n' and started:
                lines += 1

        return lines

    def _check_nesting(self, filepath: Path, lines: list):
        """Check for deeply nested code."""
        max_depth = 0
        current_depth = 0

        for i, line in enumerate(lines, 1):
            opens = line.count('{')
            closes = line.count('}')
            current_depth += opens - closes

            if current_depth > max_depth:
                max_depth = current_depth

            if current_depth > self.SMELLS['deep_nesting']['threshold']:
                self.issues.append({
                    'file': str(filepath),
                    'type': 'deep_nesting',
                    'message': f"Deep nesting ({current_depth} levels) at line {i}",
                    'suggestion': 'Use early returns or extract conditions'
                })
                break

    def report(self):
        print(f"\n🔍 Code Smell Analysis: {self.path}")
        print("=" * 50)

        if self.issues:
            # Group by type
            by_type = {}
            for issue in self.issues:
                t = issue['type']
                if t not in by_type:
                    by_type[t] = []
                by_type[t].append(issue)

            for smell_type, issues in by_type.items():
                print(f"\n⚠️  {smell_type.replace('_', ' ').title()} ({len(issues)} found):")
                for issue in issues[:5]:
                    print(f"  {issue['file']}")
                    print(f"    → {issue['message']}")
                    print(f"    💡 {issue['suggestion']}")

                if len(issues) > 5:
                    print(f"    ... and {len(issues) - 5} more")

        if not self.issues:
            print("\n✅ No major code smells found!")

        print("")
        return len(self.issues) == 0


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else 'app/'

    if not Path(path).exists():
        print(f"Path not found: {path}")
        sys.exit(1)

    finder = CodeSmellFinder(path)
    finder.scan()
    finder.report()


if __name__ == "__main__":
    main()
