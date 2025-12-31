# 🚀 Deployment Configuration Guide

## Overview

This guide explains how to properly configure the BotFacebook application for production deployment on Railway. The application consists of two services that must be properly configured to communicate with each other.

## Production Deployment URLs (Current)

```
Frontend:  https://www.botjao.com
Backend:   https://api.botjao.com
```

---

## 🔴 Critical: CORS Configuration

### Problem
When the frontend and backend are deployed to different domains, API requests fail with:
```
Access to XMLHttpRequest at 'https://backend-url.com/api/bots'
from origin 'https://frontend-url.com' has been blocked by CORS policy
```

### Solution
Configure these environment variables on the **Backend Service**:

#### 1. `CORS_ALLOWED_ORIGINS` (REQUIRED)
**Purpose**: Specifies which frontend domains can make API requests to the backend

```bash
# Set the frontend URL as the allowed origin:
CORS_ALLOWED_ORIGINS=https://www.botjao.com
```

**For multiple environments** (local, staging, production):
```bash
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:3000,https://www.botjao.com,https://staging-frontend.up.railway.app
```

#### 2. `SANCTUM_STATEFUL_DOMAINS` (REQUIRED for authentication)
**Purpose**: Enables stateful (cookie-based) authentication for Single Page Applications (SPAs)

```bash
# Set the frontend domain (without protocol):
SANCTUM_STATEFUL_DOMAINS=www.botjao.com
```

**For multiple environments**:
```bash
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,localhost:3000,www.botjao.com,staging-frontend.up.railway.app
```

---

## 📋 Complete Backend Environment Variables

### Required for Production

```ini
# Application Identity
APP_NAME=BotFacebook
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:your-generated-key-here

# Server URLs (MUST match deployed URLs)
APP_URL=https://api.botjao.com

# Database (Neon PostgreSQL)
DB_CONNECTION=pgsql
DB_HOST=your-neon-host.neon.tech
DB_PORT=5432
DB_DATABASE=neondb
DB_USERNAME=neon_user
DB_PASSWORD=your-neon-password

# Cache & Queue
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=your-redis-host
REDIS_PASSWORD=your-redis-password
REDIS_PORT=6379

# Session
SESSION_DRIVER=cookie
SESSION_LIFETIME=129600

# Broadcasting (for WebSocket/Reverb)
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=your-app-id
PUSHER_APP_KEY=your-app-key
PUSHER_APP_SECRET=your-app-secret
PUSHER_APP_CLUSTER=us2

# ⭐ CRITICAL: CORS & Authentication
CORS_ALLOWED_ORIGINS=https://www.botjao.com
SANCTUM_STATEFUL_DOMAINS=www.botjao.com

# AI/LLM Configuration
OPENROUTER_API_KEY=your-openrouter-key
EMBEDDING_MODEL=openai/text-embedding-3-small

# Integrations (if using)
LINE_CHANNEL_ACCESS_TOKEN=your-line-token
LINE_CHANNEL_SECRET=your-line-secret
```

---

## 📋 Complete Frontend Environment Variables

### Required for Production

```ini
# ⭐ CRITICAL: API Endpoint (must match backend URL)
VITE_API_URL=https://api.botjao.com/api

# WebSocket Configuration (for real-time features)
VITE_REVERB_APP_KEY=your-reverb-app-key
VITE_REVERB_HOST=your-reverb-host
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

---

## 🔧 Step-by-Step: Configure on Railway Dashboard

### Backend Service Configuration

1. Go to **Railway Dashboard** → **BotFacebook Project** → **Backend Service**

2. Click **Variables** tab

3. Add/Update these variables:

| Variable | Value | Notes |
|----------|-------|-------|
| `APP_ENV` | `production` | Critical for performance & security |
| `APP_DEBUG` | `false` | MUST be false (prevents info leaks) |
| `APP_URL` | `https://api.botjao.com` | Must match deployed URL |
| `CORS_ALLOWED_ORIGINS` | `https://www.botjao.com` | Frontend URL exactly |
| `SANCTUM_STATEFUL_DOMAINS` | `www.botjao.com` | Frontend domain without protocol |
| `DB_CONNECTION` | `pgsql` | PostgreSQL for Neon |
| `SESSION_DRIVER` | `cookie` | Required for SPA auth |

4. **Click "Deploy"** to apply changes (triggers automatic redeploy)

5. **Verify**: Check deployment logs in the Logs tab

### Frontend Service Configuration

1. Go to **Railway Dashboard** → **BotFacebook Project** → **Frontend Service**

2. Click **Variables** tab

3. Add/Update these variables:

