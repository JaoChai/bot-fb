---
id: railway-005-domain-setup
title: Domain and SSL Configuration
impact: MEDIUM
impactDescription: "Domain not working, SSL errors"
category: railway
tags: [railway, domain, ssl, dns]
relatedRules: [health-001-endpoint-config, env-003-config-sync]
---

## Symptom

- Custom domain not resolving
- SSL certificate errors
- Mixed content warnings
- Redirect loops

## Root Cause

1. DNS not configured correctly
2. SSL certificate not provisioned
3. Wrong CNAME/A record
4. APP_URL mismatch
5. Force HTTPS not enabled

## Diagnosis

### Quick Check

```bash
# Check domain in Railway
railway domains

# Test DNS resolution
dig api.botjao.com

# Check SSL
curl -vI https://api.botjao.com 2>&1 | grep -i ssl
```

### Detailed Analysis

```bash
# Verify DNS propagation
nslookup api.botjao.com

# Check certificate details
openssl s_client -connect api.botjao.com:443 -servername api.botjao.com | openssl x509 -noout -dates

# Verify Railway domain
railway domains --json | jq .
```

## Solution

### Fix Steps

1. **Add custom domain**
```bash
# Via MCP tool
Use generate-domain with:
- workspacePath: current directory
- service: "api" (optional)

# Or via CLI
railway domain add api.botjao.com
```

2. **Configure DNS**
```
# Add CNAME record at your DNS provider:
api.botjao.com -> your-service.up.railway.app

# Or for root domain, use A record:
botjao.com -> Railway IP (from dashboard)
```

3. **Wait for SSL**
```bash
# Railway auto-provisions SSL
# Wait 5-15 minutes after DNS propagation
# Check status:
railway domains
```

4. **Configure Laravel**
```php
// config/app.php
'url' => env('APP_URL', 'https://api.botjao.com'),

// .env / Railway vars
APP_URL=https://api.botjao.com
ASSET_URL=https://api.botjao.com
```

5. **Force HTTPS**
```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    if (config('app.env') === 'production') {
        URL::forceScheme('https');
    }
}
```

### DNS Configuration

```
# Required DNS records

# API (backend)
Type: CNAME
Name: api
Value: botfb-backend.up.railway.app

# Frontend
Type: CNAME
Name: www
Value: botfb-frontend.up.railway.app

# WebSocket
Type: CNAME
Name: reverb
Value: botfb-reverb.up.railway.app
```

## Verification

```bash
# Test domain resolution
curl -sI https://api.botjao.com | head -5

# Check SSL validity
curl -vI https://api.botjao.com 2>&1 | grep "SSL certificate verify ok"

# Verify no mixed content
curl -s https://www.botjao.com | grep -i "http://" | head -5
# Should be empty

# Check health through domain
curl -s https://api.botjao.com/health | jq .
```

## Prevention

- Document all domain configurations
- Monitor SSL expiration
- Test domains after DNS changes
- Use consistent URL format (with/without www)
- Set up domain monitoring

## Project-Specific Notes

**BotFacebook Context:**
- API domain: api.botjao.com
- Frontend domain: www.botjao.com
- WebSocket domain: reverb.botjao.com
- SSL: Auto-provisioned by Railway
- Force HTTPS: Enabled in Laravel
