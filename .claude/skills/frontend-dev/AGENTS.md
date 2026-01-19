# Frontend Development Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 11:09

## Table of Contents

**Total Rules: 38**

- [Gotchas (Common Mistakes)](#gotcha) - 5 rules (2 CRITICAL)
- [React](#react) - 8 rules (1 CRITICAL)
- [React Query](#query) - 7 rules (1 CRITICAL)
- [State Management](#state) - 3 rules (1 HIGH)
- [Performance](#perf) - 5 rules (1 CRITICAL)
- [Accessibility](#a11y) - 4 rules (2 HIGH)
- [Styling](#style) - 3 rules
- [TypeScript](#ts) - 3 rules (2 HIGH)

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
| [gotcha-001-response-data-access](rules/gotcha-001-response-data-access.md) | **CRITICAL** | API Response Data Access |
| [gotcha-002-infinite-rerenders](rules/gotcha-002-infinite-rerenders.md) | **CRITICAL** | Infinite Re-render Prevention |
| [gotcha-003-echo-auth-token](rules/gotcha-003-echo-auth-token.md) | **HIGH** | Laravel Echo Authentication Token |
| [gotcha-004-modal-event-bubbling](rules/gotcha-004-modal-event-bubbling.md) | MEDIUM | Modal Event Bubbling |
| [gotcha-005-form-submit-button](rules/gotcha-005-form-submit-button.md) | MEDIUM | Form Submit Button Type Attribute |

**gotcha-001-response-data-access**: BotFacebook's API responses are wrapped in a standard format by Laravel.

**gotcha-002-infinite-rerenders**: Infinite re-renders freeze the browser and crash the application.

**gotcha-003-echo-auth-token**: BotFacebook uses Laravel Reverb for real-time features (live chat, notifications, presence).

**gotcha-004-modal-event-bubbling**: Modals in BotFacebook use Radix UI's Dialog component, which closes when clicking the overlay.

**gotcha-005-form-submit-button**: HTML buttons inside forms default to `type="submit"`, but this default can be affected by component libraries or button wrappers.

## React
<a name="react"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [react-006-error-boundaries](rules/react-006-error-boundaries.md) | **CRITICAL** | Error Boundaries |
| [react-001-component-structure](rules/react-001-component-structure.md) | **HIGH** | Component Structure and Props |
| [react-003-use-hook](rules/react-003-use-hook.md) | **HIGH** | use() Hook for Promises (React 19) |
| [react-004-use-action-state](rules/react-004-use-action-state.md) | **HIGH** | useActionState for Forms (React 19) |
| [react-007-suspense-boundaries](rules/react-007-suspense-boundaries.md) | **HIGH** | Suspense Boundaries |
| [react-002-use-client-directive](rules/react-002-use-client-directive.md) | MEDIUM | 'use client' Directive Placement |
| [react-005-use-optimistic](rules/react-005-use-optimistic.md) | MEDIUM | useOptimistic for Instant Feedback (React 19) |
| [react-008-generic-components](rules/react-008-generic-components.md) | MEDIUM | Generic Components with TypeScript |

**react-006-error-boundaries**: Without error boundaries, a JavaScript error in one component can crash your entire React app.

**react-001-component-structure**: Consistent component structure makes code easier to read, maintain, and debug.

**react-003-use-hook**: React 19's `use()` hook allows reading promises and context directly in render.

**react-004-use-action-state**: React 19's `useActionState` provides a cleaner way to handle form submissions with automatic pending state management.

**react-007-suspense-boundaries**: Suspense lets you declaratively specify loading states for async components.

**react-002-use-client-directive**: React 19 introduces Server Components as the default.

**react-005-use-optimistic**: `useOptimistic` lets you show optimistic state while an async action is pending.

**react-008-generic-components**: Generic components let you build reusable UI that maintains type safety regardless of the data type.

## React Query
<a name="query"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [query-001-query-keys-factory](rules/query-001-query-keys-factory.md) | **CRITICAL** | Query Keys Factory Pattern |
| [query-002-enabled-option](rules/query-002-enabled-option.md) | **HIGH** | Conditional Fetching with enabled Option |
| [query-003-optimistic-updates](rules/query-003-optimistic-updates.md) | **HIGH** | Optimistic Updates in Mutations |
| [query-004-cache-invalidation](rules/query-004-cache-invalidation.md) | **HIGH** | Cache Invalidation Strategies |
| [query-007-error-handling](rules/query-007-error-handling.md) | **HIGH** | Error Handling in Queries |
| [query-005-infinite-queries](rules/query-005-infinite-queries.md) | MEDIUM | Infinite Queries for Pagination |
| [query-006-prefetching](rules/query-006-prefetching.md) | MEDIUM | Prefetching for Instant Navigation |

**query-001-query-keys-factory**: Query keys determine cache identity in React Query.

**query-002-enabled-option**: The `enabled` option controls when a query should fetch.

**query-003-optimistic-updates**: Optimistic updates show the expected result immediately, before the server confirms.

**query-004-cache-invalidation**: After a mutation, the cached data is stale.

**query-007-error-handling**: API requests fail - networks are unreliable, servers have errors, tokens expire.

**query-005-infinite-queries**: When displaying lists with many items (messages, conversations, logs), loading everything at once is slow and memory-intensive.

**query-006-prefetching**: Prefetching loads data before the user navigates, making page transitions feel instant.

## State Management
<a name="state"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [state-002-store-selectors](rules/state-002-store-selectors.md) | **HIGH** | Zustand Store Selectors for Performance |
| [state-001-zustand-persist](rules/state-001-zustand-persist.md) | MEDIUM | Zustand Persist Middleware |
| [state-003-avoid-prop-drilling](rules/state-003-avoid-prop-drilling.md) | MEDIUM | Avoiding Prop Drilling |

**state-002-store-selectors**: Zustand triggers re-renders when state changes.

**state-001-zustand-persist**: Zustand's persist middleware saves state to localStorage, allowing it to survive page reloads.

**state-003-avoid-prop-drilling**: Prop drilling passes data through multiple component layers that don't use it.

## Performance
<a name="perf"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [perf-004-stable-references](rules/perf-004-stable-references.md) | **CRITICAL** | Stable References in Dependencies |
| [perf-001-memoization](rules/perf-001-memoization.md) | **HIGH** | Memoization Guidelines |
| [perf-005-bundle-size](rules/perf-005-bundle-size.md) | **HIGH** | Bundle Size Monitoring |
| [perf-002-code-splitting](rules/perf-002-code-splitting.md) | MEDIUM | Code Splitting with lazy() |
| [perf-003-virtualization](rules/perf-003-virtualization.md) | MEDIUM | Virtualization for Long Lists |

**perf-004-stable-references**: React hooks compare dependencies by reference, not value.

**perf-001-memoization**: Memoization caches results so they don't need to be recalculated on every render.

**perf-005-bundle-size**: Every kilobyte of JavaScript must be downloaded, parsed, and executed before the app becomes interactive.

**perf-002-code-splitting**: Code splitting loads JavaScript on demand instead of all at once.

**perf-003-virtualization**: Rendering thousands of DOM nodes causes jank, slow scrolling, and high memory usage.

## Accessibility
<a name="a11y"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [a11y-001-semantic-html](rules/a11y-001-semantic-html.md) | **HIGH** | Semantic HTML Elements |
| [a11y-002-keyboard-navigation](rules/a11y-002-keyboard-navigation.md) | **HIGH** | Keyboard Navigation Support |
| [a11y-003-aria-labels](rules/a11y-003-aria-labels.md) | MEDIUM | ARIA Labels and Roles |
| [a11y-004-color-contrast](rules/a11y-004-color-contrast.md) | MEDIUM | Color Contrast Requirements |

**a11y-001-semantic-html**: Semantic HTML provides meaning to content.

**a11y-002-keyboard-navigation**: Many users navigate with keyboards: those with motor impairments, power users, and screen reader users.

**a11y-003-aria-labels**: ARIA (Accessible Rich Internet Applications) supplements HTML when native semantics aren't enough.

**a11y-004-color-contrast**: Low contrast text is hard to read for users with visual impairments, color blindness, or in poor lighting conditions.

## Styling
<a name="style"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [style-001-cn-utility](rules/style-001-cn-utility.md) | MEDIUM | cn() Utility for Class Merging |
| [style-002-cva-variants](rules/style-002-cva-variants.md) | MEDIUM | CVA for Component Variants |
| [style-003-tailwind-conflicts](rules/style-003-tailwind-conflicts.md) | MEDIUM | Tailwind Class Conflicts |

**style-001-cn-utility**: Tailwind classes can conflict when combined from different sources (base styles, variants, props).

**style-002-cva-variants**: Class Variance Authority (CVA) provides a structured way to define component variants.

**style-003-tailwind-conflicts**: When multiple Tailwind classes target the same CSS property, CSS specificity rules apply - usually the last class in the stylesheet wins, NOT the l...

## TypeScript
<a name="ts"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [ts-001-no-any](rules/ts-001-no-any.md) | **HIGH** | Avoid any Type |
| [ts-003-api-response-types](rules/ts-003-api-response-types.md) | **HIGH** | API Response Type Definitions |
| [ts-002-discriminated-unions](rules/ts-002-discriminated-unions.md) | MEDIUM | Discriminated Unions for State |

**ts-001-no-any**: `any` disables TypeScript's type checking, defeating the purpose of using TypeScript.

**ts-003-api-response-types**: API responses are a major source of runtime errors - the backend sends data, but the frontend might have wrong assumptions about its shape.

**ts-002-discriminated-unions**: Discriminated unions use a common property (discriminant) to distinguish between variants.

## Quick Reference by Tag

- **React.memo**: perf-001-memoization
- **accessibility**: a11y-001-semantic-html, a11y-002-keyboard-navigation, a11y-003-aria-labels, a11y-004-color-contrast, gotcha-005-form-submit-button
- **actions**: react-004-use-action-state
- **any**: ts-001-no-any
- **api**: ts-003-api-response-types, gotcha-001-response-data-access
- **aria**: a11y-003-aria-labels
- **async**: react-003-use-hook, react-007-suspense-boundaries
- **authentication**: gotcha-003-echo-auth-token
- **axios**: gotcha-001-response-data-access
- **boundaries**: react-006-error-boundaries
- **bundle**: perf-005-bundle-size, perf-002-code-splitting
- **button**: gotcha-005-form-submit-button
- **cache**: query-001-query-keys-factory, query-004-cache-invalidation
- **classnames**: style-001-cn-utility
- **client-components**: react-002-use-client-directive
- **cn**: style-001-cn-utility
- **code-splitting**: react-007-suspense-boundaries, perf-002-code-splitting
- **color**: a11y-004-color-contrast
- **component-design**: state-003-avoid-prop-drilling
- **components**: react-001-component-structure, style-002-cva-variants
- **conditional**: query-002-enabled-option
- **conflicts**: style-003-tailwind-conflicts
- **context**: state-003-avoid-prop-drilling
- **contrast**: a11y-004-color-contrast
- **css**: style-003-tailwind-conflicts
- **cva**: style-002-cva-variants
- **data-access**: gotcha-001-response-data-access
- **debugging**: style-003-tailwind-conflicts
- **dependencies**: perf-004-stable-references, gotcha-002-infinite-rerenders
- **dependent-queries**: query-002-enabled-option
- **dialog**: gotcha-004-modal-event-bubbling
- **directive**: react-002-use-client-directive
- **echo**: gotcha-003-echo-auth-token
- **enabled**: query-002-enabled-option
- **error-handling**: react-006-error-boundaries, query-007-error-handling
- **errors**: query-007-error-handling
- **events**: gotcha-004-modal-event-bubbling
- **focus**: a11y-002-keyboard-navigation
- **forms**: react-004-use-action-state, gotcha-005-form-submit-button
- **generics**: react-008-generic-components
- **hooks**: react-003-use-hook, react-004-use-action-state, react-005-use-optimistic, perf-004-stable-references, gotcha-002-infinite-rerenders
- **html**: a11y-001-semantic-html, gotcha-005-form-submit-button
- **imports**: perf-005-bundle-size
- **infinite-scroll**: query-005-infinite-queries
- **invalidation**: query-001-query-keys-factory, query-004-cache-invalidation
- **keyboard**: a11y-002-keyboard-navigation
- **keys**: query-001-query-keys-factory
- **labels**: a11y-003-aria-labels
- **lazy**: react-007-suspense-boundaries, perf-002-code-splitting
- **lists**: perf-003-virtualization
- **loading**: react-007-suspense-boundaries
- **localstorage**: state-001-zustand-persist
- **modal**: gotcha-004-modal-event-bubbling
- **mutations**: query-003-optimistic-updates
- **optimistic**: query-003-optimistic-updates
- **optimistic-updates**: react-005-use-optimistic
- **optimization**: perf-001-memoization
- **pagination**: query-005-infinite-queries
- **pattern**: ts-002-discriminated-unions
- **patterns**: react-001-component-structure
- **pending-state**: react-004-use-action-state
- **performance**: perf-001-memoization, perf-005-bundle-size, perf-002-code-splitting, perf-003-virtualization, state-002-store-selectors, gotcha-002-infinite-rerenders, query-005-infinite-queries, query-006-prefetching
- **persist**: state-001-zustand-persist
- **prefetch**: query-006-prefetching
- **promises**: react-003-use-hook
- **props**: react-001-component-structure, state-003-avoid-prop-drilling
- **radix**: gotcha-004-modal-event-bubbling
- **re-renders**: state-002-store-selectors
- **react-19**: react-003-use-hook, react-004-use-action-state, react-002-use-client-directive, react-005-use-optimistic
- **react-query**: query-001-query-keys-factory, query-002-enabled-option, query-003-optimistic-updates, query-004-cache-invalidation, query-007-error-handling, query-005-infinite-queries, query-006-prefetching
- **real-time**: gotcha-003-echo-auth-token
- **references**: perf-004-stable-references
- **refetch**: query-004-cache-invalidation
- **resilience**: react-006-error-boundaries
- **response**: gotcha-001-response-data-access
- **responses**: ts-003-api-response-types
- **reusability**: react-008-generic-components
- **reverb**: gotcha-003-echo-auth-token
- **screen-reader**: a11y-001-semantic-html, a11y-003-aria-labels
- **selectors**: state-002-store-selectors
- **semantic**: a11y-001-semantic-html
- **sentry**: react-006-error-boundaries
- **server-components**: react-002-use-client-directive
- **state**: ts-002-discriminated-unions
- **state-management**: state-001-zustand-persist
- **styling**: style-001-cn-utility, style-002-cva-variants
- **submit**: gotcha-005-form-submit-button
- **suspense**: react-003-use-hook, react-007-suspense-boundaries, perf-002-code-splitting
- **tabindex**: a11y-002-keyboard-navigation
- **tailwind**: style-001-cn-utility, style-002-cva-variants, style-003-tailwind-conflicts
- **tanstack-virtual**: perf-003-virtualization
- **tree-shaking**: perf-005-bundle-size
- **type-safety**: react-008-generic-components, ts-001-no-any
- **types**: ts-001-no-any, ts-003-api-response-types
- **typescript**: react-001-component-structure, react-008-generic-components, ts-001-no-any, ts-003-api-response-types, ts-002-discriminated-unions
- **ui**: gotcha-004-modal-event-bubbling
- **unions**: ts-002-discriminated-unions
- **useCallback**: perf-004-stable-references, perf-001-memoization
- **useEffect**: perf-004-stable-references, gotcha-002-infinite-rerenders
- **useMemo**: perf-001-memoization, gotcha-002-infinite-rerenders
- **ux**: react-005-use-optimistic, query-003-optimistic-updates, query-007-error-handling, query-006-prefetching
- **variants**: style-002-cva-variants
- **virtualization**: perf-003-virtualization
- **visual**: a11y-004-color-contrast
- **vite**: perf-005-bundle-size
- **websocket**: gotcha-003-echo-auth-token
- **zustand**: state-002-store-selectors, state-001-zustand-persist, state-003-avoid-prop-drilling
