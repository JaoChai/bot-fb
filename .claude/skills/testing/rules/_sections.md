# Testing Decision Trees

## Test Type Selection

```
What needs testing?
├── Service/Model logic only
│   └── Unit Test (tests/Unit/)
│       ├── Isolated from DB? → Mock dependencies
│       └── Needs DB state? → RefreshDatabase trait
│
├── API endpoint behavior
│   └── Feature Test (tests/Feature/)
│       ├── Auth required? → actingAs($user)
│       ├── Validation? → assertJsonValidationErrors
│       └── Response structure? → assertJsonStructure
│
├── Full user flow
│   └── E2E Test (Playwright)
│       ├── Critical path? → Must have
│       ├── Multiple steps? → Page Object Model
│       └── Async operations? → waitFor strategies
│
├── Visual appearance
│   └── UI Test
│       ├── Layout changes? → Screenshot comparison
│       └── Responsive? → Test breakpoints
│
└── Accessibility
    └── A11y Test
        ├── WCAG level? → Configure axe rules
        └── Component or page? → Scope appropriately
```

## Coverage Priority Matrix

| Area | Coverage Target | Priority |
|------|-----------------|----------|
| RAG Services | 90%+ | CRITICAL |
| Auth flows | 85%+ | CRITICAL |
| API Controllers | 70%+ | HIGH |
| LLM Services | 60%+ | HIGH |
| Helper utilities | 50%+ | MEDIUM |
| UI components | Visual tests | MEDIUM |

## When to Use What

### Unit Tests (unit-)
- Service method logic
- Model accessors/mutators
- Utility functions
- Edge cases

### Feature Tests (feature-)
- API endpoints
- Auth flows
- Request validation
- Response format

### E2E Tests (e2e-)
- Login/logout flow
- Bot creation flow
- Conversation flow
- Critical business paths

### UI Tests (ui-)
- Responsive layouts
- Visual regressions
- Animation states
- Dark mode

### A11y Tests (a11y-)
- Keyboard navigation
- Screen reader support
- Color contrast
- Focus management

## Testing Checklist

### Before Writing Tests
- [ ] What behavior am I testing?
- [ ] What's the simplest test that covers it?
- [ ] Do I need database? (use RefreshDatabase only if needed)
- [ ] Do I need mocks? (avoid over-mocking)

### Test Structure
- [ ] Clear test name (test_verb_scenario_expectation)
- [ ] Arrange-Act-Assert pattern
- [ ] One assertion focus per test
- [ ] Independent tests (no shared state)

### After Writing Tests
- [ ] Tests pass in isolation?
- [ ] Tests pass in full suite?
- [ ] Tests are deterministic?
- [ ] Coverage improved?

## Rule Index by Impact

### CRITICAL
- unit-001-isolation
- unit-002-mock-external
- feature-001-auth-testing
- e2e-001-critical-paths

### HIGH
- unit-003-assertion-quality
- feature-002-validation-testing
- feature-003-response-format
- e2e-002-auth-flow
- e2e-003-waiting-strategies

### MEDIUM
- unit-004-factory-usage
- feature-004-database-testing
- ui-001-responsive
- a11y-001-basic-checks

### LOW
- ui-002-visual-regression
- a11y-002-wcag-levels
