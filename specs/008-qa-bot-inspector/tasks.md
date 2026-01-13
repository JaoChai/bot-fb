# Tasks: QA Bot Inspector

**Input**: Design documents from `/specs/008-qa-bot-inspector/`
**Prerequisites**: plan.md ✅, spec.md ✅, research.md ✅, data-model.md ✅, contracts/ ✅

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

---

## Phase 1: Setup (Shared Infrastructure) ✅

**Purpose**: Project initialization and basic structure

- [x] T001 Create QA Inspector service directory structure at `backend/app/Services/QAInspector/`
- [x] T002 [P] Create QA Inspector types at `frontend/src/types/qa-inspector.ts`
- [x] T003 [P] Create QA Inspector components directory at `frontend/src/components/qa-inspector/`

---

## Phase 2: Foundational (Blocking Prerequisites) ✅

**Purpose**: Core infrastructure that MUST be complete before ANY user story can be implemented

**✅ COMPLETE**: Foundation ready - user story implementation can now begin

### Database Migrations

- [x] T004 Create migration `backend/database/migrations/2026_01_14_000001_add_qa_inspector_fields_to_bots_table.php`
  - Add 11 fields: qa_inspector_enabled, qa_realtime_model, qa_realtime_fallback_model, qa_analysis_model, qa_analysis_fallback_model, qa_report_model, qa_report_fallback_model, qa_score_threshold, qa_sampling_rate, qa_report_schedule, qa_notifications
- [x] T005 [P] Create migration `backend/database/migrations/2026_01_14_000002_create_qa_evaluation_logs_table.php`
  - Fields per data-model.md: id, bot_id, conversation_id, message_id, flow_id, 5 metrics, overall_score, is_flagged, issue_type, issue_details, user_question, bot_response, system_prompt_used, kb_chunks_used, model_metadata, evaluated_at
  - Indexes: idx_qa_eval_bot_id, idx_qa_eval_conversation_id, idx_qa_eval_flagged, idx_qa_eval_created
- [x] T006 [P] Create migration `backend/database/migrations/2026_01_14_000003_create_qa_weekly_reports_table.php`
  - Fields per data-model.md: id, bot_id, week_start, week_end, status, performance_summary, top_issues, prompt_suggestions, total_conversations, total_flagged, average_score, previous_average_score, generation_cost, generated_at, notification_sent
  - Unique constraint: (bot_id, week_start)

### Core Models

- [x] T007 Update `backend/app/Models/Bot.php` - Add QA Inspector fields and relationships
  - Add 11 qa_* fields to $fillable and $casts
  - Add hasMany relationship to QAEvaluationLog
  - Add hasMany relationship to QAWeeklyReport
- [x] T008 [P] Create `backend/app/Models/QAEvaluationLog.php` - Evaluation log model
  - Define relationships: belongsTo Bot, Conversation, Message, Flow
  - Add scopes: flagged(), byBot(), byDateRange()
  - Define $casts for JSON fields
- [x] T009 [P] Create `backend/app/Models/QAWeeklyReport.php` - Weekly report model
  - Define relationship: belongsTo Bot
  - Add scopes: completed(), byBot(), byWeek()
  - Define $casts for JSON fields

### Base Services

- [x] T010 Create `backend/app/Services/QAInspector/QAInspectorService.php` - Main orchestration service
  - Inject LLMJudgeService and OpenRouterService
  - Method: isEnabled(Bot $bot): bool
  - Method: shouldEvaluate(Bot $bot): bool (sampling logic)
  - Method: getModelsForLayer(Bot $bot, string $layer): array

**Checkpoint**: Foundation ready - user story implementation can now begin

---

## Phase 3: User Story 1 - Enable QA Inspector for Bot (Priority: P1) 🎯 MVP ✅

**Goal**: Allow users to enable/disable QA Inspector per Bot and trigger real-time evaluations

**Independent Test**: Toggle QA Inspector in Bot settings, send test message, verify evaluation log is created

### Implementation for User Story 1

