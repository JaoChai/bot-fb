---
id: owasp-006-vulnerable-components
title: OWASP A06 - Vulnerable Components
impact: HIGH
impactDescription: "Outdated packages with known vulnerabilities"
category: owasp
tags: [owasp, dependencies, composer, npm]
relatedRules: [owasp-005-security-misconfig]
---

## Why This Matters

Applications rely on many third-party packages. Known vulnerabilities in these packages can be exploited even if your code is secure.

## Threat Model

**Attack Vector:** Exploiting known CVEs in dependencies
**Impact:** Varies from information disclosure to RCE
**Likelihood:** High - vulnerability databases are public

## Bad Example

```bash
# Never updating dependencies
composer install --no-update
npm ci --ignore-scripts

# Ignoring security warnings
npm audit  # Shows 47 vulnerabilities, ignored

# Using unmaintained packages
"abandoned/package": "^1.0"  # No updates in 3 years
```

**Why it's vulnerable:**
- Known exploits are public
- Automated tools scan for CVEs
- Old packages have more vulnerabilities
- No security patches applied

## Good Example

```bash
# Regular security audits
composer audit
npm audit

# Update regularly
composer update --with-dependencies
npm update

# Automated vulnerability scanning in CI
# .github/workflows/security.yml
name: Security Scan
on: [push, pull_request]
jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - run: composer audit
      - run: npm audit

# Use Dependabot
# .github/dependabot.yml
version: 2
updates:
  - package-ecosystem: "composer"
    directory: "/"
    schedule:
      interval: "weekly"
  - package-ecosystem: "npm"
    directory: "/frontend"
    schedule:
      interval: "weekly"
```

**Why it's secure:**
- Regular vulnerability checks
- Automated updates
- CI blocks vulnerable code
- Dependabot keeps deps fresh

## Audit Command

```bash
# PHP vulnerabilities
composer audit

# JavaScript vulnerabilities
npm audit

# Check for outdated packages
composer outdated --direct
npm outdated

# Security advisory check
composer audit --format=json | jq '.advisories | length'
```

## Project-Specific Notes

**BotFacebook Dependency Management:**

```yaml
# .github/workflows/security.yml
name: Security Audit
on:
  schedule:
    - cron: '0 0 * * 1'  # Weekly on Monday
  push:
    branches: [main]

jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: PHP Security Audit
        run: |
          composer install --no-dev
          composer audit --format=json > audit.json
          if [ $(jq '.advisories | length' audit.json) -gt 0 ]; then
            echo "::error::PHP vulnerabilities found"
            exit 1
          fi

      - name: JS Security Audit
        run: |
          cd frontend
          npm ci
          npm audit --audit-level=high
```

```json
// composer.json
{
    "scripts": {
        "security-check": "composer audit && cd frontend && npm audit"
    }
}
```
