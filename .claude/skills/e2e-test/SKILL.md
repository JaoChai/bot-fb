---
name: e2e-test
description: "Comprehensive E2E testing for Phase 1. Run automated tests for Backend APIs (health, auth, bots, flows, webhooks, security) and Frontend UI (login, register, dashboard). Use Playwright MCP for browser testing. Commands: test, e2e, e2e-test, testing, verify, validate, check features. Actions: run tests, test all, full test, comprehensive test."
---

# E2E Test - Comprehensive Testing Skill

Automated end-to-end testing for Backend APIs and Frontend UI using Playwright MCP.

## Prerequisites

Before running tests, ensure:

1. **Backend server running**: `php artisan serve` (port 8000)
2. **Frontend server running**: `npm run dev` in frontend/ (port 5173 or 5174)
3. **Database migrated**: `php artisan migrate`
4. **Playwright MCP available**: Check Claude Code has Playwright MCP configured

---

## How to Use This Skill

When user types `/e2e-test`, run the complete test suite in this order:

### Quick Check First

```bash
# Check backend is running
curl -s http://localhost:8000/api/health | head -20

# Check frontend is running
curl -s http://localhost:5173 || curl -s http://localhost:5174
```

If servers aren't running, provide instructions to start them.

---

## Test Suite 1: Backend API Testing

Use `WebFetch` or `curl` to test APIs. Track results in a table.

### 1.1 Health Check API

| Test | Method | Endpoint | Expected |
|------|--------|----------|----------|
| Health | GET | `/api/health` | 200 OK |

### 1.2 Authentication APIs

| Test | Method | Endpoint | Body | Expected |
|------|--------|----------|------|----------|
| Register | POST | `/api/auth/register` | `{name, email, password, password_confirmation}` | 201 + token |
| Login | POST | `/api/auth/login` | `{email, password}` | 200 + token |
| Get User | GET | `/api/auth/user` | (Bearer token) | 200 + user |
| Refresh | POST | `/api/auth/refresh` | (Bearer token) | 200 + new token |
| Logout | POST | `/api/auth/logout` | (Bearer token) | 200 |
| Unauthorized | GET | `/api/auth/user` | (no token) | 401 |

### 1.3 Bot Management APIs

| Test | Method | Endpoint | Body | Expected |
|------|--------|----------|------|----------|
| List Bots | GET | `/api/bots` | - | 200 |
| Create Bot | POST | `/api/bots` | `{name, channel_type, description}` | 201 |
| Get Bot | GET | `/api/bots/{id}` | - | 200 |
| Update Bot | PUT | `/api/bots/{id}` | `{name}` | 200 |
| Delete Bot | DELETE | `/api/bots/{id}` | - | 200 |

### 1.4 Flow Management APIs

| Test | Method | Endpoint | Expected |
|------|--------|----------|----------|
| List Templates | GET | `/api/bots/{id}/flows/templates` | 200 |
| List Flows | GET | `/api/bots/{id}/flows` | 200 |
| Create Flow | POST | `/api/bots/{id}/flows` | 201 |
| Get Flow | GET | `/api/bots/{id}/flows/{flow_id}` | 200 |
| Update Flow | PUT | `/api/bots/{id}/flows/{flow_id}` | 200 |
| Duplicate Flow | POST | `/api/bots/{id}/flows/{flow_id}/duplicate` | 201 |
| Set Default | PATCH | `/api/bots/{id}/flows/{flow_id}/set-default` | 200 |
| Delete Flow | DELETE | `/api/bots/{id}/flows/{flow_id}` | 200 |

### 1.5 LINE Webhook APIs

| Test | Method | Endpoint | Headers | Expected |
|------|--------|----------|---------|----------|
| Invalid Token | POST | `/webhook/invalid-token` | - | 404 |
| Missing Signature | POST | `/webhook/{valid-token}` | - | 401 |
| Invalid Signature | POST | `/webhook/{valid-token}` | X-Line-Signature: invalid | 401 or 500 |

### 1.6 Broadcasting Auth

| Test | Method | Endpoint | Expected |
|------|--------|----------|----------|
| No Auth | POST | `/broadcasting/auth` | 401 |
| With Token | POST | `/broadcasting/auth` | 200 or 403 |

### 1.7 Security Tests

| Test | What to Check | Expected |
|------|---------------|----------|
| Security Headers | X-Content-Type-Options, X-Frame-Options | Present |
| XSS Prevention | Script injection in inputs | Escaped/Rejected |
| SQL Injection | `' OR '1'='1` in email field | Rejected |
| JSON Depth | Deeply nested JSON (50 levels) | Rejected |

