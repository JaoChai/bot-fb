# 🚀 Railway Environment Variables Setup - Quick Checklist

## Step 1: Configure Backend Service

Go to: **Railway Dashboard** → **BotFacebook** → **Backend** → **Variables**

### Add/Update These Variables:

```
APP_ENV                  = production
APP_DEBUG                = false
APP_URL                  = https://backend-production-b216.up.railway.app
CORS_ALLOWED_ORIGINS     = https://frontend-production-9fe8.up.railway.app
SANCTUM_STATEFUL_DOMAINS = frontend-production-9fe8.up.railway.app
SESSION_DRIVER           = cookie
```

**Critical**:
- ✅ `CORS_ALLOWED_ORIGINS` must be the **exact frontend URL**
- ✅ `SANCTUM_STATEFUL_DOMAINS` must be the domain **without** `https://`
- ✅ `APP_DEBUG` must be `false` for production

### After Updating:
1. Click **Save Variables**
2. Wait for automatic redeploy (or manually click **Deploy**)
3. Check **Logs** tab to verify successful deployment

---

## Step 2: Configure Frontend Service

Go to: **Railway Dashboard** → **BotFacebook** → **Frontend** → **Variables**

### Add/Update These Variables:

```
VITE_API_URL = https://backend-production-b216.up.railway.app/api
```

**Critical**:
- ✅ Must include `/api` at the end
- ✅ Must match your backend URL exactly

### After Updating:
1. Click **Save Variables**
2. Wait for automatic redeploy (or manually click **Deploy**)
3. Check **Logs** tab to verify successful deployment

---

## Step 3: Verify It Works

After both services have redeployed:

### Test 1: Load the Page
1. Visit: `https://frontend-production-9fe8.up.railway.app`
2. Login with your account
3. Navigate to "การเชื่อมต่อ" (Connections)
4. Check: **No "Network Error" message should appear**

### Test 2: Check API Calls
1. Open DevTools (F12) → **Network** tab
2. Refresh the Connections page
3. Look for requests to `/api/bots`
4. Check: **Status should be 200**, not 0 or blocked by CORS

### Test 3: Check for CORS Errors
1. Open DevTools (F12) → **Console** tab
2. Refresh the page
3. Check: **No red CORS error messages**

---

## Troubleshooting

### Still Getting "Network Error"?

**Check Backend Logs:**
1. Go to Backend service → **Logs** tab
2. Search for errors
3. Look for database connection errors

**Check Browser Console:**
1. F12 → Console tab
2. Look for error messages about failed API calls
3. Note the exact error URL

**Verify Variables Were Set:**
1. Go to Backend → Variables tab
2. Confirm `CORS_ALLOWED_ORIGINS` is visible and has correct value
3. Confirm `SANCTUM_STATEFUL_DOMAINS` is visible and has correct value

### CORS Error Still Appears?

**Most Common Issue**: Variable not exactly matching frontend URL

```
✅ CORRECT:   https://frontend-production-9fe8.up.railway.app
❌ WRONG:     frontend-production-9fe8.up.railway.app  (missing https://)
❌ WRONG:     https://frontend-production-9fe8.up.railway.app/  (trailing slash)
```

Fix and redeploy.

### Page Loads But No Data Shows?

**Check**:
1. Are you logged in? (should see user profile button)
2. Are there other errors in Console?
3. Check Network tab - are API calls 401 (auth) or 403 (permission)?

---

## 📋 Environment Variable Reference

### Backend Variables (Node)
| Variable | Example | Notes |
|----------|---------|-------|
| `APP_ENV` | `production` | Must be `production` for performance |
| `APP_DEBUG` | `false` | **Must be false** (security) |
| `APP_URL` | `https://backend-production-b216.up.railway.app` | Must match deployed backend URL |
| `CORS_ALLOWED_ORIGINS` | `https://frontend-production-9fe8.up.railway.app` | **Must be exact frontend URL** |
| `SANCTUM_STATEFUL_DOMAINS` | `frontend-production-9fe8.up.railway.app` | **Must be domain without protocol** |
| `SESSION_DRIVER` | `cookie` | Required for SPA authentication |

### Frontend Variables (Vite)
| Variable | Example | Notes |
|----------|---------|-------|
| `VITE_API_URL` | `https://backend-production-b216.up.railway.app/api` | **Must include `/api`** |

---

## ✅ Final Checklist

- [ ] Backend service redeploy **completed successfully**
- [ ] Frontend service redeploy **completed successfully**
- [ ] Can access login page without errors
- [ ] Can log in successfully
- [ ] Connections page loads without "Network Error"
- [ ] API calls show status 200 in Network tab
- [ ] No CORS errors in browser console
- [ ] Can create/edit/delete connections (if you have permissions)

---

**Deployment Status**: Ready for environment variable configuration

**Troubleshooting Reference**: See DEPLOYMENT_GUIDE.md for detailed explanations
