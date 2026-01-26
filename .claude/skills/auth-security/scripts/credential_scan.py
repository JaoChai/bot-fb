#!/usr/bin/env python3
"""
Scan for exposed credentials and secrets.
Usage: python credential_scan.py <path>
"""

import sys
import re
from pathlib import Path


class CredentialScanner:
    SECRET_PATTERNS = [
        # API Keys
        (r'api[_-]?key\s*[=:]\s*[\'"][^\'"]{20,}[\'"]', 'API Key'),
        (r'apikey\s*[=:]\s*[\'"][^\'"]{20,}[\'"]', 'API Key'),

        # Access Tokens
        (r'access[_-]?token\s*[=:]\s*[\'"][^\'"]{20,}[\'"]', 'Access Token'),
        (r'auth[_-]?token\s*[=:]\s*[\'"][^\'"]{20,}[\'"]', 'Auth Token'),
        (r'bearer\s+[a-zA-Z0-9\-_.]+', 'Bearer Token'),

        # Private Keys
        (r'-----BEGIN (?:RSA |DSA |EC )?PRIVATE KEY-----', 'Private Key'),
        (r'-----BEGIN OPENSSH PRIVATE KEY-----', 'SSH Private Key'),

        # Common Services
        (r'sk_live_[a-zA-Z0-9]{24,}', 'Stripe Secret Key'),
        (r'pk_live_[a-zA-Z0-9]{24,}', 'Stripe Publishable Key'),
        (r'AKIA[0-9A-Z]{16}', 'AWS Access Key'),
        (r'ghp_[a-zA-Z0-9]{36}', 'GitHub Personal Token'),
        (r'xox[baprs]-[a-zA-Z0-9-]+', 'Slack Token'),

        # Database
        (r'postgres://[^:]+:[^@]+@', 'PostgreSQL Connection String'),
        (r'mysql://[^:]+:[^@]+@', 'MySQL Connection String'),

        # Password patterns
        (r'password\s*[=:]\s*[\'"][^\'"]{8,}[\'"]', 'Hardcoded Password'),
        (r'secret\s*[=:]\s*[\'"][^\'"]{8,}[\'"]', 'Hardcoded Secret'),
    ]

    SAFE_FILES = ['.env.example', '.env.sample', 'example.env']
    IGNORE_DIRS = ['vendor', 'node_modules', '.git', 'storage', 'tests']

    def __init__(self, path: str):
        self.path = Path(path)
        self.findings = []

    def scan(self) -> bool:
        if self.path.is_file():
            self._scan_file(self.path)
        else:
            for file_path in self._get_files():
                self._scan_file(file_path)
        return len(self.findings) == 0

    def _get_files(self):
        """Get all files to scan, excluding ignored directories."""
        for file_path in self.path.rglob('*'):
            if file_path.is_file():
                # Skip ignored directories
                if any(ignored in str(file_path) for ignored in self.IGNORE_DIRS):
                    continue
                # Skip safe example files
                if file_path.name in self.SAFE_FILES:
                    continue
                # Only scan text files
                if file_path.suffix in ['.php', '.js', '.ts', '.json', '.yml', '.yaml', '.env', '.md', '.txt']:
                    yield file_path

    def _scan_file(self, filepath: Path):
        try:
            content = filepath.read_text()
            lines = content.split('\n')

            for i, line in enumerate(lines, 1):
                # Skip comments
                if line.strip().startswith('#') or line.strip().startswith('//'):
                    continue

                for pattern, secret_type in self.SECRET_PATTERNS:
                    match = re.search(pattern, line, re.IGNORECASE)
                    if match:
                        self.findings.append({
                            'file': str(filepath),
                            'line': i,
                            'type': secret_type,
                            'match': self._mask_secret(match.group()),
                        })

        except Exception:
            pass

    def _mask_secret(self, secret: str) -> str:
        """Mask the secret value for safe display."""
        if len(secret) > 10:
            return secret[:5] + '*' * (len(secret) - 10) + secret[-5:]
        return '*' * len(secret)

    def report(self):
        print(f"\n🔑 Credential Scan: {self.path}")
        print("=" * 50)

        if self.findings:
            print(f"\n❌ {len(self.findings)} Potential Secrets Found:")

            # Group by type
            by_type = {}
            for finding in self.findings:
                t = finding['type']
                if t not in by_type:
                    by_type[t] = []
                by_type[t].append(finding)

            for secret_type, items in by_type.items():
                print(f"\n  🔴 {secret_type} ({len(items)} found):")
                for item in items[:3]:
                    print(f"    {item['file']}:{item['line']}")
                    print(f"    → {item['match']}")

                if len(items) > 3:
                    print(f"    ... and {len(items) - 3} more")

            print("\n💡 Recommendations:")
            print("  1. Move secrets to .env file")
            print("  2. Add .env to .gitignore")
            print("  3. Rotate any exposed credentials")
            print("  4. Use environment variables")

        if not self.findings:
            print("\n✅ No exposed credentials found!")

        print("")
        return len(self.findings) == 0


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else '.'

    if not Path(path).exists():
        print(f"Path not found: {path}")
        sys.exit(1)

    scanner = CredentialScanner(path)
    scanner.scan()
    success = scanner.report()

    sys.exit(0 if success else 1)


if __name__ == "__main__":
    main()