---

## Test Suite 2: Frontend UI Testing

Use **Playwright MCP** for browser automation testing.

### 2.1 Start Browser Session

```
Use mcp__plugin_playwright_playwright__browser_navigate to open the frontend URL
```

### 2.2 Login Page Tests

| Test | Action | Expected |
|------|--------|----------|
| Page Load | Navigate to `/login` | Login form visible |
| Form Elements | Check presence | Email, Password, Submit button |
| Styling | Visual inspection | Clean, professional design |
| Validation | Submit empty form | Error messages shown |
| Link | Click "Register" link | Navigate to `/register` |

### 2.3 Register Page Tests

| Test | Action | Expected |
|------|--------|----------|
| Page Load | Navigate to `/register` | Register form visible |
| Form Elements | Check presence | Name, Email, Password, Confirm, Submit |
| Validation | Submit mismatched passwords | Error message |
| Success | Submit valid data | Redirect to dashboard |

### 2.4 Dashboard Tests (After Login)

| Test | Action | Expected |
|------|--------|----------|
| Page Load | After successful login | Dashboard visible |
| Navigation | Check sidebar/navbar | All menu items present |
| User Info | Check header | User name/email displayed |
| Bots Page | Navigate to /bots | Bot list or empty state |

### 2.5 Responsive Tests

| Test | Viewport | Expected |
|------|----------|----------|
| Mobile | 375x667 | Mobile layout, hamburger menu |
| Tablet | 768x1024 | Tablet layout |
| Desktop | 1920x1080 | Full desktop layout |

---

## Test Suite 3: Integration Tests

These test the full flow across frontend and backend.

### 3.1 Registration Flow

1. Open frontend `/register`
2. Fill form with test data
3. Submit
4. Verify redirect to dashboard
5. Verify user data via API

### 3.2 Login Flow

1. Open frontend `/login`
2. Fill form with registered user
3. Submit
4. Verify redirect to dashboard
5. Verify token stored

### 3.3 Logout Flow

1. From dashboard, click logout
2. Verify redirect to login
3. Verify token cleared
4. Verify protected routes redirect to login

---

## Report Format

After testing, provide a summary:

```
## E2E Test Results

### Backend API Tests
| Category | Passed | Failed | Total |
|----------|--------|--------|-------|
| Health | 1 | 0 | 1 |
| Auth | 6 | 0 | 6 |
| Bots | 5 | 0 | 5 |
| Flows | 8 | 0 | 8 |
| Webhooks | 3 | 0 | 3 |
| Security | 4 | 0 | 4 |
| **Total** | **27** | **0** | **27** |

### Frontend UI Tests
| Category | Passed | Failed | Total |
|----------|--------|--------|-------|
| Login Page | 5 | 0 | 5 |
| Register Page | 4 | 0 | 4 |
| Dashboard | 4 | 0 | 4 |
| Responsive | 3 | 0 | 3 |
| **Total** | **16** | **0** | **16** |

### Integration Tests
| Flow | Status |
|------|--------|
| Registration | PASS |
| Login | PASS |
| Logout | PASS |

### Issues Found
- [List any bugs or issues discovered]

### Overall: 43/43 PASSED (100%)
```

---

## Test Data

Use these test data patterns:

### Registration Data
```json
{
  "name": "Test User",
  "email": "test-{timestamp}@example.com",
  "password": "TestPassword123!",
  "password_confirmation": "TestPassword123!"
}
```

### Bot Data
```json
{
  "name": "Test Bot",
  "channel_type": "line",
  "description": "Test bot for E2E testing"
}
```

### Flow Data
```json
{
  "name": "Test Flow",
  "description": "Test flow for E2E testing",
  "type": "customer_service"
}
```

---

## Error Handling

If a test fails:

1. **Log the error** with full details
2. **Continue testing** other items
3. **Mark as FAIL** in the report
4. **Suggest fixes** if obvious

Common issues:
- Server not running → Provide start commands
- CORS error → Check cors.php config
- 401 Unauthorized → Check token handling
- 500 Server Error → Check Laravel logs

---

## Cleanup

After testing:

1. **Delete test users** (optional, based on user preference)
2. **Delete test bots** created during testing
3. **Close browser session** via Playwright MCP
4. **Stop servers** (only if user requests)

---

## Quick Commands

For partial testing:

| Command | Scope |
|---------|-------|
| `/e2e-test` | Full test suite |
| `/e2e-test backend` | Backend APIs only |
| `/e2e-test frontend` | Frontend UI only |
| `/e2e-test auth` | Auth tests only |
| `/e2e-test security` | Security tests only |
