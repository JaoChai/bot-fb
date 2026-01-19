---
id: sanctum-003-spa-authentication
title: SPA Cookie Authentication
impact: MEDIUM
impactDescription: "Misconfigured SPA auth causes CORS and CSRF issues"
category: sanctum
tags: [auth, sanctum, spa, cors, csrf]
relatedRules: [sanctum-001-token-creation]
---

## Why This Matters

Sanctum's stateful authentication for SPAs uses cookies. Misconfiguration causes confusing CORS errors, CSRF failures, or security vulnerabilities.

## Threat Model

**Attack Vector:** CSRF attacks if cookies not properly configured
**Impact:** Unauthorized actions on behalf of user
**Likelihood:** Medium - requires misconfiguration

## Bad Example

```php
// config/sanctum.php - Missing domains
'stateful' => [
    // Missing frontend domains
],

// Frontend - Not getting CSRF cookie first
const login = async (email, password) => {
    // Directly calling login without CSRF cookie
    return axios.post('/api/v1/auth/login', { email, password });
};

// config/cors.php - Too permissive
'allowed_origins' => ['*'], // Allows any origin!
'supports_credentials' => true, // With credentials = dangerous
```

**Why it's vulnerable:**
- CSRF cookie not set
- Any origin can make requests
- Credentials sent to unknown origins

## Good Example

```php
// config/sanctum.php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', implode(',', [
    'localhost',
    'localhost:3000',
    'localhost:5173',
    '127.0.0.1',
    '127.0.0.1:8000',
    'botjao.com',
    'www.botjao.com',
]))),

// config/cors.php
'allowed_origins' => [
    env('FRONTEND_URL', 'https://www.botjao.com'),
],
'supports_credentials' => true,

// Frontend - Proper CSRF flow
const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    withCredentials: true, // Send cookies
});

const login = async (email: string, password: string) => {
    // Get CSRF cookie first
    await api.get('/sanctum/csrf-cookie');

    // Then login
    return api.post('/api/v1/auth/login', { email, password });
};
```

**Why it's secure:**
- Only known domains are stateful
- CORS restricted to frontend
- CSRF token required

## Audit Command

```bash
# Check CORS config
grep -rn "allowed_origins" config/cors.php

# Check stateful domains
grep -rn "stateful" config/sanctum.php
```

## Project-Specific Notes

**BotFacebook SPA Auth Setup:**

```typescript
// frontend/src/lib/api.ts
import axios from 'axios';

const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    withCredentials: true,
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
    },
});

// Add token for API requests (mobile flow)
api.interceptors.request.use((config) => {
    const token = localStorage.getItem('token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

export { api };
```
