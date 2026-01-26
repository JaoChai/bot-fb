#!/usr/bin/env python3
"""
Check test coverage thresholds.
Usage: python coverage_check.py [--min-coverage 80]
"""

import sys
import subprocess
import re
import argparse


def run_coverage():
    """Run PHPUnit with coverage and return output."""
    try:
        result = subprocess.run(
            ['php', 'artisan', 'test', '--coverage'],
            capture_output=True,
            text=True,
            timeout=300
        )
        return result.stdout + result.stderr
    except subprocess.TimeoutExpired:
        return "ERROR: Test timeout exceeded"
    except FileNotFoundError:
        return "ERROR: php or artisan not found"


def parse_coverage(output: str) -> dict:
    """Parse coverage percentage from output."""
    coverage = {
        'total': 0.0,
        'classes': {},
        'passed': True
    }

    # Look for total coverage line like "Total: 75.5%"
    total_match = re.search(r'Total:\s*([\d.]+)%', output)
    if total_match:
        coverage['total'] = float(total_match.group(1))

    # Look for class-level coverage
    class_pattern = r'(\S+)\s+\.+\s*([\d.]+)%'
    for match in re.finditer(class_pattern, output):
        coverage['classes'][match.group(1)] = float(match.group(2))

    return coverage


def check_thresholds(coverage: dict, min_coverage: float) -> list:
    """Check if coverage meets thresholds."""
    issues = []

    if coverage['total'] < min_coverage:
        issues.append(f"Total coverage {coverage['total']:.1f}% < {min_coverage}% threshold")

    # Check for very low coverage classes
    for class_name, pct in coverage['classes'].items():
        if pct < 50:
            issues.append(f"{class_name}: {pct:.1f}% (below 50%)")

    return issues


def main():
    parser = argparse.ArgumentParser(description='Check test coverage')
    parser.add_argument('--min-coverage', type=float, default=60.0,
                        help='Minimum required coverage percentage')
    parser.add_argument('--test', action='store_true',
                        help='Run in test mode (skip actual test execution)')
    args = parser.parse_args()

    print(f"\n📊 Coverage Check (minimum: {args.min_coverage}%)")
    print("=" * 50)

    if args.test:
        print("\n⚠️  Test mode - skipping actual test execution")
        print("✅ Coverage check script is working!")
        sys.exit(0)

    print("\nRunning tests with coverage...")
    output = run_coverage()

    if "ERROR:" in output:
        print(f"\n❌ {output}")
        sys.exit(1)

    coverage = parse_coverage(output)
    issues = check_thresholds(coverage, args.min_coverage)

    print(f"\n📈 Total Coverage: {coverage['total']:.1f}%")

    if coverage['classes']:
        print("\nClass Coverage:")
        for class_name, pct in sorted(coverage['classes'].items(), key=lambda x: x[1]):
            status = "✅" if pct >= args.min_coverage else "⚠️"
            print(f"  {status} {class_name}: {pct:.1f}%")

    if issues:
        print(f"\n❌ {len(issues)} Coverage Issues:")
        for issue in issues:
            print(f"  → {issue}")
        sys.exit(1)
    else:
        print(f"\n✅ Coverage meets {args.min_coverage}% threshold!")
        sys.exit(0)


if __name__ == "__main__":
    main()