- [x] T011 [US1] Create `backend/app/Services/QAInspector/RealtimeEvaluator.php` - Layer 1 evaluation
  - Use LLMJudgeService for 5-metric evaluation
  - Calculate weighted overall_score (relevancy 25%, faithfulness 25%, role 20%, context 15%, task 15%)
  - Flag if score < threshold
  - Store evaluation log
- [x] T012 [US1] Create `backend/app/Jobs/EvaluateConversationJob.php` - Async evaluation job
  - Accept Message and Bot
  - Check if QA Inspector enabled and sampling allows
  - Call RealtimeEvaluator
  - Handle model fallback on failure
  - Queue: qa-evaluation
- [x] T013 [US1] Create Observer/Hook to dispatch evaluation job after bot response
  - Option A: MessageObserver - dispatch job on assistant message created
  - Option B: Event listener in MessageService
  - Delay: 2 seconds for message to be fully saved
- [x] T014 [US1] Create `backend/app/Http/Controllers/QAInspectorController.php` - Settings endpoints
  - GET /bots/{bot}/qa-inspector/settings
  - PUT /bots/{bot}/qa-inspector/settings
  - Use FormRequest validation per contracts/api.md
- [x] T015 [P] [US1] Create `backend/app/Http/Requests/UpdateQAInspectorSettingsRequest.php`
  - Validate qa_inspector_enabled: boolean
  - Validate qa_score_threshold: between:0,1
  - Validate qa_sampling_rate: between:1,100
- [x] T016 [P] [US1] Create `backend/app/Http/Resources/QAInspectorSettingsResource.php`
  - Format response per contracts/api.md
- [x] T017 [US1] Register routes in `backend/routes/api.php`
  - Route::prefix('bots/{bot}/qa-inspector') with auth:sanctum middleware
- [x] T018 [US1] Create `frontend/src/hooks/useQAInspector.ts` - React Query hooks
  - useQAInspectorSettings(botId)
  - useUpdateQAInspectorSettings()
- [x] T019 [US1] Create `frontend/src/components/qa-inspector/QAInspectorToggle.tsx`
  - Simple toggle component to enable/disable
  - Shows status indicator (enabled/disabled)
- [x] T020 [US1] Integrate QAInspectorToggle into Bot Settings page
  - Add "QA Inspector" section in existing bot settings

**Checkpoint**: User Story 1 complete - Users can enable QA Inspector and system creates evaluation logs

---

## Phase 4: User Story 2 - Configure QA Inspector Models (Priority: P1) ✅

**Goal**: Allow users to configure AI models for each layer with primary + fallback support

**Independent Test**: Configure custom models in settings, trigger evaluation, verify correct model is used in logs

### Implementation for User Story 2

- [x] T021 [US2] Update RealtimeEvaluator to use configured models
  - Read qa_realtime_model and qa_realtime_fallback_model from Bot
  - Implement fallback chain: primary → fallback → skip with error log
- [x] T022 [US2] Create model validation helper in QAInspectorService
  - Validate provider/model-name format
  - Check model exists in OpenRouter (optional, can be async validation)
- [x] T023 [US2] Update QAInspectorSettingsRequest to validate model fields
  - qa_realtime_model, qa_realtime_fallback_model
  - qa_analysis_model, qa_analysis_fallback_model
  - qa_report_model, qa_report_fallback_model
  - Format validation: provider/model-name
- [x] T024 [US2] Create `frontend/src/components/qa-inspector/QAInspectorSettings.tsx`
  - Model configuration section with 6 text inputs (3 layers x 2 models)
  - Use existing ModelSelector component pattern (text input with provider/model-name)
  - Show default values as placeholder
- [x] T025 [P] [US2] Create `frontend/src/components/qa-inspector/ModelLayerConfig.tsx`
  - Reusable component for Primary + Fallback model inputs
  - Props: layerName, primaryModel, fallbackModel, onChange
- [x] T026 [US2] Add model cost estimation display in settings
  - Show estimated monthly cost based on configured models
  - Reference pricing from llm-models.php config

**Checkpoint**: User Story 2 complete - Users can configure custom AI models per layer

