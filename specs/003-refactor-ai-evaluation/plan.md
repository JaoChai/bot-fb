# Implementation Plan: Refactor AI Evaluation System - Phase 1

**Branch**: `003-refactor-ai-evaluation` | **Date**: 2026-01-08 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/003-refactor-ai-evaluation/spec.md`

## Summary

ปรับปรุงระบบ AI Evaluation เพื่อลด cost และ latency โดย: (1) รวม Second AI checks (Fact/Policy/Personality) เป็น 1 unified LLM call ลด API calls จาก 6-9 calls → 1 call, (2) ใช้ model tier system (premium/standard/budget) สำหรับ Evaluation metrics เพื่อลด cost 50-70%, (3) เพิ่ม Knowledge Base warning UI เมื่อเปิด Fact Check โดยไม่มี KB attached

**Technical approach**: Refactor `SecondAIService` ให้รองรับทั้ง sequential และ unified modes โดยใช้ unified prompt design ที่รวมทั้ง 3 checks และ parse structured JSON response, ปรับ `LLMJudgeService` ให้ใช้ model selection strategy ตาม metric complexity, เพิ่ม client-side validation ใน flow editor UI

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12), TypeScript 5.x (React 19)
**Primary Dependencies**: Laravel 12, React 19, TanStack Query v5, OpenRouter SDK, Neon PostgreSQL, Laravel Reverb
**Storage**: PostgreSQL 15+ (Neon) with pgvector extension
**Testing**: PHPUnit 11 (backend), Vitest (frontend)
**Target Platform**: Web application (Railway deployment)
**Project Type**: web (backend + frontend monorepo)
**Performance Goals**:
- Second AI latency ≤1.5s (down from 3-6s)
- Cost reduction ≥60% for Second AI
- Evaluation cost reduction ≥50%
**Constraints**:
- Zero breaking changes (backward compatibility required)
- OpenRouter rate limits must be respected
- Context length limits (Claude 3.5 Sonnet: 200K tokens)
**Scale/Scope**:
- 3 backend services to refactor
- 1 frontend component to enhance
- ~40 test cases per evaluation (typical)
- 5-6 evaluation metrics

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

**Note**: Project constitution is not yet defined (template placeholders exist at `.specify/memory/constitution.md`). Applying general best practices:

✅ **Minimal Change Principle** (from CLAUDE.md):
- แก้เฉพาะจุด: เปลี่ยนเฉพาะ SecondAIService, LLMJudgeService, FlowEditorPage
- ห้าม refactor: ไม่แตะ RAGService, OpenRouterService ที่ไม่เกี่ยวข้อง
- ห้ามเพิ่ม feature: focus เฉพาะ P1/P2/P3 ตาม spec
- ตรวจ git diff: commit เฉพาะไฟล์ที่เกี่ยวข้องกับ refactor นี้

✅ **Backward Compatibility**:
- Existing Second AI settings ใน database ไม่ต้องเปลี่ยน schema
- Sequential mode ยังคงทำงานได้ (fallback mechanism)
- Frontend API contracts ไม่เปลี่ยน (response format เดิม)

✅ **Testing Requirements**:
- Existing tests ต้องผ่านทั้งหมด (SC-005)
- เพิ่ม tests สำหรับ unified mode และ model tier selection
- Integration tests สำหรับ fallback scenarios

## Project Structure

### Documentation (this feature)

```text
specs/003-refactor-ai-evaluation/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (unified prompt design, model tier strategy)
├── data-model.md        # Phase 1 output (SecondAICheckResult, ModelTierConfig)
├── quickstart.md        # Phase 1 output (developer setup guide)
├── contracts/           # Phase 1 output (API contracts for unified response)
│   ├── second-ai-unified-response.md
│   └── model-tier-config.md
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)

```text
backend/
├── app/
│   ├── Services/
│   │   ├── SecondAI/
│   │   │   ├── SecondAIService.php          # REFACTOR: add unified mode
│   │   │   ├── FactCheckService.php         # UNCHANGED (used for fallback)
│   │   │   ├── PolicyCheckService.php       # UNCHANGED (used for fallback)
│   │   │   ├── PersonalityCheckService.php  # UNCHANGED (used for fallback)
│   │   │   ├── CheckResult.php              # UNCHANGED
│   │   │   └── UnifiedCheckService.php      # NEW: unified LLM call logic
│   │   └── Evaluation/
│   │       ├── EvaluationService.php        # UNCHANGED (orchestrator)
│   │       ├── LLMJudgeService.php          # REFACTOR: add model tier selection
│   │       ├── ModelTierSelector.php        # NEW: tier selection strategy
│   │       ├── TestCaseGeneratorService.php # UNCHANGED
│   │       ├── ConversationSimulatorService.php # UNCHANGED
│   │       ├── ReportGeneratorService.php   # UNCHANGED
│   │       └── PersonaService.php           # UNCHANGED
│   └── Models/
│       └── Flow.php                         # UNCHANGED (second_ai_options schema stays)
└── tests/
    ├── Feature/
    │   ├── SecondAI/
    │   │   ├── UnifiedCheckTest.php         # NEW
    │   │   └── FallbackTest.php             # NEW
    │   └── Evaluation/
    │       └── ModelTierTest.php            # NEW
    └── Unit/
        ├── UnifiedCheckServiceTest.php      # NEW
        └── ModelTierSelectorTest.php        # NEW

frontend/
├── src/
│   ├── pages/
│   │   └── FlowEditorPage.tsx               # REFACTOR: add KB warning UI
│   ├── components/
│   │   └── flow/
│   │       └── KnowledgeBaseWarning.tsx     # NEW: warning component
│   └── api/
│       └── types.ts                         # UNCHANGED (API types stay compatible)
└── tests/
    └── components/
        └── KnowledgeBaseWarning.test.tsx    # NEW
```

