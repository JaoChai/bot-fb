# E2E Testing Strategy

End-to-end testing using **Claude-in-Chrome** browser automation.

## Overview

E2E tests validate complete user flows by automating a real browser. We use Claude-in-Chrome (Chrome extension) instead of Playwright for browser testing.

## Critical User Flows

### 1. Login Flow

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to `/login` | Login form visible |
| 2 | Enter email + password | Fields populated |
| 3 | Click login button | Loading state shown |
| 4 | Wait for redirect | Redirected to `/dashboard` |
| 5 | Verify dashboard | User name visible, bots listed |

### 2. Create Bot Flow

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to `/bots` | Bot list page visible |
| 2 | Click "Create Bot" | Creation form/dialog opens |
| 3 | Enter bot name | Field populated |
| 4 | Select channel type (LINE/Telegram/Facebook) | Channel selected |
| 5 | Click create/submit | Bot created successfully |
| 6 | Verify bot appears | New bot visible in list |

### 3. Chat Flow

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to bot's conversations | Conversation list visible |
| 2 | Select a conversation | Chat window opens |
| 3 | Type a message | Input field populated |
| 4 | Send message | Message appears in chat |
| 5 | Wait for bot response | Bot reply visible |

### 4. Knowledge Base Flow

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to Knowledge Bases | KB list visible |
| 2 | Create new KB | KB created |
| 3 | Upload document | Upload starts, processing indicator shown |
| 4 | Wait for processing | Document status changes to "processed" |
| 5 | Search in KB | Search results returned |

## Running E2E Tests

### Prerequisites

1. Chrome browser with Claude-in-Chrome extension installed
2. Backend running at `https://api.botjao.com` (production) or `http://localhost:8000` (local)
3. Frontend running at `https://www.botjao.com` (production) or `http://localhost:5173` (local)
4. Test user account created

### Using Claude-in-Chrome

```
1. Open Chrome with the extension active
2. Ask Claude to run an E2E flow:
   "Run the login E2E test on https://www.botjao.com/login"
3. Claude will automate the browser using chrome tools
4. Results reported in conversation
```

### Test Data Requirements

| Data | Purpose |
|------|---------|
| Test user (email/password) | Login flow |
| Test bot (active, LINE channel) | Chat flow |
| Test conversation with messages | Chat display |
| Test KB with processed document | Search flow |

## Agent Team for E2E

For comprehensive E2E testing, use an Agent Team:

```
Lead (qa-tester) → orchestrates flows
├── laravel-api-dev → seeds test data (user, bot, KB)
├── qa-tester → runs Chrome flows one by one
└── deploy-ops → checks Sentry for errors after tests
```

### Team workflow:
1. **laravel-api-dev** creates test data via API/seeder
2. **qa-tester** runs each flow sequentially using Chrome
3. **deploy-ops** monitors Sentry for any new errors during testing
4. Results aggregated by lead

## Best Practices

- Run E2E tests against staging, not production (when available)
- Create isolated test data that doesn't affect real users
- Clean up test data after runs
- Capture GIF recordings for visual review using `gif_creator`
- Check Sentry after each flow for backend errors
- If a flow fails, capture screenshot for debugging