---

## Phase 5: User Story 3 - View Real-time Evaluation Results (Priority: P2) ✅

**Goal**: Display real-time evaluation dashboard with logs, stats, and score trends

**Independent Test**: Enable QA Inspector, generate test conversations, view dashboard with populated data

### Implementation for User Story 3

- [x] T027 [US3] Add evaluation log endpoints in QAInspectorController
  - GET /bots/{bot}/qa-inspector/logs (paginated, filterable)
  - GET /bots/{bot}/qa-inspector/logs/{log} (detail view)
  - GET /bots/{bot}/qa-inspector/stats (dashboard stats)
- [x] T028 [P] [US3] Create `backend/app/Http/Resources/QAEvaluationLogResource.php`
  - Format per contracts/api.md
  - Include scores, issue_type, user_question, bot_response (truncated)
- [x] T029 [P] [US3] Create `backend/app/Http/Resources/QAEvaluationLogDetailResource.php`
  - Full detail including system_prompt_used, kb_chunks_used, model_metadata
- [x] T030 [US3] Implement stats calculation in QAInspectorService
  - Summary: total_evaluated, total_flagged, error_rate, average_score
  - Score trend: daily averages for period (1d, 7d, 30d)
  - Issue breakdown: count by issue_type
  - Cost tracking: sum of model_metadata.cost_estimate
- [x] T031 [US3] Update useQAInspector.ts with new hooks
  - useQAEvaluationLogs(botId, filters)
  - useQAEvaluationLog(botId, logId)
  - useQAStats(botId, period)
- [x] T032 [US3] Create `frontend/src/components/qa-inspector/QADashboard.tsx`
  - Summary cards: Total Evaluated, Flagged Issues, Error Rate, Average Score
  - Score trend chart (line chart, 7 days default)
  - Issue breakdown chart (pie/donut chart)
- [x] T033 [P] [US3] Create `frontend/src/components/qa-inspector/QAEvaluationLogList.tsx`
  - Table with columns: Time, Score, Status (Pass/Flagged), Issue Type, Question preview
  - Filters: is_flagged, issue_type, date range, score range
  - Pagination
- [x] T034 [P] [US3] Create `frontend/src/components/qa-inspector/QAEvaluationLogDetail.tsx`
  - Full conversation view: user_question, bot_response
  - Scores breakdown with visual indicators
  - Issue details with root cause (if flagged)
  - KB chunks used, model metadata
- [x] T035 [US3] Create `frontend/src/pages/QAInspectorPage.tsx`
  - Main QA Inspector page with tabs: Dashboard, Evaluation Logs, Weekly Reports
  - Route: /bots/{botId}/qa-inspector

**Checkpoint**: User Story 3 complete - Users can view real-time evaluation results

---

## Phase 6: User Story 4 - View Weekly Report (Priority: P2) ✅

**Goal**: Generate and display Weekly Reports with performance summary, top issues, and prompt suggestions

**Independent Test**: Wait for scheduled report generation (or trigger manually), view report with all sections

### Implementation for User Story 4

- [x] T036 [US4] Create `backend/app/Services/QAInspector/DeepAnalyzer.php` - Layer 2 analysis
  - Analyze flagged issues to identify root cause
  - Identify prompt section that caused issue
  - Categorize issue type: price_error, hallucination, wrong_tone, missing_info, off_topic, unanswered
  - Use configured qa_analysis_model
- [x] T037 [US4] Create `backend/app/Services/QAInspector/WeeklyReportGenerator.php` - Layer 3 reports
  - Aggregate metrics for the week
  - Identify top issues with patterns
  - Generate prompt suggestions referencing Flow.system_prompt
  - Calculate generation cost
  - Use configured qa_report_model
- [x] T038 [US4] Create `backend/app/Jobs/GenerateWeeklyReportJob.php`
  - Generate report for specified bot and week
  - Update status: generating → completed/failed
  - Handle model fallback
  - Queue: qa-report
- [x] T039 [US4] Create `backend/app/Console/Commands/GenerateQAWeeklyReports.php`
  - Generate reports for all bots with QA Inspector enabled
  - Scheduled: weekly per bot's qa_report_schedule
