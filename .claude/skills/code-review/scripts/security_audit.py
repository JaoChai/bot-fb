#!/usr/bin/env python3
"""
Security audit for PHP/Laravel codebase.
Usage: python security_audit.py <path>
"""

import sys
import re
from pathlib import Path


class SecurityAuditor:
    VULNERABILITIES = [
        # SQL Injection
        (r'DB::raw\s*\(', 'SQL Injection: Raw SQL query detected'),
        (r'whereRaw\s*\(', 'SQL Injection: Raw where clause detected'),
        (r'\$_GET\s*\[', 'Input Handling: Direct $_GET access'),
        (r'\$_POST\s*\[', 'Input Handling: Direct $_POST access'),
        (r'\$_REQUEST\s*\[', 'Input Handling: Direct $_REQUEST access'),

        # XSS
        (r'\{!!\s*\$', 'XSS: Unescaped Blade output'),
        (r'echo\s+\$', 'XSS: Direct echo of variable'),

        # Hardcoded Secrets
        (r'sk_live_[a-zA-Z0-9]+', 'Secret: Stripe live key detected'),
        (r'pk_live_[a-zA-Z0-9]+', 'Secret: Stripe public key detected'),
        (r'api_key\s*=\s*[\'"][^\'"]+[\'"]', 'Secret: Hardcoded API key'),
        (r'password\s*=\s*[\'"][^\'"]+[\'"]', 'Secret: Hardcoded password'),

        # Insecure Practices
        (r'eval\s*\(', 'Code Execution: eval() used'),
        (r'exec\s*\(', 'Code Execution: exec() used'),
        (r'shell_exec\s*\(', 'Code Execution: shell_exec() used'),
        (r'md5\s*\(', 'Crypto: Weak hash (MD5)'),
        (r'sha1\s*\(', 'Crypto: Weak hash (SHA1)'),

        # Mass Assignment
        (r'protected\s+\$guarded\s*=\s*\[\s*\]', 'Mass Assignment: Empty $guarded'),
    ]

    def __init__(self, path: str):
        self.path = Path(path)
        self.issues = []
        self.warnings = []

    def audit(self) -> bool:
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
            for pattern, message in self.VULNERABILITIES:
                matches = re.finditer(pattern, content, re.IGNORECASE)
                for match in matches:
                    line_num = content[:match.start()].count('\n') + 1
                    self.issues.append({
                        'file': str(filepath),
                        'line': line_num,
                        'message': message,
                        'match': match.group()[:50]
                    })
        except Exception as e:
            self.warnings.append(f"Could not read {filepath}: {e}")

    def report(self):
        print(f"\n🔒 Security Audit: {self.path}")
        print("=" * 50)

        if self.issues:
            print(f"\n❌ {len(self.issues)} Security Issues Found:")
            for issue in self.issues[:20]:  # Limit output
                print(f"\n  {issue['file']}:{issue['line']}")
                print(f"  → {issue['message']}")
                print(f"  → Match: {issue['match']}")

            if len(self.issues) > 20:
                print(f"\n  ... and {len(self.issues) - 20} more issues")

        if self.warnings:
            print("\n⚠️  Warnings:")
            for warning in self.warnings:
                print(f"  {warning}")

        if not self.issues and not self.warnings:
            print("\n✅ No security issues found!")

        print("")
        return len(self.issues) == 0


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else 'app/'

    if not Path(path).exists():
        print(f"Path not found: {path}")
        sys.exit(1)

    auditor = SecurityAuditor(path)
    auditor.audit()
    success = auditor.report()

    sys.exit(0 if success else 1)


if __name__ == "__main__":
    main()
