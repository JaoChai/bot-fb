# Refactor Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 13:30

## Table of Contents

**Total Rules: 20**

- [Laravel Refactoring](#laravel) - 6 rules (2 HIGH)
- [React Refactoring](#react) - 5 rules (4 HIGH)
- [Database Refactoring](#db) - 4 rules (2 HIGH)
- [Code Smell Detection](#smell) - 3 rules (2 HIGH)
- [Design Patterns](#pattern) - 2 rules (1 HIGH)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## Laravel Refactoring
<a name="laravel"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [laravel-002-extract-service](rules/laravel-002-extract-service.md) | **HIGH** | Extract Service Refactoring |
| [laravel-006-job-extraction](rules/laravel-006-job-extraction.md) | **HIGH** | Extract Job Refactoring |
| [laravel-001-extract-method](rules/laravel-001-extract-method.md) | MEDIUM | Extract Method Refactoring |
| [laravel-003-extract-trait](rules/laravel-003-extract-trait.md) | MEDIUM | Extract Trait Refactoring |
| [laravel-004-form-request](rules/laravel-004-form-request.md) | MEDIUM | Extract FormRequest Refactoring |
| [laravel-005-query-scope](rules/laravel-005-query-scope.md) | MEDIUM | Extract Query Scope Refactoring |

## React Refactoring
<a name="react"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [react-001-extract-component](rules/react-001-extract-component.md) | **HIGH** | Extract Component Refactoring |
| [react-002-extract-hook](rules/react-002-extract-hook.md) | **HIGH** | Extract Custom Hook Refactoring |
| [react-004-context-pattern](rules/react-004-context-pattern.md) | **HIGH** | Context/Zustand Pattern Refactoring |
| [react-005-state-refactor](rules/react-005-state-refactor.md) | **HIGH** | State Management Refactoring |
| [react-003-extract-utility](rules/react-003-extract-utility.md) | MEDIUM | Extract Utility Function Refactoring |

## Database Refactoring
<a name="db"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [db-001-eager-loading](rules/db-001-eager-loading.md) | **HIGH** | Eager Loading Refactoring |
| [db-002-query-optimization](rules/db-002-query-optimization.md) | **HIGH** | Query Optimization Refactoring |
| [db-003-add-index](rules/db-003-add-index.md) | MEDIUM | Add Database Index |
| [db-004-migration-refactor](rules/db-004-migration-refactor.md) | MEDIUM | Migration Refactoring |

## Code Smell Detection
<a name="smell"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [smell-001-long-method](rules/smell-001-long-method.md) | **HIGH** | Long Method Detection |
| [smell-002-duplicate-code](rules/smell-002-duplicate-code.md) | **HIGH** | Duplicate Code Detection |
| [smell-003-complex-conditional](rules/smell-003-complex-conditional.md) | MEDIUM | Complex Conditional Detection |

## Design Patterns
<a name="pattern"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [pattern-001-strategy-pattern](rules/pattern-001-strategy-pattern.md) | **HIGH** | Strategy Pattern Refactoring |
| [pattern-002-repository-pattern](rules/pattern-002-repository-pattern.md) | MEDIUM | Repository Pattern Refactoring |

## Quick Reference by Tag

- **abstraction**: pattern-002-repository-pattern
- **architecture**: laravel-002-extract-service
- **async**: laravel-006-job-extraction
- **clean-code**: laravel-004-form-request
- **code-smell**: smell-001-long-method, smell-002-duplicate-code, smell-003-complex-conditional
- **complexity**: react-005-state-refactor, smell-003-complex-conditional
- **component**: react-001-extract-component
- **composition**: react-001-extract-component
- **conditional**: smell-003-complex-conditional
- **context**: react-004-context-pattern
- **controller**: laravel-002-extract-service, laravel-004-form-request
- **data-access**: pattern-002-repository-pattern
- **database**: db-004-migration-refactor
- **design-pattern**: pattern-001-strategy-pattern, pattern-002-repository-pattern
- **dry**: laravel-003-extract-trait, laravel-005-query-scope, react-003-extract-utility, smell-002-duplicate-code
- **duplication**: smell-002-duplicate-code
- **eager-loading**: db-001-eager-loading
- **eloquent**: laravel-005-query-scope, db-001-eager-loading, db-002-query-optimization
- **extensibility**: pattern-001-strategy-pattern
- **extract**: laravel-002-extract-service, laravel-001-extract-method, laravel-003-extract-trait, react-001-extract-component, react-002-extract-hook, react-003-extract-utility, smell-001-long-method
- **formrequest**: laravel-004-form-request
- **hook**: react-002-extract-hook
- **index**: db-003-add-index
- **job**: laravel-006-job-extraction
- **long-method**: smell-001-long-method
- **maintainability**: laravel-001-extract-method
- **method**: laravel-001-extract-method
- **migration**: db-003-add-index, db-004-migration-refactor
- **n-plus-one**: db-001-eager-loading
- **optimization**: db-002-query-optimization
- **performance**: laravel-006-job-extraction, db-001-eager-loading, db-002-query-optimization, db-003-add-index
- **polymorphism**: pattern-001-strategy-pattern
- **postgresql**: db-003-add-index
- **prop-drilling**: react-004-context-pattern
- **pure-function**: react-003-extract-utility
- **query**: laravel-005-query-scope, db-002-query-optimization
- **queue**: laravel-006-job-extraction
- **react**: react-001-extract-component, react-002-extract-hook
- **readability**: laravel-001-extract-method, smell-001-long-method, smell-003-complex-conditional
- **reducer**: react-005-state-refactor
- **refactor**: smell-002-duplicate-code
- **repository**: pattern-002-repository-pattern
- **reuse**: laravel-003-extract-trait, react-002-extract-hook
- **safety**: db-004-migration-refactor
- **schema**: db-004-migration-refactor
- **scope**: laravel-005-query-scope
- **service**: laravel-002-extract-service
- **state**: react-004-context-pattern, react-005-state-refactor
- **strategy**: pattern-001-strategy-pattern
- **trait**: laravel-003-extract-trait
- **utility**: react-003-extract-utility
- **validation**: laravel-004-form-request
- **zustand**: react-004-context-pattern, react-005-state-refactor
