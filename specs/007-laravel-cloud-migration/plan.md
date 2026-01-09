# Implementation Plan: Laravel Cloud Migration with Inertia.js

**Branch**: `007-laravel-cloud-migration` | **Date**: 2026-01-09 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/007-laravel-cloud-migration/spec.md`

## Summary

Migrate BotFacebook from a two-service architecture (React SPA + Laravel API on Railway) to a unified monolith (Laravel + Inertia.js + React) on Laravel Cloud. This involves:

1. Installing and configuring Inertia.js with React adapter
2. Converting 16 React pages to Inertia pages
3. Migrating 199 TanStack Query operations to server-side props
4. Preserving WebSocket (Echo/Reverb) and SSE streaming functionality
5. Migrating database from Neon to Laravel Cloud Serverless Postgres (with pgvector)
6. Deploying the unified application to Laravel Cloud

## Technical Context

**Language/Version**: PHP 8.4, TypeScript 5.x
**Primary Dependencies**: Laravel 12, Inertia.js 2.x, React 19, Vite 5.x, TailwindCSS
**Storage**: Laravel Cloud Serverless Postgres with pgvector extension
**Testing**: Pest (PHP), Vitest (TypeScript)
**Target Platform**: Laravel Cloud (AWS EC2-based)
**Project Type**: Web application (monolith after migration)
**Performance Goals**: API responses <500ms, real-time messages <2s latency, SSE streaming progressive
**Constraints**: Zero downtime migration, data integrity preserved, all channel integrations maintained
**Scale/Scope**: ~16 pages, ~110 components, ~20 hooks, ~14,500 LOC frontend

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Gate | Status | Notes |
|------|--------|-------|
| **Simplicity** | ✅ PASS | Migration reduces complexity (2 services → 1 monolith) |
| **Test Coverage** | ✅ PASS | Existing tests + migration validation tests |
| **Documentation** | ✅ PASS | Spec complete with 20 FRs and 13 SCs |
| **Data Integrity** | ✅ PASS | Full backup + staged migration planned |

## Project Structure

### Documentation (this feature)

```text
specs/007-laravel-cloud-migration/
├── spec.md              # Feature specification
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output (Inertia page props)
├── checklists/          # Quality validation
│   └── requirements.md
└── tasks.md             # Phase 2 output (created by /speckit.tasks)
```

### Source Code (after migration)

```text
backend/                           # Laravel 12 Monolith
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/              # Session-based auth
│   │   │   ├── Bot/               # Bot management
│   │   │   ├── Chat/              # Conversation handling
│   │   │   ├── Flow/              # Flow editor
│   │   │   └── KnowledgeBase/     # RAG documents
│   │   └── Middleware/
│   │       └── HandleInertiaRequests.php
│   ├── Models/                    # Eloquent models (unchanged)
│   └── Services/                  # Business logic (unchanged)
├── resources/
│   ├── js/                        # React + Inertia (migrated from frontend/)
│   │   ├── Components/            # Reusable UI components
│   │   │   ├── ui/                # Radix primitives
│   │   │   ├── chat/              # Chat components
│   │   │   ├── bot-settings/      # Bot config sections
│   │   │   └── flow-editor/       # Flow editor sections
│   │   ├── Layouts/               # Page layouts
│   │   │   ├── AuthenticatedLayout.tsx
│   │   │   └── GuestLayout.tsx
│   │   ├── Pages/                 # Inertia pages
│   │   │   ├── Auth/
│   │   │   │   ├── Login.tsx
│   │   │   │   └── Register.tsx
│   │   │   ├── Dashboard.tsx
│   │   │   ├── Bots/
│   │   │   │   ├── Index.tsx
│   │   │   │   ├── Settings.tsx
│   │   │   │   └── Edit.tsx
│   │   │   ├── Chat/
│   │   │   │   └── Index.tsx
│   │   │   ├── Flows/
│   │   │   │   └── Editor.tsx
│   │   │   ├── KnowledgeBase/
│   │   │   │   └── Index.tsx
│   │   │   └── Settings/
│   │   │       └── Index.tsx
│   │   ├── Hooks/                 # React hooks (Echo, streaming)
│   │   │   ├── useEcho.ts
│   │   │   └── useStreamingChat.ts
│   │   ├── Lib/                   # Utilities
│   │   │   ├── echo.ts
│   │   │   └── utils.ts
│   │   └── app.tsx                # Inertia entry point
│   ├── views/
│   │   └── app.blade.php          # Root Blade template
│   └── css/
│       └── app.css                # Tailwind CSS
├── routes/
│   ├── web.php                    # Inertia routes
│   └── api.php                    # API routes (webhooks, SSE)
├── tests/
│   ├── Feature/
│   │   ├── Inertia/               # Page rendering tests
│   │   └── Migration/             # Data migration tests
│   └── Unit/
├── vite.config.ts                 # Vite + React + Inertia
└── package.json
```

**Structure Decision**: Single monolith in `backend/` directory. The `frontend/` directory will be deprecated after migration is complete. React code moves to `resources/js/`.

## Complexity Tracking

> No complexity violations. Migration simplifies architecture.

| Aspect | Before | After | Justification |
|--------|--------|-------|---------------|
| Services | 2 | 1 | Reduced operational complexity |
| Auth | Token-based | Session-based | Native Laravel, simpler |
| State | TanStack Query + Zustand | Inertia props | Less client-side state |
| Deployment | 2 Railway services | 1 Laravel Cloud app | Single deploy |

## Migration Phases

### Phase 0: Setup & Research

1. Install Inertia.js in Laravel
2. Configure Vite for React + Inertia
3. Research Inertia.js best practices with Echo/Reverb
4. Research SSE streaming with Inertia
5. Validate pgvector compatibility on Laravel Cloud

### Phase 1: Foundation

1. Create base layouts (Authenticated, Guest)
2. Implement session-based authentication
3. Migrate simple pages (Dashboard, Settings)
4. Set up shared Inertia data (user, flash messages)

### Phase 2: Core Features

1. Migrate Bot management pages
2. Migrate Knowledge Base pages
3. Set up Echo WebSocket integration
4. Implement real-time updates

### Phase 3: Complex Features

1. Migrate Chat page with infinite scroll
2. Implement real-time message updates
3. Migrate Flow Editor with SSE streaming
4. Test all channel adapters (FB/LINE/Telegram)

### Phase 4: Database & Deployment

1. Set up Laravel Cloud project
2. Create Serverless Postgres with pgvector
3. Migrate data from Neon
4. Configure managed Reverb
5. Deploy and validate

### Phase 5: Cutover

1. DNS migration
2. Final validation
3. Deprecate Railway services
4. Monitor production