**Structure Decision**: Web application structure (backend + frontend) เหมาะสมสำหรับ project นี้ เนื่องจาก:
- Backend refactor: 2 services (SecondAI, Evaluation) + 2 new classes
- Frontend enhancement: 1 page + 1 new component
- Clear separation of concerns: business logic (backend) vs UI (frontend)
- Existing structure ใน repo: `backend/` และ `frontend/` directories ที่ชัดเจนอยู่แล้ว

## Complexity Tracking

> Constitution Check ไม่พบ violations ที่ต้อง justify - refactor นี้เป็นไปตาม minimal change principle และไม่เพิ่ม unnecessary complexity

**Table intentionally empty** - no complexity violations detected.

---

## Phase 0: Research (Completed)

✅ **Status**: Research completed and documented in [research.md](./research.md)

### Key Research Outcomes

**Q1: Unified Prompt Design**
- **Decision**: Single LLM call with structured JSON output combining all 3 checks
- **Model**: `anthropic/claude-3.5-sonnet` (best JSON stability)
- **Fallback**: Sequential checks if unified fails/timeouts
- **Expected Impact**: 60-70% cost reduction, 50%+ latency reduction

**Q2: Model Tier Strategy**
- **Decision**: 3-tier system (budget/standard/premium) based on metric complexity
- **Mapping**:
  - Simple metrics (`answer_relevancy`) → budget tier (Gemini Flash free)
  - Moderate metrics (`task_completion`, `role_adherence`) → standard tier (GPT-4o-mini)
  - Complex metrics (`faithfulness`, `context_precision`) → premium tier (Claude 3.5 Sonnet)
- **Expected Impact**: 50-60% cost reduction, accuracy difference ≤10%

**Q3: KB Warning UI**
- **Decision**: Yellow warning box below Fact Check toggle
- **Behavior**: Non-blocking, navigate to KB management page
- **Expected Impact**: Reduced user confusion, better UX

### Technical Risks Identified

| Risk | Mitigation |
|------|-----------|
| Unified prompt parse fail | Fallback to sequential checks |
| Budget model low accuracy | Monitor scores, upgrade tier if >10% difference |
| Context length exceeded | Claude 3.5 Sonnet supports 200K tokens (sufficient) |
| Rate limit on cheaper models | Fallback chain: budget → standard → premium |

---

## Phase 1: Design (Completed)

✅ **Status**: Design artifacts completed

### Artifacts Created

1. **[data-model.md](./data-model.md)**: Entity definitions
   - `SecondAICheckResult`: Value object for unified check results
   - `ModelTierConfig`: Value object for tier configuration
   - `UnifiedCheckService`: New service for unified LLM call
   - `ModelTierSelector`: New service for tier selection
   - No database schema changes required ✅

2. **[contracts/second-ai-unified-response.md](./contracts/second-ai-unified-response.md)**: Unified response format
   - JSON schema with `passed`, `modifications`, `final_response`
   - Field definitions for fact_check, policy, personality checks
   - Error handling strategies (invalid JSON, missing fields, timeout)
   - Backward compatibility with legacy format
   - Validation rules and test scenarios

3. **[contracts/model-tier-config.md](./contracts/model-tier-config.md)**: Model tier contract
   - Tier definitions (budget/standard/premium)
   - Metric-to-tier mapping with rationale
   - Model selection with fallback chain
   - Cost estimation formulas
   - Accuracy validation strategy

4. **[quickstart.md](./quickstart.md)**: Developer guide
   - Setup instructions
   - Development workflow (3 phases)
   - Testing scenarios and commands
   - Debugging tips
   - Performance benchmarks
   - Rollback plan

### Key Design Decisions

✅ **Backward Compatibility**:
- No database migrations needed
- Response format identical to frontend
- Sequential mode available as fallback
- Existing tests continue to pass

✅ **Minimal Changes**:
- Only 2 services refactored: `SecondAIService`, `LLMJudgeService`
- Only 2 new services: `UnifiedCheckService`, `ModelTierSelector`
- Only 1 frontend component added: `KnowledgeBaseWarning`
- No changes to: RAGService, OpenRouterService, Flow model schema

✅ **Testing Strategy**:
- Unit tests for new value objects and services
- Integration tests for unified flow and fallback scenarios
- Manual testing scenarios documented
- Performance benchmarks defined

---

## Next Steps

**Ready for**: `/speckit.tasks` command to generate implementation task breakdown

**Phase 2** (not created yet - requires `/speckit.tasks`):
- Generate `tasks.md` with detailed implementation steps
- Break down into backend tasks (UnifiedCheckService, ModelTierSelector)
- Break down into frontend tasks (KnowledgeBaseWarning component)
- Define testing tasks (unit, integration, manual)
- Create task dependency graph

**After `/speckit.tasks`**:
- Use `/speckit.implement` to execute tasks
- Or manually implement following quickstart.md guide
- Create commits using conventional commit format
- Create PR using `/commit-push-pr` skill
