# Code Review Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 12:14

## Table of Contents

**Total Rules: 32**

- [Laravel Review](#backend) - 8 rules (2 HIGH)
- [React Review](#frontend) - 8 rules (3 HIGH)
- [Security Check](#security) - 6 rules (4 CRITICAL)
- [API Design](#api) - 5 rules (3 HIGH)
- [Performance](#perf) - 5 rules (1 HIGH)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## Laravel Review
<a name="backend"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [backend-001-thin-controller](rules/backend-001-thin-controller.md) | **HIGH** | Thin Controller Pattern |
| [backend-002-service-layer](rules/backend-002-service-layer.md) | **HIGH** | Service Layer Pattern |
| [backend-003-formrequest](rules/backend-003-formrequest.md) | MEDIUM | FormRequest Validation |
| [backend-004-api-resource](rules/backend-004-api-resource.md) | MEDIUM | API Resource Transformation |
| [backend-005-service-dependencies](rules/backend-005-service-dependencies.md) | MEDIUM | Service Dependencies Management |
| [backend-006-lazy-loading](rules/backend-006-lazy-loading.md) | MEDIUM | Avoid Lazy Loading in Services |
| [backend-007-model-logic](rules/backend-007-model-logic.md) | MEDIUM | Keep Models Thin |
| [backend-008-relationships](rules/backend-008-relationships.md) | MEDIUM | Define All Relationships |

**backend-001-thin-controller**: Controllers should only handle HTTP concerns (request/response).

**backend-002-service-layer**: Services encapsulate business logic in reusable, testable classes.

**backend-003-formrequest**: FormRequest classes separate validation from controller logic, making validation reusable, testable, and keeping controllers clean.

**backend-004-api-resource**: API Resources control exactly what data is returned, preventing accidental exposure of sensitive fields and ensuring consistent response structure.

**backend-005-service-dependencies**: Services with many dependencies (>5) often violate Single Responsibility Principle.

**backend-006-lazy-loading**: Database queries or heavy operations in constructors execute on every service instantiation, even when those features aren't used.

**backend-007-model-logic**: Models should represent data structure and relationships.

**backend-008-relationships**: Eloquent relationships enable eager loading, automatic joins, and clean query syntax.

## React Review
<a name="frontend"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [frontend-001-component-size](rules/frontend-001-component-size.md) | **HIGH** | Component Size Limits |
| [frontend-002-custom-hooks](rules/frontend-002-custom-hooks.md) | **HIGH** | Extract Custom Hooks |
| [frontend-005-hook-deps](rules/frontend-005-hook-deps.md) | **HIGH** | Correct Hook Dependencies |
| [frontend-003-prop-drilling](rules/frontend-003-prop-drilling.md) | MEDIUM | Avoid Prop Drilling |
| [frontend-004-list-keys](rules/frontend-004-list-keys.md) | MEDIUM | Unique Keys for Lists |
| [frontend-006-memoization](rules/frontend-006-memoization.md) | MEDIUM | Appropriate Memoization |
| [frontend-007-cleanup](rules/frontend-007-cleanup.md) | MEDIUM | Effect Cleanup Functions |
| [frontend-008-loading-states](rules/frontend-008-loading-states.md) | MEDIUM | Handle Loading and Error States |

**frontend-001-component-size**: Components over 150-200 lines typically do too much.

**frontend-002-custom-hooks**: Custom hooks extract reusable logic from components.

**frontend-005-hook-deps**: Hook dependencies determine when effects run and when callbacks update.

**frontend-003-prop-drilling**: Prop drilling (passing props through 3+ levels) creates tight coupling between components.

**frontend-004-list-keys**: React uses keys to track list items.

**frontend-006-memoization**: Memoization prevents unnecessary recalculations and re-renders.

**frontend-007-cleanup**: Effects that set up subscriptions, timers, or async operations must clean up when the component unmounts or deps change.

**frontend-008-loading-states**: Every async operation has loading and error states.

## Security Check
<a name="security"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [security-001-sql-injection](rules/security-001-sql-injection.md) | **CRITICAL** | SQL Injection Prevention |
| [security-002-xss](rules/security-002-xss.md) | **CRITICAL** | XSS (Cross-Site Scripting) Prevention |
| [security-003-path-traversal](rules/security-003-path-traversal.md) | **CRITICAL** | Path Traversal Prevention |
| [security-004-command-injection](rules/security-004-command-injection.md) | **CRITICAL** | Command Injection Prevention |
| [security-005-auth-bypass](rules/security-005-auth-bypass.md) | **HIGH** | Authentication Bypass Prevention |
| [security-006-data-exposure](rules/security-006-data-exposure.md) | **HIGH** | Sensitive Data Exposure Prevention |

**security-001-sql-injection**: SQL injection allows attackers to manipulate database queries, potentially accessing sensitive data, modifying records, or deleting entire tables.

**security-002-xss**: XSS allows attackers to inject malicious scripts that run in victims' browsers, stealing sessions, credentials, or performing actions as the user.

**security-003-path-traversal**: Path traversal allows attackers to access files outside the intended directory using `.

**security-004-command-injection**: Command injection allows attackers to execute arbitrary system commands on the server, potentially gaining full system access, exfiltrating data, o...

**security-005-auth-bypass**: Authentication bypass allows attackers to access protected resources without proper credentials, potentially viewing, modifying, or deleting other ...

**security-006-data-exposure**: Exposing sensitive data through API responses, logs, or error messages can lead to credential theft, privacy violations, and regulatory non-complia...

## API Design
<a name="api"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [api-001-restful-naming](rules/api-001-restful-naming.md) | **HIGH** | RESTful Resource Naming |
| [api-003-validation](rules/api-003-validation.md) | **HIGH** | API Input Validation |
| [api-004-response-format](rules/api-004-response-format.md) | **HIGH** | Consistent Response Format |
| [api-002-http-verbs](rules/api-002-http-verbs.md) | MEDIUM | Correct HTTP Methods |
| [api-005-error-handling](rules/api-005-error-handling.md) | MEDIUM | API Error Handling |

**api-001-restful-naming**: RESTful naming conventions make APIs predictable and self-documenting.

**api-003-validation**: APIs must validate all input.

**api-004-response-format**: Consistent response formats make APIs predictable and easier to integrate.

**api-002-http-verbs**: HTTP methods have specific semantics.

**api-005-error-handling**: Good error responses help clients handle failures gracefully and developers debug issues quickly.

## Performance
<a name="perf"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [perf-001-n-plus-one](rules/perf-001-n-plus-one.md) | **HIGH** | N+1 Query Detection |
| [perf-002-missing-index](rules/perf-002-missing-index.md) | MEDIUM | Missing Database Indexes |
| [perf-003-over-fetching](rules/perf-003-over-fetching.md) | MEDIUM | Avoid Over-Fetching Data |
| [perf-004-pagination](rules/perf-004-pagination.md) | MEDIUM | Use Pagination for Lists |
| [perf-005-render-optimization](rules/perf-005-render-optimization.md) | MEDIUM | Frontend Render Optimization |

**perf-001-n-plus-one**: N+1 queries occur when you load a list then query each item individually.

**perf-002-missing-index**: Indexes speed up queries dramatically.

**perf-003-over-fetching**: Fetching all columns when you only need a few wastes memory and bandwidth.

**perf-004-pagination**: Loading thousands of records into memory crashes servers and times out requests.

**perf-005-render-optimization**: React re-renders components when state or props change.

## Quick Reference by Tag

- **api**: api-001-restful-naming, api-003-validation, api-004-response-format, api-002-http-verbs, api-005-error-handling, backend-004-api-resource, perf-004-pagination
- **architecture**: backend-001-thin-controller, backend-002-service-layer, backend-007-model-logic, frontend-001-component-size
- **authentication**: security-005-auth-bypass
- **authorization**: security-005-auth-bypass
- **clean-code**: backend-003-formrequest
- **cleanup**: frontend-007-cleanup
- **command-injection**: security-004-command-injection
- **component**: frontend-001-component-size
- **constructor**: backend-006-lazy-loading
- **context**: frontend-003-prop-drilling
- **controller**: backend-001-thin-controller
- **conventions**: api-001-restful-naming
- **data-exposure**: security-006-data-exposure
- **database**: perf-001-n-plus-one, perf-002-missing-index, perf-003-over-fetching
- **dependencies**: frontend-005-hook-deps
- **dependency-injection**: backend-005-service-dependencies
- **eloquent**: backend-007-model-logic, backend-008-relationships, perf-001-n-plus-one, perf-003-over-fetching
- **error**: frontend-008-loading-states
- **errors**: api-005-error-handling
- **exceptions**: api-005-error-handling
- **files**: security-003-path-traversal
- **formrequest**: api-003-validation, backend-003-formrequest
- **frontend**: security-002-xss
- **hooks**: frontend-002-custom-hooks, frontend-005-hook-deps
- **http**: api-002-http-verbs
- **index**: perf-002-missing-index
- **injection**: security-001-sql-injection
- **json**: api-004-response-format
- **keys**: frontend-004-list-keys
- **laravel**: backend-001-thin-controller, backend-002-service-layer, backend-003-formrequest, backend-004-api-resource, backend-005-service-dependencies, backend-006-lazy-loading, backend-007-model-logic, backend-008-relationships
- **lists**: frontend-004-list-keys
- **loading**: frontend-008-loading-states
- **memo**: frontend-006-memoization
- **memory**: perf-004-pagination
- **memory-leak**: frontend-007-cleanup
- **methods**: api-002-http-verbs
- **model**: backend-007-model-logic
- **n+1**: perf-001-n-plus-one
- **naming**: api-001-restful-naming
- **optimization**: perf-005-render-optimization
- **orm**: backend-008-relationships
- **owasp**: security-001-sql-injection, security-002-xss, security-003-path-traversal, security-004-command-injection, security-005-auth-bypass, security-006-data-exposure
- **pagination**: perf-004-pagination
- **path-traversal**: security-003-path-traversal
- **pattern**: backend-002-service-layer
- **performance**: backend-006-lazy-loading, frontend-004-list-keys, frontend-006-memoization, perf-001-n-plus-one, perf-002-missing-index, perf-003-over-fetching, perf-004-pagination, perf-005-render-optimization
- **pii**: security-006-data-exposure
- **props**: frontend-003-prop-drilling
- **query**: perf-002-missing-index
- **react**: frontend-001-component-size, frontend-002-custom-hooks, frontend-005-hook-deps, frontend-003-prop-drilling, frontend-004-list-keys, frontend-006-memoization, frontend-007-cleanup, frontend-008-loading-states, perf-005-render-optimization
- **refactoring**: frontend-001-component-size, frontend-002-custom-hooks
- **relationships**: backend-008-relationships
- **render**: perf-005-render-optimization
- **resource**: backend-004-api-resource
- **response**: api-004-response-format
- **rest**: api-001-restful-naming, api-002-http-verbs
- **reusability**: frontend-002-custom-hooks
- **security**: security-001-sql-injection, security-002-xss, security-003-path-traversal, security-004-command-injection, security-005-auth-bypass, security-006-data-exposure, api-003-validation
- **select**: perf-003-over-fetching
- **service**: backend-002-service-layer, backend-005-service-dependencies, backend-006-lazy-loading
- **shell**: security-004-command-injection
- **solid**: backend-001-thin-controller, backend-005-service-dependencies
- **sql**: security-001-sql-injection
- **standards**: api-004-response-format, api-005-error-handling
- **state-management**: frontend-003-prop-drilling, frontend-008-loading-states
- **transformation**: backend-004-api-resource
- **useCallback**: frontend-006-memoization
- **useEffect**: frontend-005-hook-deps, frontend-007-cleanup
- **useMemo**: frontend-006-memoization
- **ux**: frontend-008-loading-states
- **validation**: api-003-validation, backend-003-formrequest
- **xss**: security-002-xss
- **zustand**: frontend-003-prop-drilling
