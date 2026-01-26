# Security Checklist

## Authentication

- [ ] Passwords hashed with bcrypt (Laravel default)
- [ ] Token expiration configured
- [ ] Session timeout configured
- [ ] Failed login attempts limited
- [ ] Password reset tokens expire

## Authorization

- [ ] Policies defined for all resources
- [ ] Middleware applied to protected routes
- [ ] Token abilities/scopes used
- [ ] Admin routes separated

## API Security

- [ ] Rate limiting on all endpoints
- [ ] CORS properly configured
- [ ] Input validation on all endpoints
- [ ] No sensitive data in responses

## Credentials

- [ ] API keys encrypted in database
- [ ] Environment variables for secrets
- [ ] No credentials in git
- [ ] Credentials rotated periodically

## OWASP Top 10 Prevention

| Vulnerability | Prevention |
|--------------|------------|
| Injection | Use Eloquent, validate input |
| Broken Auth | Sanctum + rate limiting |
| Sensitive Data | Encrypt credentials, HTTPS |
| XXE | Disable XML parsing |
| Broken Access | Policies + middleware |
| Security Misconfig | Review config files |
| XSS | Blade escaping, CSP headers |
| Insecure Deserialization | Validate serialized data |
| Vulnerable Components | `composer audit` |
| Insufficient Logging | Log auth events |

## Common Vulnerabilities

| Issue | How to Find | Fix |
|-------|-------------|-----|
| Missing auth | Routes without middleware | Add `auth:sanctum` |
| Weak tokens | Short or predictable tokens | Use Sanctum defaults |
| Token leakage | Tokens in logs/responses | Filter sensitive data |
| IDOR | Direct object reference | Use policies |
| Mass assignment | Unguarded models | Use `$fillable` |

## Security Audit Commands

```bash
# Check for hardcoded secrets
grep -rn "sk_live\|pk_live\|api_key\|secret" app/ config/

# Check for unsafe queries
grep -rn "DB::raw\|whereRaw" app/

# Check middleware coverage
php artisan route:list --columns=uri,middleware

# Check for security issues
grep -r "env(" app/ --include="*.php" | grep -v ".env"
```
