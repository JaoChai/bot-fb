# Implementation Plan: QA Bot Inspector

**Branch**: `008-qa-bot-inspector` | **Date**: 2026-01-13 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/008-qa-bot-inspector/spec.md`

## Summary

QA Bot Inspector เป็นระบบ AI Agent สำหรับตรวจสอบคุณภาพการตอบของ Bot แบบอัตโนมัติ โดยมี 3 layers:
- **Layer 1**: Real-time evaluation ทุก conversation ด้วย 5 metrics
- **Layer 2**: Deep analysis สำหรับ flagged issues
- **Layer 3**: Weekly report พร้อม prompt improvement suggestions

Technical Approach: ใช้ existing LLMJudgeService และ ModelTierSelector เป็น foundation โดยสร้าง real-time evaluation hook ใหม่และ QAInspectorService สำหรับ orchestration

## Technical Context

**Language/Version**: PHP 8.4 (Backend), TypeScript 5.x (Frontend)
**Primary Dependencies**: Laravel 12, React 19, TanStack Query v5, Zustand, OpenRouter API
**Storage**: PostgreSQL (Neon) with existing pgvector extension
**Testing**: PHPUnit (Backend), Vitest (Frontend)
**Target Platform**: Railway (Production), Docker (Local)
**Project Type**: Web application (Frontend + Backend)
**Performance Goals**: Evaluation within 30 seconds, 500+ conversations/day
**Constraints**: ~$62/month evaluation cost budget, 90 days data retention
**Scale/Scope**: 200 conversations/day baseline, scalable to 500+

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Gate | Status | Notes |
|------|--------|-------|
| Reuse existing patterns | ✅ PASS | Reuses LLMJudgeService, ModelTierSelector, OpenRouterService |
| No unnecessary complexity | ✅ PASS | Extends Bot model instead of separate settings table |
| Testable implementation | ✅ PASS | Service layer with dependency injection |
| Security/Privacy | ✅ PASS | Evaluation data scoped to user's bots only |

## Project Structure

### Documentation (this feature)

```text
specs/008-qa-bot-inspector/
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
│   │   ├── Bot.php                    # Add QA Inspector fields
│   │   ├── QAEvaluationLog.php        # NEW: Real-time evaluation logs
│   │   └── QAWeeklyReport.php         # NEW: Weekly report storage
│   ├── Services/
│   │   └── QAInspector/
│   │       ├── QAInspectorService.php     # NEW: Main orchestration
│   │       ├── RealtimeEvaluator.php      # NEW: Layer 1 evaluation
│   │       ├── DeepAnalyzer.php           # NEW: Layer 2 analysis
│   │       └── WeeklyReportGenerator.php  # NEW: Layer 3 reports
│   ├── Jobs/
│   │   ├── EvaluateConversationJob.php    # NEW: Async evaluation
│   │   └── GenerateWeeklyReportJob.php    # NEW: Scheduled report
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── QAInspectorController.php  # NEW: API endpoints
│   │   └── Resources/
│   │       ├── QAEvaluationLogResource.php
│   │       └── QAWeeklyReportResource.php
│   └── Console/
│       └── Commands/
│           └── GenerateQAWeeklyReports.php # NEW: Artisan command
├── database/
│   └── migrations/
│       ├── 2026_01_14_000001_add_qa_inspector_fields_to_bots_table.php
│       ├── 2026_01_14_000002_create_qa_evaluation_logs_table.php
│       └── 2026_01_14_000003_create_qa_weekly_reports_table.php
├── routes/
│   └── api.php                        # Add QA Inspector routes
└── tests/
    ├── Feature/
    │   └── QAInspector/
    │       ├── QAInspectorSettingsTest.php
    │       ├── RealtimeEvaluationTest.php
    │       └── WeeklyReportTest.php
    └── Unit/
        └── QAInspector/
            ├── RealtimeEvaluatorTest.php
            └── DeepAnalyzerTest.php

frontend/
├── src/
│   ├── components/
│   │   └── qa-inspector/
│   │       ├── QAInspectorSettings.tsx     # NEW: Settings UI
│   │       ├── QADashboard.tsx             # NEW: Real-time dashboard
│   │       ├── QAEvaluationLogList.tsx     # NEW: Log list component
│   │       ├── QAWeeklyReportView.tsx      # NEW: Report viewer
│   │       └── PromptSuggestionCard.tsx    # NEW: Suggestion display
│   ├── pages/
│   │   ├── QAInspectorPage.tsx             # NEW: Main QA page
│   │   └── QAWeeklyReportPage.tsx          # NEW: Report detail page
│   ├── hooks/
│   │   └── useQAInspector.ts               # NEW: React Query hooks
│   └── types/
│       └── qa-inspector.ts                 # NEW: TypeScript types
└── tests/
    └── qa-inspector/
        └── QAInspectorSettings.test.tsx
```

**Structure Decision**: Web application structure extending existing frontend/backend pattern. QA Inspector services are grouped in dedicated namespace for maintainability.

## Complexity Tracking

> No violations detected - design follows existing patterns

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| Model storage | Extend Bot model | Consistent with existing model fields (primary_chat_model, etc.) |
| Evaluation service | New service using LLMJudgeService | Reuses existing 5-metric evaluation logic |
| Real-time hook | Job dispatch on message saved | Non-blocking, scalable via Laravel Queue |

## Key Integration Points

### Existing Services to Reuse

| Service | Location | Usage |
|---------|----------|-------|
| LLMJudgeService | `app/Services/Evaluation/LLMJudgeService.php` | 5-metric evaluation logic |
| ModelTierSelector | `app/Services/Evaluation/ModelTierSelector.php` | Smart model selection |
| OpenRouterService | `app/Services/OpenRouterService.php` | AI API calls with fallback |
| OpenRouter API | External | Model inference |

### New vs Existing Models

| Entity | Action | Notes |
|--------|--------|-------|
| Bot | EXTEND | Add 10+ QA Inspector fields |
| QAEvaluationLog | NEW | Real-time evaluation results |
| QAWeeklyReport | NEW | Weekly report storage |
| PromptSuggestion | EMBEDDED | JSON in QAWeeklyReport |

### Hook Point for Real-time Evaluation

```php
// In MessageService or Observer
// After bot response is saved, dispatch evaluation job
QAEvaluateConversationJob::dispatch($message, $bot)
    ->onQueue('qa-evaluation')
    ->delay(now()->addSeconds(2)); // Small delay for message to be fully saved
```

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Evaluation delays response | Async job processing, non-blocking |
| Cost overrun | ModelTierSelector + sampling rate option |
| AI model failures | Fallback chain (primary → fallback → skip) |
| Rate limits | Queue with exponential backoff retry |
