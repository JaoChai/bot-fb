# Backend Dev Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 11:50

## Table of Contents

**Total Rules: 42**

- [Gotchas (Common Mistakes)](#gotcha) - 5 rules (3 CRITICAL)
- [Laravel Core](#laravel) - 8 rules (1 CRITICAL)
- [Eloquent](#eloquent) - 7 rules (1 CRITICAL)
- [API Design](#api) - 8 rules (1 CRITICAL)
- [Queue Jobs](#job) - 4 rules (1 CRITICAL)
- [Events & Broadcasting](#event) - 3 rules (2 HIGH)
- [Authorization](#policy) - 3 rules (1 CRITICAL)
- [Security](#security) - 4 rules (2 CRITICAL)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## Gotchas (Common Mistakes)
<a name="gotcha"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [gotcha-001-config-null-coalesce](rules/gotcha-001-config-null-coalesce.md) | **CRITICAL** | Config Default Returns Null, Not Default |
| [gotcha-002-n-plus-one-queries](rules/gotcha-002-n-plus-one-queries.md) | **CRITICAL** | N+1 Query Problem |
| [gotcha-003-race-condition-locks](rules/gotcha-003-race-condition-locks.md) | **CRITICAL** | Race Conditions and Database Locks |
| [gotcha-004-env-vs-config](rules/gotcha-004-env-vs-config.md) | **HIGH** | Env vs Config Usage |
| [gotcha-005-mass-assignment](rules/gotcha-005-mass-assignment.md) | **HIGH** | Model Mass Assignment Protection |

**gotcha-001-config-null-coalesce**: Laravel's `config('key', 'default')` returns the second parameter ONLY if the key doesn't exist.

**gotcha-002-n-plus-one-queries**: The N+1 query problem occurs when you fetch a collection of records (1 query) and then access a relationship for each record in a loop (N queries).

**gotcha-003-race-condition-locks**: Race conditions occur when multiple requests try to read-then-write the same data simultaneously.

**gotcha-004-env-vs-config**: In production, Laravel caches configuration files.

**gotcha-005-mass-assignment**: Mass assignment allows setting multiple model attributes at once.

## Laravel Core
<a name="laravel"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [laravel-003-service-layer](rules/laravel-003-service-layer.md) | **CRITICAL** | Service Layer Pattern |
| [laravel-001-thin-controller](rules/laravel-001-thin-controller.md) | **HIGH** | Thin Controller Pattern |
| [laravel-004-formrequest](rules/laravel-004-formrequest.md) | **HIGH** | FormRequest Validation Classes |
| [laravel-005-api-resource](rules/laravel-005-api-resource.md) | **HIGH** | API Resource Transformation |
| [laravel-002-service-provider](rules/laravel-002-service-provider.md) | MEDIUM | Service Provider Registration |
| [laravel-006-config-organization](rules/laravel-006-config-organization.md) | MEDIUM | Config File Organization |
| [laravel-007-route-organization](rules/laravel-007-route-organization.md) | MEDIUM | Route Organization |
| [laravel-008-middleware](rules/laravel-008-middleware.md) | MEDIUM | Middleware Usage |

**laravel-003-service-layer**: The service layer pattern keeps controllers thin and business logic centralized.

**laravel-001-thin-controller**: Controllers should be thin - they coordinate request/response flow, not implement business logic.

**laravel-004-formrequest**: FormRequest classes extract validation logic from controllers, making it reusable and testable.

**laravel-005-api-resource**: API Resources transform models into JSON responses.

**laravel-002-service-provider**: Service providers are the central place for bootstrapping Laravel applications.

**laravel-006-config-organization**: Well-organized config files make settings discoverable and maintainable.

**laravel-007-route-organization**: Well-organized routes make APIs discoverable and maintainable.

**laravel-008-middleware**: Middleware handles cross-cutting concerns like authentication, rate limiting, and logging.

## Eloquent
<a name="eloquent"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [eloquent-001-eager-loading](rules/eloquent-001-eager-loading.md) | **CRITICAL** | Eager Loading Relationships |
| [eloquent-002-query-scopes](rules/eloquent-002-query-scopes.md) | **HIGH** | Query Scopes |
| [eloquent-003-model-casts](rules/eloquent-003-model-casts.md) | **HIGH** | Model Attribute Casts |
| [eloquent-004-soft-deletes](rules/eloquent-004-soft-deletes.md) | MEDIUM | Soft Deletes |
| [eloquent-005-accessors-mutators](rules/eloquent-005-accessors-mutators.md) | MEDIUM | Accessors and Mutators |
| [eloquent-006-model-events](rules/eloquent-006-model-events.md) | MEDIUM | Model Events |
| [eloquent-007-factories](rules/eloquent-007-factories.md) | LOW | Model Factories |

**eloquent-001-eager-loading**: Eager loading fetches related records in a single query instead of one query per record.

**eloquent-002-query-scopes**: Query scopes encapsulate common query constraints in the model, making them reusable and keeping controllers clean.

**eloquent-003-model-casts**: Casts automatically convert database values to PHP types.

**eloquent-004-soft-deletes**: Soft deletes keep deleted records in the database with a `deleted_at` timestamp instead of permanently removing them.

**eloquent-005-accessors-mutators**: Accessors transform data when reading from model, mutators when writing.

**eloquent-006-model-events**: Model events fire automatically during model lifecycle (creating, created, updating, etc.

**eloquent-007-factories**: Factories generate fake model instances for testing and seeding.

## API Design
<a name="api"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [api-001-response-format](rules/api-001-response-format.md) | **CRITICAL** | Standard API Response Format |
| [api-002-restful-naming](rules/api-002-restful-naming.md) | **HIGH** | RESTful Naming Conventions |
| [api-003-http-status](rules/api-003-http-status.md) | **HIGH** | HTTP Status Codes |
| [api-004-pagination](rules/api-004-pagination.md) | **HIGH** | API Pagination |
| [api-005-filtering-sorting](rules/api-005-filtering-sorting.md) | MEDIUM | Filtering and Sorting |
| [api-006-versioning](rules/api-006-versioning.md) | MEDIUM | API Versioning |
| [api-007-rate-limiting](rules/api-007-rate-limiting.md) | MEDIUM | Rate Limiting |
| [api-008-documentation](rules/api-008-documentation.md) | LOW | API Documentation |

**api-001-response-format**: A consistent API response format makes frontend integration predictable and error handling uniform.

**api-002-restful-naming**: RESTful naming conventions make APIs predictable and self-documenting.

**api-003-http-status**: HTTP status codes communicate request outcome.

**api-004-pagination**: Returning all records without pagination causes memory exhaustion, slow responses, and database strain.

**api-005-filtering-sorting**: Filtering and sorting via query parameters makes APIs flexible without creating separate endpoints for each use case.

**api-006-versioning**: API versioning allows evolving your API without breaking existing clients.

**api-007-rate-limiting**: Rate limiting protects your API from abuse, prevents DoS attacks, and ensures fair usage across clients.

**api-008-documentation**: Good API documentation makes integration easy and reduces support burden.

## Queue Jobs
<a name="job"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [job-001-retry-configuration](rules/job-001-retry-configuration.md) | **CRITICAL** | Job Retry Configuration |
| [job-002-failed-handling](rules/job-002-failed-handling.md) | **HIGH** | Failed Job Handling |
| [job-003-dispatching](rules/job-003-dispatching.md) | **HIGH** | Job Dispatching Patterns |
| [job-004-chaining](rules/job-004-chaining.md) | MEDIUM | Job Chaining |

**job-001-retry-configuration**: Queue jobs can fail due to temporary issues (network timeouts, API rate limits, database locks).

**job-002-failed-handling**: Jobs can fail permanently after all retries.

**job-003-dispatching**: Proper job dispatching ensures jobs go to the right queue, run at the right time, and don't overwhelm the system.

**job-004-chaining**: Job chaining ensures jobs run sequentially when order matters.

## Events & Broadcasting
<a name="event"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [event-001-dispatching](rules/event-001-dispatching.md) | **HIGH** | Event Dispatching |
| [event-002-broadcasting](rules/event-002-broadcasting.md) | **HIGH** | Broadcasting with Reverb |
| [event-003-listener-queuing](rules/event-003-listener-queuing.md) | MEDIUM | Listener Queuing |

**event-001-dispatching**: Events decouple components - the code that triggers an action doesn't need to know all consequences.

**event-002-broadcasting**: Broadcasting sends events to clients via WebSockets for real-time updates.

**event-003-listener-queuing**: Queued listeners run asynchronously, preventing slow operations from blocking HTTP responses.

## Authorization
<a name="policy"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [policy-001-authorization](rules/policy-001-authorization.md) | **CRITICAL** | Policy-Based Authorization |
| [policy-002-controller-authorize](rules/policy-002-controller-authorize.md) | **HIGH** | Controller Authorization Calls |
| [policy-003-gates](rules/policy-003-gates.md) | MEDIUM | Gate Definitions |

**policy-001-authorization**: Every resource access must be authorized.

**policy-002-controller-authorize**: Every controller action that accesses user-specific resources must have an authorization check.

**policy-003-gates**: Gates handle authorization that doesn't fit the resource-policy model - like feature flags, role-based access, or cross-cutting permissions.

## Security
<a name="security"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [security-001-input-validation](rules/security-001-input-validation.md) | **CRITICAL** | Input Validation with FormRequest |
| [security-002-sql-injection](rules/security-002-sql-injection.md) | **CRITICAL** | SQL Injection Prevention |
| [security-003-mass-assignment-protection](rules/security-003-mass-assignment-protection.md) | **HIGH** | Mass Assignment Protection |
| [security-004-sensitive-data](rules/security-004-sensitive-data.md) | **HIGH** | Sensitive Data Handling |

**security-001-input-validation**: All user input must be validated before use.

**security-002-sql-injection**: SQL injection allows attackers to execute arbitrary SQL queries, potentially reading all data, modifying records, or deleting the entire database.

**security-003-mass-assignment-protection**: Mass assignment vulnerabilities allow attackers to modify fields they shouldn't access by adding extra fields to requests.

**security-004-sensitive-data**: Sensitive data (passwords, API keys, tokens) must never appear in logs, responses, or error messages.

## Quick Reference by Tag

- **access-control**: policy-001-authorization
- **accessor**: eloquent-005-accessors-mutators
- **api**: laravel-005-api-resource, api-001-response-format, api-002-restful-naming, api-003-http-status, api-004-pagination, api-005-filtering-sorting, api-006-versioning, api-007-rate-limiting, api-008-documentation
- **architecture**: event-001-dispatching, laravel-003-service-layer, laravel-001-thin-controller
- **async**: event-003-listener-queuing, job-003-dispatching
- **authorization**: policy-001-authorization, policy-002-controller-authorize, policy-003-gates
- **batch**: job-004-chaining
- **broadcasting**: event-002-broadcasting
- **caching**: gotcha-004-env-vs-config
- **casts**: eloquent-003-model-casts
- **chain**: job-004-chaining
- **compatibility**: api-006-versioning
- **concurrency**: gotcha-003-race-condition-locks
- **config**: laravel-006-config-organization, gotcha-001-config-null-coalesce, gotcha-004-env-vs-config
- **container**: laravel-002-service-provider
- **controller**: laravel-001-thin-controller, laravel-004-formrequest, policy-002-controller-authorize
- **database**: eloquent-004-soft-deletes, gotcha-003-race-condition-locks, security-002-sql-injection, api-004-pagination
- **dependency-injection**: laravel-002-service-provider
- **dispatch**: job-003-dispatching
- **documentation**: api-008-documentation
- **eloquent**: eloquent-001-eager-loading, eloquent-002-query-scopes, eloquent-003-model-casts, eloquent-004-soft-deletes, eloquent-005-accessors-mutators, eloquent-006-model-events, eloquent-007-factories, gotcha-002-n-plus-one-queries
- **encryption**: security-004-sensitive-data
- **env**: gotcha-004-env-vs-config
- **event**: event-001-dispatching, event-002-broadcasting, event-003-listener-queuing
- **events**: eloquent-006-model-events
- **factory**: eloquent-007-factories
- **failure**: job-001-retry-configuration, job-002-failed-handling
- **feature**: policy-003-gates
- **fillable**: gotcha-005-mass-assignment
- **filter**: api-005-filtering-sorting
- **format**: api-001-response-format
- **formrequest**: laravel-004-formrequest, security-001-input-validation
- **gate**: policy-003-gates
- **gotcha**: gotcha-001-config-null-coalesce, gotcha-004-env-vs-config
- **http**: api-003-http-status
- **injection**: security-002-sql-injection
- **input**: laravel-004-formrequest, security-001-input-validation
- **job**: job-001-retry-configuration, job-002-failed-handling, job-003-dispatching, job-004-chaining
- **json**: api-001-response-format
- **laravel**: gotcha-001-config-null-coalesce
- **lifecycle**: eloquent-006-model-events
- **listener**: event-001-dispatching, event-003-listener-queuing
- **lock**: gotcha-003-race-condition-locks
- **logging**: security-004-sensitive-data
- **mass-assignment**: gotcha-005-mass-assignment, security-003-mass-assignment-protection
- **middleware**: laravel-007-route-organization, laravel-008-middleware
- **model**: eloquent-002-query-scopes, eloquent-003-model-casts, eloquent-004-soft-deletes, eloquent-005-accessors-mutators, eloquent-006-model-events, gotcha-005-mass-assignment, security-003-mass-assignment-protection
- **monitoring**: job-002-failed-handling
- **mutator**: eloquent-005-accessors-mutators
- **n+1**: gotcha-002-n-plus-one-queries
- **naming**: api-002-restful-naming
- **null**: gotcha-001-config-null-coalesce
- **openapi**: api-008-documentation
- **organization**: laravel-006-config-organization, laravel-007-route-organization
- **pagination**: api-004-pagination
- **pattern**: laravel-003-service-layer, laravel-001-thin-controller
- **performance**: eloquent-001-eager-loading, gotcha-002-n-plus-one-queries, api-004-pagination
- **policy**: policy-001-authorization, policy-002-controller-authorize
- **query**: eloquent-001-eager-loading, eloquent-002-query-scopes, gotcha-002-n-plus-one-queries
- **query-params**: api-005-filtering-sorting
- **queue**: event-003-listener-queuing, job-001-retry-configuration, job-002-failed-handling, job-003-dispatching, job-004-chaining
- **race-condition**: gotcha-003-race-condition-locks
- **rate-limiting**: api-007-rate-limiting
- **relationships**: eloquent-001-eager-loading
- **request**: laravel-008-middleware
- **resource**: laravel-005-api-resource
- **response**: laravel-005-api-resource, api-001-response-format, api-003-http-status
- **rest**: api-002-restful-naming
- **retry**: job-001-retry-configuration
- **reverb**: event-002-broadcasting
- **routes**: laravel-007-route-organization, api-002-restful-naming
- **scope**: eloquent-002-query-scopes
- **secrets**: security-004-sensitive-data
- **security**: laravel-008-middleware, gotcha-005-mass-assignment, security-001-input-validation, security-002-sql-injection, security-003-mass-assignment-protection, security-004-sensitive-data, api-007-rate-limiting, policy-001-authorization, policy-002-controller-authorize, policy-003-gates
- **seeding**: eloquent-007-factories
- **service**: laravel-003-service-layer
- **service-provider**: laravel-002-service-provider
- **settings**: laravel-006-config-organization
- **soft-delete**: eloquent-004-soft-deletes
- **sort**: api-005-filtering-sorting
- **sql**: security-002-sql-injection
- **status**: api-003-http-status
- **testing**: eloquent-007-factories, laravel-003-service-layer, laravel-001-thin-controller
- **throttle**: api-007-rate-limiting
- **transformation**: laravel-005-api-resource
- **types**: eloquent-003-model-casts
- **validation**: laravel-004-formrequest, security-001-input-validation, security-003-mass-assignment-protection
- **versioning**: api-006-versioning
- **websocket**: event-002-broadcasting
