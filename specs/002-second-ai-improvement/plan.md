# Implementation Plan: Second AI for Improvement

**Branch**: `002-second-ai-improvement` | **Date**: 2026-01-07 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/002-second-ai-improvement/spec.md`

## Summary

เพิ่ม AI ตัวที่สองเพื่อตรวจสอบและปรับปรุง response จาก Primary AI ก่อนส่งกลับ user โดยมี 3 options: Fact Check (ตรวจข้อเท็จจริงเทียบกับ KB), Policy (ตรวจนโยบายธุรกิจ), Personality (ตรวจ tone/บุคลิกภาพ) - UI มีอยู่แล้ว ต้องเพิ่ม backend service และ persistence

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12), TypeScript 5.x (React 19)
**Primary Dependencies**: Laravel, React, TanStack Query, OpenRouter API
**Storage**: PostgreSQL (Neon) - pgvector enabled
**Testing**: PHPUnit (backend), Vitest (frontend)
**Target Platform**: Web application (Railway deployment)
**Project Type**: Web application (frontend + backend)
**Performance Goals**: Response latency increase ≤3 seconds when Second AI enabled
**Constraints**: Must gracefully fallback to Primary AI response on Second AI failure
**Scale/Scope**: Existing bot-fb platform with flows, knowledge bases, conversations

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Gate | Status | Notes |
|------|--------|-------|
| No new major dependencies | ✅ Pass | Uses existing OpenRouter service |
| Follows existing patterns | ✅ Pass | New service follows existing *Service.php pattern |
| Database changes backward compatible | ✅ Pass | New columns with defaults, nullable |
| Test coverage required | ✅ Pass | Unit + integration tests planned |
| No breaking changes | ✅ Pass | Feature is opt-in per flow |

## Project Structure

### Documentation (this feature)

```text
specs/002-second-ai-improvement/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
└── tasks.md             # Phase 2 output
```

### Source Code (repository root)

```text
backend/
├── app/
│   ├── Models/
│   │   └── Flow.php                    # Add second_ai_enabled, second_ai_options
│   ├── Services/
│   │   └── SecondAI/
│   │       ├── SecondAIService.php     # Main orchestrator
│   │       ├── FactCheckService.php    # Fact check logic
│   │       ├── PolicyCheckService.php  # Policy compliance logic
│   │       └── PersonalityCheckService.php # Tone/personality logic
│   ├── Http/
│   │   └── Requests/Flow/
│   │       ├── StoreFlowRequest.php    # Add validation rules
│   │       └── UpdateFlowRequest.php   # Add validation rules
│   └── Jobs/
│       └── SecondAI/
│           └── ProcessSecondAICheckJob.php  # Async processing (optional)
├── database/
│   └── migrations/
│       └── xxxx_add_second_ai_columns_to_flows_table.php
└── tests/
    ├── Unit/
    │   └── Services/SecondAI/
    │       ├── SecondAIServiceTest.php
    │       ├── FactCheckServiceTest.php
    │       ├── PolicyCheckServiceTest.php
    │       └── PersonalityCheckServiceTest.php
    └── Feature/
        └── SecondAI/
            └── SecondAIFlowTest.php

frontend/
├── src/
│   ├── pages/
│   │   └── FlowEditorPage.tsx          # Already has UI, add save logic
│   ├── hooks/
│   │   └── useFlows.ts                 # Update mutation types
│   └── types/
│       └── api.ts                      # Add SecondAI types
└── tests/
    └── components/
        └── FlowEditor.test.tsx
```

**Structure Decision**: Web application structure with backend/ and frontend/ directories matching existing project layout. New SecondAI services follow Laravel service layer pattern.

## Complexity Tracking

> No constitution violations - feature follows existing patterns and is opt-in.

| Aspect | Complexity | Justification |
|--------|------------|---------------|
| New services | Medium | 4 new services but follow existing patterns |
| Database change | Low | 2 new columns with defaults |
| Frontend change | Low | UI exists, add save logic only |
| Integration point | Medium | Hook into AIService response flow |
