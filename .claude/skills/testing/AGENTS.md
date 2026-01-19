# Testing Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 12:35

## Table of Contents

**Total Rules: 24**

- [Unit Testing](#unit) - 6 rules (2 CRITICAL)
- [Feature Testing](#feature) - 6 rules (1 CRITICAL)
- [E2E Testing](#e2e) - 5 rules (1 CRITICAL)
- [UI Testing](#ui) - 4 rules
- [Accessibility Testing](#a11y) - 3 rules

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## Unit Testing
<a name="unit"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [unit-001-isolation](rules/unit-001-isolation.md) | **CRITICAL** | Isolate Unit Tests from External Dependencies |
| [unit-002-mock-external](rules/unit-002-mock-external.md) | **CRITICAL** | Mock External Services Properly |
| [unit-003-assertion-quality](rules/unit-003-assertion-quality.md) | **HIGH** | Write Meaningful Assertions |
| [unit-004-factory-usage](rules/unit-004-factory-usage.md) | MEDIUM | Use Factories Effectively |
| [unit-005-test-naming](rules/unit-005-test-naming.md) | MEDIUM | Use Descriptive Test Names |
| [unit-006-arrange-act-assert](rules/unit-006-arrange-act-assert.md) | MEDIUM | Follow Arrange-Act-Assert Pattern |

**unit-001-isolation**: Unit tests that depend on external services (APIs, databases, file systems) are slow, flaky, and can fail due to network issues rather than code bugs.

**unit-002-mock-external**: Proper mocking tests behavior while isolating from external systems.

**unit-003-assertion-quality**: Weak assertions like `assertTrue($response !== null)` pass even when the code is broken.

**unit-004-factory-usage**: Factories create consistent test data with minimal code.

**unit-005-test-naming**: When tests fail, the name is the first clue to what's wrong.

**unit-006-arrange-act-assert**: The AAA pattern (Arrange-Act-Assert) creates predictable, readable tests.

## Feature Testing
<a name="feature"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [feature-001-auth-testing](rules/feature-001-auth-testing.md) | **CRITICAL** | Test Authentication Properly |
| [feature-002-validation-testing](rules/feature-002-validation-testing.md) | **HIGH** | Test Request Validation Thoroughly |
| [feature-003-response-format](rules/feature-003-response-format.md) | **HIGH** | Verify API Response Format |
| [feature-004-database-testing](rules/feature-004-database-testing.md) | MEDIUM | Test Database State Changes |
| [feature-005-http-methods](rules/feature-005-http-methods.md) | MEDIUM | Test All HTTP Methods Properly |
| [feature-006-edge-cases](rules/feature-006-edge-cases.md) | MEDIUM | Test Edge Cases and Boundaries |

**feature-001-auth-testing**: Authentication is critical.

**feature-002-validation-testing**: Validation tests ensure bad data is rejected before it causes problems.

**feature-003-response-format**: Consistent API response format ensures frontend can reliably parse responses.

**feature-004-database-testing**: Feature tests should verify that data is correctly persisted.

**feature-005-http-methods**: RESTful APIs should only accept the correct HTTP methods.

**feature-006-edge-cases**: Edge cases are where bugs hide.

## E2E Testing
<a name="e2e"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [e2e-001-critical-paths](rules/e2e-001-critical-paths.md) | **CRITICAL** | Test Critical User Paths |
| [e2e-002-auth-flow](rules/e2e-002-auth-flow.md) | **HIGH** | Test Authentication Flow Completely |
| [e2e-003-waiting-strategies](rules/e2e-003-waiting-strategies.md) | **HIGH** | Use Proper Waiting Strategies |
| [e2e-004-selectors](rules/e2e-004-selectors.md) | MEDIUM | Use Stable Test Selectors |
| [e2e-005-page-objects](rules/e2e-005-page-objects.md) | MEDIUM | Use Page Object Pattern for Complex Flows |

**e2e-001-critical-paths**: E2E tests verify that critical user journeys work end-to-end.

**e2e-002-auth-flow**: Auth flows involve multiple steps, redirects, and session management.

**e2e-003-waiting-strategies**: Web apps are async.

**e2e-004-selectors**: Selectors based on class names or text break when UI changes.

**e2e-005-page-objects**: Page Objects encapsulate page-specific selectors and actions.

## UI Testing
<a name="ui"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [ui-001-responsive](rules/ui-001-responsive.md) | MEDIUM | Test Responsive Layouts |
| [ui-002-visual-regression](rules/ui-002-visual-regression.md) | MEDIUM | Use Visual Regression Testing |
| [ui-003-animations](rules/ui-003-animations.md) | LOW | Handle Animations in Tests |
| [ui-004-dark-mode](rules/ui-004-dark-mode.md) | LOW | Test Dark Mode and Theming |

**ui-001-responsive**: Users access apps from various devices.

**ui-002-visual-regression**: Visual regression tests catch unintended UI changes by comparing screenshots.

**ui-003-animations**: Animations cause test flakiness when tests run faster than animations complete.

**ui-004-dark-mode**: Users increasingly expect dark mode support.

## Accessibility Testing
<a name="a11y"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [a11y-001-basic-checks](rules/a11y-001-basic-checks.md) | MEDIUM | Run Basic Accessibility Checks |
| [a11y-002-keyboard-navigation](rules/a11y-002-keyboard-navigation.md) | MEDIUM | Test Keyboard Navigation |
| [a11y-003-screen-reader](rules/a11y-003-screen-reader.md) | MEDIUM | Test Screen Reader Support |

**a11y-001-basic-checks**: Accessibility ensures your app is usable by everyone, including people with disabilities.

**a11y-002-keyboard-navigation**: Many users navigate with keyboard only (motor disabilities, power users, screen reader users).

**a11y-003-screen-reader**: Screen reader users rely on semantic HTML and ARIA attributes to understand and navigate your app.

## Quick Reference by Tag

- **a11y**: a11y-001-basic-checks
- **aaa-pattern**: unit-006-arrange-act-assert
- **accessibility**: a11y-001-basic-checks, a11y-002-keyboard-navigation, a11y-003-screen-reader
- **animations**: ui-003-animations
- **api**: feature-003-response-format
- **aria**: a11y-003-screen-reader
- **assertions**: feature-004-database-testing, unit-003-assertion-quality
- **async**: e2e-003-waiting-strategies
- **auth**: e2e-002-auth-flow, feature-001-auth-testing
- **axe**: a11y-001-basic-checks
- **boundaries**: feature-006-edge-cases
- **breakpoints**: ui-001-responsive
- **color-scheme**: ui-004-dark-mode
- **conventions**: unit-005-test-naming
- **critical-path**: e2e-001-critical-paths
- **dark-mode**: ui-004-dark-mode
- **data-testid**: e2e-004-selectors
- **database**: feature-004-database-testing
- **e2e**: e2e-001-critical-paths, e2e-002-auth-flow, e2e-003-waiting-strategies, e2e-004-selectors, e2e-005-page-objects
- **edge-cases**: feature-006-edge-cases
- **factory**: unit-004-factory-usage
- **feature-test**: feature-001-auth-testing, feature-002-validation-testing, feature-003-response-format, feature-004-database-testing, feature-005-http-methods, feature-006-edge-cases
- **fixtures**: unit-004-factory-usage
- **flaky**: ui-003-animations, e2e-003-waiting-strategies
- **focus**: a11y-002-keyboard-navigation
- **formrequest**: feature-002-validation-testing
- **http**: feature-005-http-methods
- **http-fake**: unit-002-mock-external
- **isolation**: unit-001-isolation
- **json**: feature-003-response-format
- **keyboard**: a11y-002-keyboard-navigation
- **laravel**: unit-004-factory-usage
- **login**: e2e-002-auth-flow
- **methods**: feature-005-http-methods
- **mobile**: ui-001-responsive
- **mockery**: unit-002-mock-external
- **mocking**: unit-001-isolation, unit-002-mock-external
- **naming**: unit-005-test-naming
- **navigation**: a11y-002-keyboard-navigation
- **organization**: e2e-005-page-objects
- **page-object**: e2e-005-page-objects
- **phpunit**: unit-001-isolation, unit-003-assertion-quality, unit-005-test-naming, unit-006-arrange-act-assert
- **playwright**: e2e-001-critical-paths, e2e-002-auth-flow, e2e-003-waiting-strategies, e2e-004-selectors, e2e-005-page-objects
- **quality**: unit-003-assertion-quality
- **regression**: ui-002-visual-regression
- **response**: feature-003-response-format
- **responsive**: ui-001-responsive
- **rest**: feature-005-http-methods
- **robustness**: feature-006-edge-cases
- **sanctum**: feature-001-auth-testing
- **screen-reader**: a11y-003-screen-reader
- **screenshots**: ui-002-visual-regression
- **security**: e2e-002-auth-flow, feature-001-auth-testing, feature-002-validation-testing
- **selectors**: e2e-004-selectors
- **semantics**: a11y-003-screen-reader
- **state**: feature-004-database-testing
- **structure**: unit-006-arrange-act-assert
- **theming**: ui-004-dark-mode
- **transitions**: ui-003-animations
- **ui-test**: ui-001-responsive, ui-002-visual-regression, ui-003-animations, ui-004-dark-mode
- **unit-test**: unit-001-isolation, unit-002-mock-external, unit-003-assertion-quality, unit-004-factory-usage, unit-005-test-naming, unit-006-arrange-act-assert
- **user-flow**: e2e-001-critical-paths
- **validation**: feature-002-validation-testing
- **visual**: ui-002-visual-regression
- **waiting**: e2e-003-waiting-strategies
- **wcag**: a11y-001-basic-checks
