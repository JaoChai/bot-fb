#!/usr/bin/env python3
"""
Validate database migration for safety issues.
Usage: python validate_migration.py <migration_file>
"""

import sys
import re
from pathlib import Path


class MigrationValidator:
    DANGEROUS_PATTERNS = [
        # Data loss risks
        (r'dropColumn\s*\(\s*[\'"]', 'Dropping column - may cause data loss'),
        (r'dropTable\s*\(\s*[\'"]', 'Dropping table - may cause data loss'),
        (r'Schema::drop', 'Dropping schema - may cause data loss'),

        # Locking risks
        (r'->change\(\)', 'Column type change - may lock table'),
        (r'renameColumn\s*\(', 'Renaming column - may lock table'),
        (r'->nullable\(false\)', 'Adding NOT NULL - may fail on existing data'),

        # Foreign key risks
        (r'->constrained\(\)', 'Adding foreign key - ensure data integrity first'),
        (r'dropForeign\s*\(', 'Dropping foreign key - check dependencies'),

        # Index risks
        (r'->unique\(\)', 'Adding unique constraint - may fail on duplicates'),
        (r'CREATE\s+INDEX(?!\s+CONCURRENTLY)', 'Index creation may lock table'),
    ]

    REQUIRED_PATTERNS = [
        (r'public\s+function\s+down', 'Missing down() method for rollback'),
    ]

    def __init__(self, filepath: str):
        self.filepath = Path(filepath)
        self.content = self.filepath.read_text()
        self.issues = []
        self.warnings = []

    def validate(self) -> bool:
        self._check_dangerous_patterns()
        self._check_required_patterns()
        self._check_large_table_operations()
        return len(self.issues) == 0

    def _check_dangerous_patterns(self):
        for pattern, message in self.DANGEROUS_PATTERNS:
            if re.search(pattern, self.content, re.IGNORECASE):
                self.warnings.append(f"⚠️  {message}")

    def _check_required_patterns(self):
        for pattern, message in self.REQUIRED_PATTERNS:
            if not re.search(pattern, self.content):
                self.issues.append(f"❌ {message}")

    def _check_large_table_operations(self):
        # Check for operations on known large tables
        large_tables = ['messages', 'conversations', 'logs']
        for table in large_tables:
            if re.search(rf"['\"]?{table}['\"]?", self.content):
                self.warnings.append(
                    f"⚠️  Operating on '{table}' table - consider impact on large dataset"
                )

    def report(self):
        print(f"\n📋 Migration Validation: {self.filepath.name}")
        print("=" * 50)

        if self.issues:
            print("\n❌ Issues (must fix):")
            for issue in self.issues:
                print(f"  {issue}")

        if self.warnings:
            print("\n⚠️  Warnings (review):")
            for warning in self.warnings:
                print(f"  {warning}")

        if not self.issues and not self.warnings:
            print("\n✅ No issues found!")

        print("")
        return len(self.issues) == 0


def main():
    if len(sys.argv) < 2:
        print("Usage: python validate_migration.py <migration_file>")
        sys.exit(1)

    filepath = sys.argv[1]

    if not Path(filepath).exists():
        print(f"File not found: {filepath}")
        sys.exit(1)

    validator = MigrationValidator(filepath)
    validator.validate()
    success = validator.report()

    sys.exit(0 if success else 1)


if __name__ == "__main__":
    main()
