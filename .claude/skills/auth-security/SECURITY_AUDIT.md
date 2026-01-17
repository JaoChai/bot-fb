# Security Audit Guide

## Quick Audit Commands

```bash
# 1. Check for hardcoded secrets
grep -rn "password\s*=\s*['\"]" app/ config/ --include="*.php"
grep -rn "api_key\|secret_key\|access_token" app/ --include="*.php"

# 2. Check for SQL injection vulnerabilities
grep -rn "DB::raw\|whereRaw\|selectRaw" app/ --include="*.php"
grep -rn "\$request->.*->first()" app/ --include="*.php"

# 3. Check middleware coverage
php artisan route:list --columns=uri,middleware | grep -v "auth"

# 4. Check for mass assignment
grep -rn "protected \$guarded = \[\]" app/Models/

# 5. Check composer vulnerabilities
composer audit
```

## Full Audit Checklist

### 1. Authentication

| Check | Command/Location | Expected |
|-------|-----------------|----------|
| Password hashing | `app/Models/User.php` | Uses `Hash::make()` |
| Token expiration | `config/sanctum.php` | Has expiration set |
| Login throttling | `app/Http/Kernel.php` | `throttle:login` applied |
| Session config | `config/session.php` | `secure` = true in prod |

### 2. Authorization

| Check | Location | Expected |
|-------|----------|----------|
| Policies exist | `app/Policies/` | Policy for each model |
| Policy registered | `AuthServiceProvider` | All policies mapped |
| Middleware applied | `routes/api.php` | Protected routes |
| Admin separation | Routes | Admin routes isolated |

### 3. Input Validation

| Check | Location | Expected |
|-------|----------|----------|
| FormRequests used | Controllers | All store/update use FormRequest |
| Validation rules | `app/Http/Requests/` | Proper rules defined |
| File upload | Upload handlers | Validates mime type, size |

### 4. Output Encoding

| Check | Location | Expected |
|-------|----------|----------|
| Blade escaping | `.blade.php` files | Uses `{{ }}` not `{!! !!}` |
| API responses | Resources | No raw user input |
| Error messages | Exception handler | No stack traces in prod |

### 5. Credentials

| Check | Command | Expected |
|-------|---------|----------|
| Env file | `ls -la .env*` | Only `.env.example` in git |
| Encrypted fields | Models with tokens | Uses `encrypted` cast |
| Config caching | `config/` | No `env()` in config |

## Vulnerability Patterns

### SQL Injection

```php
// BAD
User::whereRaw("email = '$email'")->first();

// GOOD
User::where('email', $email)->first();
```

### XSS

```php
// BAD (in Blade)
{!! $userInput !!}

// GOOD
{{ $userInput }}
```

### Mass Assignment

```php
// BAD
protected $guarded = [];

// GOOD
protected $fillable = ['name', 'email'];
```

### IDOR

```php
// BAD
$bot = Bot::find($request->bot_id);

// GOOD
$bot = $request->user()->bots()->findOrFail($request->bot_id);
```

## Automated Security Scan

```bash
# Install security checker
composer require --dev enlightn/security-checker

# Run security check
php artisan security:check
```

## Reporting Format

```markdown
# Security Audit Report

Date: YYYY-MM-DD
Auditor: [name]

## Summary
- Critical: X
- High: X
- Medium: X
- Low: X

## Findings

### [CRITICAL] Finding Title
- Location: file:line
- Description: ...
- Impact: ...
- Recommendation: ...

### [HIGH] Finding Title
...
```

## Post-Audit Actions

1. **Critical/High**: Fix immediately, deploy hotfix
2. **Medium**: Fix within 1 week
3. **Low**: Add to backlog
4. **Document**: Update security documentation
5. **Retest**: Verify fixes work