| Variable | Value | Notes |
|----------|-------|-------|
| `VITE_API_URL` | `https://api.botjao.com/api` | Must point to backend |
| `VITE_REVERB_HOST` | `your-reverb-host` | Configure for WebSocket |
| `VITE_REVERB_SCHEME` | `https` | Use HTTPS for production |

4. **Click "Deploy"** to apply changes (triggers automatic redeploy)

5. **Verify**: Check deployment logs in the Logs tab

---

## ✅ Verification Checklist

After configuring environment variables:

- [ ] Backend service redeployed successfully
- [ ] Frontend service redeployed successfully
- [ ] Can access login page: `https://www.botjao.com`
- [ ] Can log in successfully
- [ ] Connections page (`/connections`) loads without "Network Error"
- [ ] Can create/edit/delete connections
- [ ] No browser console CORS errors
- [ ] API responses show correct data

---

## 🐛 Troubleshooting

### "Network Error" on Connections Page

**Symptom**: Connections page shows "เกิดข้อผิดพลาดในการโหลดข้อมูล: Network Error"

**Diagnosis**:
1. Open browser DevTools (F12) → **Network** tab
2. Try to load connections
3. Look for failed requests to `/api/bots` with status 0 or network error
4. Check browser console for CORS error message

**Common Causes & Fixes**:

| Cause | Fix |
|-------|-----|
| `CORS_ALLOWED_ORIGINS` not set or wrong | Verify it exactly matches frontend URL in Railway vars |
| `SANCTUM_STATEFUL_DOMAINS` not set | Add it with the frontend domain (no protocol) |
| Backend not redeployed after env vars change | Manually trigger deploy in Railway dashboard |
| Frontend using old cached environment | Hard refresh browser (Ctrl+Shift+R or Cmd+Shift+R) |
| API_URL in frontend env vars is wrong | Verify `VITE_API_URL` is correct backend URL + `/api` |

### API Returns 401 Unauthorized

**Cause**: Authentication token not being sent with requests

**Fix**: Ensure `SESSION_DRIVER=cookie` is set on backend and frontend can access cookies

### WebSocket Connection Fails

**Cause**: Reverb/WebSocket configuration incorrect

**Fix**:
1. Verify `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` are correct
2. Check backend Reverb configuration matches
3. Check browser console for WebSocket connection errors

---

## 🔐 Security Checklist

**Before going to production:**

- [ ] `APP_DEBUG=false` on backend (never true in production!)
- [ ] `APP_ENV=production` set on backend
- [ ] All sensitive keys/passwords stored in Railway Variables (never in code)
- [ ] `CORS_ALLOWED_ORIGINS` is specific (never use `*` with credentials)
- [ ] Database credentials use strong passwords
- [ ] API keys (OpenRouter, LINE, etc.) are secure and not exposed
- [ ] HTTPS is enabled for all URLs
- [ ] Backup database before any migrations in production

---

## 📚 Reference: Environment Variables by Layer

### Frontend Layer (`src/lib/echo.ts`, `src/lib/api.ts`)
- `VITE_API_URL` → Used to construct all API endpoints
- `VITE_REVERB_*` → Used for WebSocket connections

### Backend Layer (Laravel)
- `APP_URL` → Used in email links, redirects
- `APP_ENV` → Determines debug mode, caching, etc.
- `CORS_ALLOWED_ORIGINS` → Controls which domains can make requests
- `SANCTUM_STATEFUL_DOMAINS` → Controls cookie-based auth
- Database variables → Connection credentials

### Deployment Layer (Railway)
- All variables must be set in Railway dashboard **Variables** tab
- Changes to variables require manual deploy trigger or auto-deploy on push

---

## 🔄 Deployment Workflow

1. **Make code changes** → Commit to `main` branch
2. **Push to GitHub** → `git push origin main`
3. **Railway auto-deploys** → Checks out code, builds, deploys
4. **Verify deployment** → Check Railway Logs tab for success
5. **Test features** → Visit deployed URL and test functionality
6. **Fix CORS if needed** → Update `CORS_ALLOWED_ORIGINS` in Railway Variables
7. **Trigger redeploy** → Click "Deploy" button on service

---

## 📞 Support

If deployment fails:

1. Check **Railway Logs** tab for error messages
2. Verify all required environment variables are set
3. Ensure `CORS_ALLOWED_ORIGINS` and `SANCTUM_STATEFUL_DOMAINS` are exact matches
4. Check git repository for recent commits
5. Review this guide's troubleshooting section

---

**Last Updated**: December 27, 2025 | Production Deployment: v1
