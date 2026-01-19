# Auth & Security Decision Trees & Checklists

Quick reference for authentication, authorization, and security decisions.

---

## Authentication Flow Selection

```
What type of client?
├─ Web SPA (React)
│  └─ Use Sanctum stateful (cookies)
│     └─ See: sanctum-001, sanctum-002
├─ Mobile App
│  └─ Use Sanctum tokens (bearer)
│     └─ See: sanctum-003, sanctum-004
├─ Third-party API
│  └─ Use API keys + rate limiting
│     └─ See: creds-001, rate-001
└─ Webhook
   └─ Use signature validation
      └─ See: webhook-001, webhook-002
```

---

## Authorization Decision Tree

```
Is this a protected resource?
├─ Yes
│  ├─ Does user own resource?
│  │  └─ Use Policy (policy-001)
│  ├─ Role-based access?
│  │  └─ Use Gate or Policy with roles
│  └─ Feature-based access?
│     └─ Use token abilities (sanctum-005)
└─ No
   └─ No authorization needed (but validate input!)
```

---

## Credential Storage Decision

```
What type of credential?
├─ User password
│  └─ Hash with bcrypt (Laravel default)
├─ API key (bot's access token)
│  └─ Encrypt in database (creds-002)
├─ Third-party API key
│  └─ Store in environment variable (creds-001)
└─ Session/temporary token
   └─ Use Sanctum with expiration (sanctum-004)
```

---

## Security Quick Audit

### Pre-Deployment Checklist

```bash
# 1. Check for hardcoded secrets
grep -rn "password\|secret\|api_key" app/ config/ --include="*.php" | grep -v test

# 2. Check routes without auth
php artisan route:list --columns=uri,middleware | grep -v "auth"

# 3. Check for SQL injection patterns
grep -rn "DB::raw\|whereRaw\|selectRaw" app/ --include="*.php"

# 4. Check for mass assignment
grep -rn 'protected \$guarded = \[\]' app/Models/

# 5. Composer vulnerability scan
composer audit
```

### OWASP Top 10 Quick Check

| # | Vulnerability | Check |
|---|---------------|-------|
| 1 | Injection | No `DB::raw` with user input |
| 2 | Broken Auth | Token expiration, rate limiting |
| 3 | Sensitive Data | Encrypted credentials, HTTPS |
| 4 | XXE | XML parsing disabled |
| 5 | Broken Access | Policies on all models |
| 6 | Misconfig | Secure headers, debug off |
| 7 | XSS | Blade escaping, no `{!! !!}` |
| 8 | Insecure Deserialize | Validate serialized data |
| 9 | Vulnerable Components | `composer audit` |
| 10 | Insufficient Logging | Auth events logged |

---

## Rate Limiting Guide

| Endpoint Type | Recommended Limit |
|---------------|-------------------|
| Public API | 60/minute per IP |
| Authenticated API | 120/minute per user |
| Login attempts | 5/minute per IP |
| Password reset | 3/minute per email |
| Webhooks | 1000/minute per bot |
| AI/LLM endpoints | 10/minute per user |

---

## Token Abilities Reference

```php
// Standard abilities
'bot:read'        // View bots
'bot:create'      // Create new bots
'bot:update'      // Modify bot settings
'bot:delete'      // Delete bots
'conversation:read'  // View conversations
'conversation:manage' // Manage conversations
'admin:*'         // Full admin access
```

---

## Rule Index by Priority

### CRITICAL (Fix Immediately)
| Rule | Title |
|------|-------|
| owasp-001 | SQL Injection Prevention |
| owasp-002 | Broken Authentication |
| creds-001 | Environment Variable Secrets |
| webhook-001 | Signature Validation |

### HIGH (Fix Before Deploy)
| Rule | Title |
|------|-------|
| sanctum-001 | Proper Token Creation |
| sanctum-004 | Token Expiration |
| creds-002 | Encrypt Stored Credentials |
| rate-001 | API Rate Limiting |
| policy-001 | Resource Policies |

### MEDIUM (Best Practice)
| Rule | Title |
|------|-------|
| sanctum-002 | Token Revocation |
| sanctum-003 | SPA Authentication |
| sanctum-005 | Token Abilities |
| owasp-003 through 006 | Other OWASP items |
| rate-002, rate-003 | Additional rate limiting |
