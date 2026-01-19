# Auth Security Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 12:24

## Table of Contents

**Total Rules: 25**

- [Laravel Sanctum](#sanctum) - 6 rules (3 HIGH)
- [OWASP Top 10](#owasp) - 6 rules (4 CRITICAL)
- [Credential Management](#creds) - 5 rules (2 CRITICAL)
- [Rate Limiting](#rate) - 3 rules (2 HIGH)
- [Webhook Security](#webhook) - 3 rules (1 CRITICAL)
- [Authorization](#policy) - 2 rules (1 HIGH)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## Laravel Sanctum
<a name="sanctum"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [sanctum-001-token-creation](rules/sanctum-001-token-creation.md) | **HIGH** | Secure Token Creation |
| [sanctum-004-token-expiration](rules/sanctum-004-token-expiration.md) | **HIGH** | Token Expiration Configuration |
| [sanctum-006-login-throttling](rules/sanctum-006-login-throttling.md) | **HIGH** | Login Attempt Throttling |
| [sanctum-002-token-revocation](rules/sanctum-002-token-revocation.md) | MEDIUM | Token Revocation on Security Events |
| [sanctum-003-spa-authentication](rules/sanctum-003-spa-authentication.md) | MEDIUM | SPA Cookie Authentication |
| [sanctum-005-token-abilities](rules/sanctum-005-token-abilities.md) | MEDIUM | Token Abilities (Scopes) |

**sanctum-001-token-creation**: API tokens are the keys to user accounts.

**sanctum-004-token-expiration**: Tokens without expiration remain valid indefinitely.

**sanctum-006-login-throttling**: Without rate limiting, attackers can try millions of password combinations.

**sanctum-002-token-revocation**: When a user changes their password or reports suspicious activity, all existing tokens should be revoked.

**sanctum-003-spa-authentication**: Sanctum's stateful authentication for SPAs uses cookies.

**sanctum-005-token-abilities**: Token abilities limit what a stolen token can do.

## OWASP Top 10
<a name="owasp"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [owasp-001-injection](rules/owasp-001-injection.md) | **CRITICAL** | OWASP A03 - Injection Prevention |
| [owasp-002-broken-auth](rules/owasp-002-broken-auth.md) | **CRITICAL** | OWASP A07 - Broken Authentication |
| [owasp-003-sensitive-data](rules/owasp-003-sensitive-data.md) | **CRITICAL** | OWASP A02 - Sensitive Data Exposure |
| [owasp-004-broken-access](rules/owasp-004-broken-access.md) | **CRITICAL** | OWASP A01 - Broken Access Control |
| [owasp-005-security-misconfig](rules/owasp-005-security-misconfig.md) | **HIGH** | OWASP A05 - Security Misconfiguration |
| [owasp-006-vulnerable-components](rules/owasp-006-vulnerable-components.md) | **HIGH** | OWASP A06 - Vulnerable Components |

**owasp-001-injection**: Injection flaws occur when untrusted data is sent to an interpreter as part of a command or query.

**owasp-002-broken-auth**: Broken authentication allows attackers to compromise user accounts through weak passwords, session management flaws, or credential exposure.

**owasp-003-sensitive-data**: Sensitive data (passwords, tokens, PII) must be protected at rest and in transit.

**owasp-004-broken-access**: Broken access control allows users to access or modify resources they shouldn't.

**owasp-005-security-misconfig**: Security misconfigurations include debug mode in production, missing security headers, default credentials, and exposed error messages.

**owasp-006-vulnerable-components**: Applications rely on many third-party packages.

## Credential Management
<a name="creds"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [creds-001-env-secrets](rules/creds-001-env-secrets.md) | **CRITICAL** | Store Secrets in Environment Variables |
| [creds-002-encrypt-credentials](rules/creds-002-encrypt-credentials.md) | **CRITICAL** | Encrypt Credentials at Rest |
| [creds-003-rotation](rules/creds-003-rotation.md) | **HIGH** | Implement Credential Rotation |
| [creds-004-platform-tokens](rules/creds-004-platform-tokens.md) | **HIGH** | Secure Platform Token Handling |
| [creds-005-api-key-management](rules/creds-005-api-key-management.md) | **HIGH** | Secure API Key Management |

**creds-001-env-secrets**: Hardcoded secrets in code get committed to git, exposed in error messages, and shared when code is shared.

**creds-002-encrypt-credentials**: API keys and tokens stored in plain text are exposed if database is compromised.

**creds-003-rotation**: Credentials that never expire remain valid even after compromise.

**creds-004-platform-tokens**: Platform tokens (LINE, Telegram) provide full access to bot functionality.

**creds-005-api-key-management**: Third-party API keys (OpenRouter, Sentry, etc.

## Rate Limiting
<a name="rate"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [rate-001-api-rate-limiting](rules/rate-001-api-rate-limiting.md) | **HIGH** | Implement API Rate Limiting |
| [rate-003-abuse-prevention](rules/rate-003-abuse-prevention.md) | **HIGH** | Prevent API Abuse Patterns |
| [rate-002-endpoint-specific](rules/rate-002-endpoint-specific.md) | MEDIUM | Configure Endpoint-Specific Limits |

**rate-001-api-rate-limiting**: Without rate limiting, a single user or attacker can overwhelm your API, causing service degradation for all users and potentially massive costs.

**rate-003-abuse-prevention**: Simple rate limits can be bypassed by distributed attacks, credential stuffing, or enumeration attacks.

**rate-002-endpoint-specific**: Different endpoints have different costs and usage patterns.

## Webhook Security
<a name="webhook"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [webhook-001-signature-validation](rules/webhook-001-signature-validation.md) | **CRITICAL** | Validate Webhook Signatures |
| [webhook-002-ip-whitelist](rules/webhook-002-ip-whitelist.md) | MEDIUM | Consider IP Whitelisting for Webhooks |
| [webhook-003-replay-prevention](rules/webhook-003-replay-prevention.md) | MEDIUM | Prevent Webhook Replay Attacks |

**webhook-001-signature-validation**: Without signature validation, anyone can send fake webhook requests to your endpoint, impersonating LINE/Telegram and injecting malicious messages.

**webhook-002-ip-whitelist**: IP whitelisting provides defense-in-depth for webhooks.

**webhook-003-replay-prevention**: Valid webhook requests can be captured and replayed.

## Authorization
<a name="policy"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [policy-001-resource-policies](rules/policy-001-resource-policies.md) | **HIGH** | Use Laravel Policies for Authorization |
| [policy-002-gates](rules/policy-002-gates.md) | MEDIUM | Use Gates for Non-Model Authorization |

**policy-001-resource-policies**: Scattered authorization checks in controllers are easy to forget and inconsistent.

**policy-002-gates**: Not all authorization is model-based.

## Quick Reference by Tag

- **abilities**: sanctum-005-token-abilities
- **abuse**: rate-003-abuse-prevention
- **access-control**: owasp-004-broken-access
- **api**: rate-001-api-rate-limiting, rate-002-endpoint-specific
- **api-keys**: creds-005-api-key-management
- **auth**: sanctum-001-token-creation, sanctum-004-token-expiration, sanctum-006-login-throttling, sanctum-002-token-revocation, sanctum-003-spa-authentication, sanctum-005-token-abilities
- **authentication**: owasp-002-broken-auth
- **authorization**: owasp-004-broken-access, sanctum-005-token-abilities, policy-001-resource-policies, policy-002-gates
- **brute-force**: sanctum-006-login-throttling
- **composer**: owasp-006-vulnerable-components
- **configuration**: creds-001-env-secrets, rate-002-endpoint-specific, owasp-005-security-misconfig
- **cors**: sanctum-003-spa-authentication
- **credentials**: creds-001-env-secrets, creds-002-encrypt-credentials, creds-003-rotation, creds-004-platform-tokens, creds-005-api-key-management
- **csrf**: sanctum-003-spa-authentication
- **data-exposure**: owasp-003-sensitive-data
- **database**: creds-002-encrypt-credentials
- **ddos**: rate-003-abuse-prevention
- **defense-in-depth**: webhook-002-ip-whitelist
- **dependencies**: owasp-006-vulnerable-components
- **encryption**: creds-002-encrypt-credentials, owasp-003-sensitive-data
- **endpoints**: rate-002-endpoint-specific
- **env**: creds-001-env-secrets
- **expiration**: sanctum-004-token-expiration
- **features**: policy-002-gates
- **gates**: policy-002-gates
- **headers**: owasp-005-security-misconfig
- **idempotency**: webhook-003-replay-prevention
- **idor**: owasp-004-broken-access, policy-001-resource-policies
- **injection**: owasp-001-injection
- **ip-whitelist**: webhook-002-ip-whitelist
- **laravel**: policy-001-resource-policies, policy-002-gates
- **line**: creds-004-platform-tokens, webhook-001-signature-validation
- **npm**: owasp-006-vulnerable-components
- **openrouter**: creds-005-api-key-management
- **owasp**: owasp-001-injection, owasp-002-broken-auth, owasp-003-sensitive-data, owasp-004-broken-access, owasp-005-security-misconfig, owasp-006-vulnerable-components
- **platform**: creds-004-platform-tokens
- **policies**: policy-001-resource-policies
- **rate-limiting**: rate-001-api-rate-limiting, rate-003-abuse-prevention, rate-002-endpoint-specific
- **replay**: webhook-003-replay-prevention
- **revocation**: sanctum-002-token-revocation
- **rotation**: creds-003-rotation
- **sanctum**: sanctum-001-token-creation, sanctum-004-token-expiration, sanctum-006-login-throttling, sanctum-002-token-revocation, sanctum-003-spa-authentication, sanctum-005-token-abilities
- **scopes**: sanctum-005-token-abilities
- **secrets**: creds-001-env-secrets
- **security**: creds-002-encrypt-credentials, creds-003-rotation, webhook-001-signature-validation, webhook-002-ip-whitelist, webhook-003-replay-prevention, rate-001-api-rate-limiting, rate-003-abuse-prevention, owasp-001-injection, owasp-002-broken-auth, owasp-003-sensitive-data, owasp-005-security-misconfig, sanctum-001-token-creation
- **session**: owasp-002-broken-auth
- **signature**: webhook-001-signature-validation
- **spa**: sanctum-003-spa-authentication
- **sql**: owasp-001-injection
- **telegram**: creds-004-platform-tokens, webhook-001-signature-validation
- **third-party**: creds-005-api-key-management
- **throttle**: rate-001-api-rate-limiting, sanctum-006-login-throttling
- **token**: sanctum-001-token-creation, sanctum-004-token-expiration, sanctum-002-token-revocation
- **tokens**: creds-003-rotation, creds-004-platform-tokens
- **webhook**: webhook-001-signature-validation, webhook-002-ip-whitelist, webhook-003-replay-prevention
