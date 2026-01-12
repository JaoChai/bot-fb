# Implementation Plan: Lead Recovery

**Branch**: `007-lead-recovery` | **Date**: 2026-01-12 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/007-lead-recovery/spec.md`

## Summary

Lead Recovery เป็นระบบติดตามลูกค้าอัตโนมัติเมื่อบทสนทนาเงียบเกินกำหนด รองรับ 2 modes:
- **Static Mode**: ส่งข้อความที่ตั้งค่าไว้ล่วงหน้า
- **AI Mode**: ใช้ System Prompt จาก Flow + context บทสนทนาสร้างข้อความ personalized

ระบบทำงานผ่าน Laravel Scheduler ที่ scan หาบทสนทนาที่เงียบทุกชั่วโมง แล้วส่งข้อความติดตามผ่าน LINE/Telegram/Facebook

## Technical Context

**Language/Version**: PHP 8.2 (Backend), TypeScript 5.x (Frontend)
**Primary Dependencies**: Laravel 12, React 19, TanStack Query v5
**Storage**: PostgreSQL (Neon)
**Testing**: PHPUnit (Backend), Vitest (Frontend)
**Target Platform**: Web application (Railway deployment)
**Project Type**: Web (Backend + Frontend)
**Performance Goals**: Follow-up messages delivered within 15 minutes of timeout
**Constraints**: Cost-effective AI (GPT-4o-mini), respect Rate Limits and Response Hours
**Scale/Scope**: 100+ bots, 1000+ conversations/day

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|-------|
| Existing Patterns | ✅ Pass | ใช้ patterns เดียวกับ MessageAggregationService, AutoAssignmentService |
| Service Layer | ✅ Pass | สร้าง LeadRecoveryService ตาม Service pattern ที่มี |
| Job Pattern | ✅ Pass | ใช้ Laravel Job เหมือน ProcessLINEWebhook |
| Database Migration | ✅ Pass | Extend existing BotHITLSettings table |
| API Design | ✅ Pass | ใช้ existing BotSetting API endpoints |

## Project Structure

### Documentation (this feature)

```text
specs/007-lead-recovery/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   └── lead-recovery-api.yaml
└── tasks.md             # Phase 2 output (from /speckit.tasks)
```

### Source Code (repository root)

```text
backend/
├── app/
│   ├── Models/
│   │   ├── BotHITLSettings.php      # Extend with lead recovery fields
│   │   ├── Conversation.php          # Extend with recovery tracking
│   │   └── LeadRecoveryLog.php       # NEW: Log follow-up attempts
│   ├── Services/
│   │   └── LeadRecoveryService.php   # NEW: Core recovery logic
│   ├── Jobs/
│   │   └── ProcessLeadRecovery.php   # NEW: Scheduled job
│   └── Http/
│       └── Controllers/Api/
│           └── LeadRecoveryController.php  # NEW: Analytics endpoints
├── database/migrations/
│   ├── xxx_add_lead_recovery_to_bot_hitl_settings.php
│   ├── xxx_add_recovery_fields_to_conversations.php
│   └── xxx_create_lead_recovery_logs_table.php
├── routes/
│   └── console.php                   # Add scheduled job
└── tests/
    ├── Unit/Services/
    │   └── LeadRecoveryServiceTest.php
    └── Feature/
        └── LeadRecoveryTest.php

frontend/
└── src/
    └── components/bot-settings/
        └── LeadRecoverySection.tsx    # NEW: Settings UI component
```

**Structure Decision**: Web application structure. Backend handles scheduling/processing, Frontend provides settings UI.

## Complexity Tracking

> No violations. Using existing patterns.