- [x] T040 [US4] Register scheduled command in `backend/routes/console.php`
  - Default: Monday 00:00
  - Support custom schedules per bot
- [x] T041 [US4] Add weekly report endpoints in QAInspectorController
  - GET /bots/{bot}/qa-inspector/reports (paginated list)
  - GET /bots/{bot}/qa-inspector/reports/{report} (detail)
  - POST /bots/{bot}/qa-inspector/reports/generate (manual trigger)
- [x] T042 [P] [US4] Create `backend/app/Http/Resources/QAWeeklyReportResource.php`
  - List format: id, week_start, week_end, status, totals, average_score
- [x] T043 [P] [US4] Create `backend/app/Http/Resources/QAWeeklyReportDetailResource.php`
  - Full format per contracts/api.md with performance_summary, top_issues, prompt_suggestions
- [x] T044 [US4] Update useQAInspector.ts with report hooks
  - useQAWeeklyReports(botId)
  - useQAWeeklyReport(botId, reportId)
  - useGenerateReport()
- [x] T045 [US4] Create `frontend/src/components/qa-inspector/QAWeeklyReportList.tsx`
  - Table: Week, Status, Conversations, Flagged, Average Score, Trend
  - Click to view detail
- [x] T046 [US4] Create `frontend/src/components/qa-inspector/QAWeeklyReportView.tsx`
  - Performance Summary section with metrics and charts
  - Top Issues section with patterns and root causes
  - Prompt Suggestions section with before/after preview
- [x] T047 [US4] Create `frontend/src/pages/QAWeeklyReportPage.tsx`
  - Standalone page for viewing single report
  - Route: /bots/{botId}/qa-inspector/reports/{reportId}

**Checkpoint**: User Story 4 complete - Weekly Reports are generated and viewable

---

## Phase 7: User Story 5 - Apply Prompt Improvements (Priority: P3) ✅

**Goal**: Allow users to apply AI-generated prompt suggestions directly to Flow.system_prompt

**Independent Test**: View suggestion in report, click Apply, verify Flow.system_prompt is updated

### Implementation for User Story 5

- [x] T048 [US5] Create `backend/app/Services/QAInspector/PromptSuggestionApplier.php`
  - Validate suggestion can be applied (flow exists, section matches)
  - Apply text replacement to Flow.system_prompt
  - Handle conflicts: prompt modified since report generation
  - Track applied_at timestamp
- [x] T049 [US5] Add apply suggestion endpoint in QAInspectorController
  - POST /bots/{bot}/qa-inspector/reports/{report}/suggestions/{index}/apply
  - Request: flow_id, confirm
  - Response: success message or 409 conflict
- [x] T050 [P] [US5] Create `backend/app/Http/Requests/ApplyPromptSuggestionRequest.php`
  - Validate flow_id exists and belongs to bot
  - Validate confirm: boolean
- [x] T051 [US5] Update useQAInspector.ts with apply hooks
  - useApplyPromptSuggestion()
- [x] T052 [US5] Create `frontend/src/components/qa-inspector/PromptSuggestionCard.tsx`
  - Show before/after diff view
  - Priority badge, expected impact
  - "Apply to Flow" button with confirmation modal
  - Applied status indicator
- [x] T053 [US5] Handle apply conflict in frontend
  - Show conflict modal when 409 returned
  - Display current vs expected section
  - Option to force apply or cancel

**Checkpoint**: User Story 5 complete - Users can apply prompt suggestions

---

## Phase 8: User Story 6 - Configure Additional Settings (Priority: P3) ✅

**Goal**: Allow users to configure threshold, sampling rate, schedule, and notifications

**Independent Test**: Change each setting, verify system behavior reflects new settings

### Implementation for User Story 6

- [x] T054 [US6] Implement sampling rate in EvaluateConversationJob
  - Random sampling based on qa_sampling_rate percentage
  - Log skipped evaluations (optional debug mode)
