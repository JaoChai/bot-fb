---
id: {category}-{number}-{slug}
title: {Descriptive Title}
impact: {CRITICAL|HIGH|MEDIUM|LOW}
impactDescription: "{Security impact}"
category: {category}
tags: [{tag1}, {tag2}, {tag3}]
relatedRules: [{other-rule-id}]
---

## Why This Matters

{1-2 sentences explaining the security impact and real-world consequences}

## Threat Model

**Attack Vector:** {How an attacker would exploit this}
**Impact:** {What damage could be done}
**Likelihood:** {How likely this is to be exploited}

## Bad Example

```{language}
// Vulnerable code
```

**Why it's vulnerable:**
- {Specific vulnerability 1}
- {Specific vulnerability 2}

## Good Example

```{language}
// Secure pattern
```

**Why it's secure:**
- {Security benefit 1}
- {Security benefit 2}

## Audit Command

```bash
# Command to detect this issue
grep -rn "pattern" --include="*.php" app/
```

## Project-Specific Notes

**BotFacebook Implementation:**

```{language}
// How this is implemented in our codebase
```

---

## Template Usage Notes

### Impact Levels for Security

| Level | Use When |
|-------|----------|
| CRITICAL | Data breach, credential theft, authentication bypass |
| HIGH | Authorization bypass, information disclosure |
| MEDIUM | Missing security headers, configuration issues |
| LOW | Security enhancements, defense in depth |

### Category Prefixes

| Prefix | Category | Examples |
|--------|----------|----------|
| sanctum- | Laravel Sanctum | token-creation, token-expiration, abilities |
| owasp- | OWASP Top 10 | injection, broken-auth, xss |
| creds- | Credentials | encryption, rotation, storage |
| rate- | Rate Limiting | api-limits, login-throttle |
| webhook- | Webhooks | signature-validation, ip-whitelist |
| policy- | Authorization | policies, gates |

### Audit Commands

Include commands to detect the vulnerability:
```bash
# Find potential issues
grep -rn "pattern" --include="*.php" app/

# List routes without auth
php artisan route:list | grep -v "sanctum"
```