- [x] T055 [US6] Implement per-bot report schedule
  - Parse qa_report_schedule format (e.g., "monday_00:00", "friday_18:00")
  - Scheduler checks each bot's schedule
- [x] T056 [US6] Create email notification service for weekly reports
  - Send email when report is generated
  - Include summary and link to full report
  - Respect qa_notifications.email setting
- [x] T057 [P] [US6] Create in-app alert for flagged issues (optional)
  - Real-time notification when high-severity issue detected
  - Respect qa_notifications.alert setting
- [x] T058 [US6] Update QAInspectorSettings.tsx with additional settings
  - Score threshold slider (0.50 - 0.95)
  - Sampling rate slider (1% - 100%)
  - Report schedule dropdown (monday_00:00, friday_18:00, etc.)
  - Notification toggles (email, alert, slack)
- [x] T059 [US6] Add settings validation feedback
  - Show cost impact when changing sampling rate
  - Warn when threshold is too high/low

**Checkpoint**: User Story 6 complete - All settings are configurable

---

## Phase 9: Polish & Cross-Cutting Concerns ✅

**Purpose**: Improvements that affect multiple user stories

- [x] T060 [P] Create `backend/app/Console/Commands/CleanupOldQALogs.php`
  - Delete evaluation logs older than 90 days
  - Schedule: daily
- [x] T061 [P] Add rate limiting to QA Inspector endpoints
  - GET: 60 req/min
  - PUT/POST: 30 req/min
  - Report generation: 5 req/hour per bot
- [x] T062 Register cleanup command in scheduler `backend/routes/console.php`
- [x] T063 Add error handling and logging throughout QA Inspector services
  - Log model failures with context
  - Track evaluation costs per bot
- [x] T064 Run quickstart.md validation - Test complete flow end-to-end
- [x] T065 Update Bot Policy for QA Inspector authorization
  - Only bot owner can access QA Inspector data

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies - can start immediately
- **Foundational (Phase 2)**: Depends on Setup completion - BLOCKS all user stories
- **User Stories (Phase 3-8)**: All depend on Foundational phase completion
  - US1 (P1) and US2 (P1) can run in parallel
  - US3 (P2) depends on US1 (evaluation logs must exist)
  - US4 (P2) depends on US1 (evaluation data) and partially on US3 (DeepAnalyzer)
  - US5 (P3) depends on US4 (weekly reports with suggestions)
  - US6 (P3) can run in parallel with US3-US5 (settings are independent)
- **Polish (Phase 9)**: Depends on core stories (US1-US4) being complete

### Parallel Opportunities

```text
Phase 1:  [T001] [T002] [T003]  - All parallel
Phase 2:  [T004] → [T005, T006] (parallel) → [T007, T008, T009] (parallel) → [T010]
Phase 3+: US1 + US2 can start together after Phase 2
          US3 can start after T012 creates evaluation logs
          US4 can start after basic US3 evaluation flow works
          US5 waits for US4 (needs reports with suggestions)
          US6 can run in parallel with US3-US5
```

---

## Implementation Strategy

### MVP First (US1 + US2)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: User Story 1 (Enable QA Inspector)
4. Complete Phase 4: User Story 2 (Configure Models)
5. **STOP and VALIDATE**: Test evaluation is working with configured models
6. Deploy MVP - Core QA evaluation is functional

### Full Feature Set

1. Continue to Phase 5: User Story 3 (View Results)
2. Continue to Phase 6: User Story 4 (Weekly Reports)
3. Continue to Phase 7: User Story 5 (Apply Suggestions)
4. Continue to Phase 8: User Story 6 (Settings)
5. Complete Phase 9: Polish
6. Final deployment with all features

---

## Notes

- [P] tasks = different files, no dependencies, safe to parallelize
- [USx] label maps task to specific user story for traceability
- Queue names: `qa-evaluation` (Layer 1), `qa-report` (Layer 3)
- Default models: Gemini 2.5 Flash (L1), Claude Sonnet 4 (L2), Claude Opus 4.5 (L3)
- Cost budget: ~$62/month for 200 conversations/day at 100% sampling
