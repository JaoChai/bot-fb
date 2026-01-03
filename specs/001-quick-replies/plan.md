# Implementation Plan: Quick Replies (Canned Responses)

**Branch**: `001-quick-replies` | **Date**: 2026-01-03 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/001-quick-replies/spec.md`

## Summary

เพิ่มระบบ Quick Replies (คำตอบที่ใช้บ่อย) สำหรับหน้าแชท ให้ Agent สามารถเลือกคำตอบสำเร็จรูปหรือพิมพ์ `/shortcut` เพื่อส่งข้อความได้อย่างรวดเร็ว โดยเก็บข้อมูลระดับ Team และให้เฉพาะ Owner จัดการได้

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 12) + TypeScript 5.x (React 19)
**Primary Dependencies**: Laravel, React, React Query, Tailwind CSS, shadcn/ui
**Storage**: PostgreSQL (Neon) - เพิ่ม `quick_replies` table
**Testing**: PHPUnit (backend), Vitest (frontend)
**Target Platform**: Web application (responsive)
**Project Type**: Web (backend + frontend)
**Performance Goals**: Autocomplete < 300ms, API response < 200ms
**Constraints**: Quick Reply content ต้องไม่เกิน 5000 bytes (LINE limit)
**Scale/Scope**: รองรับ ~100 Quick Replies per Team

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Gate | Status | Notes |
|------|--------|-------|
| Existing patterns followed | PASS | ใช้ Laravel Resource pattern, React Query hooks |
| No unnecessary complexity | PASS | CRUD + autocomplete เท่านั้น |
| Security checked | PASS | Owner-only management via Policy |
| Tests required | PASS | Unit + Feature tests planned |

## Project Structure

### Documentation (this feature)

```text
specs/001-quick-replies/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   └── quick-replies-api.yaml
└── tasks.md             # Phase 2 output (created by /speckit.tasks)
```

### Source Code (repository root)

```text
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/QuickReplyController.php
│   │   ├── Requests/QuickReplyRequest.php
│   │   └── Resources/QuickReplyResource.php
│   ├── Models/QuickReply.php
│   └── Policies/QuickReplyPolicy.php
├── database/
│   └── migrations/xxxx_create_quick_replies_table.php
└── tests/
    └── Feature/QuickReplyTest.php

frontend/
├── src/
│   ├── components/
│   │   └── chat/
│   │       ├── QuickReplyButton.tsx
│   │       ├── QuickReplyList.tsx
│   │       └── QuickReplyAutocomplete.tsx
│   ├── pages/
│   │   └── settings/
│   │       └── QuickRepliesPage.tsx
│   ├── hooks/
│   │   └── useQuickReplies.ts
│   └── types/
│       └── quick-reply.ts
└── tests/
    └── components/QuickReply.test.tsx
```

**Structure Decision**: Web application structure - ใช้ existing patterns จาก backend (Laravel) และ frontend (React) ที่มีอยู่แล้ว

## Implementation Phases

### Phase 1: Backend Foundation
1. Create migration & model
2. Create controller with CRUD
3. Add policy for Owner-only access
4. Write feature tests

### Phase 2: Frontend Management
1. Create settings page for managing Quick Replies
2. Add hooks for CRUD operations
3. Connect to existing settings navigation

### Phase 3: Chat Integration
1. Add Quick Reply button near input
2. Implement autocomplete on `/` typing
3. Send message using existing sendAgentMessage

## Complexity Tracking

> No violations - straightforward CRUD + UI integration
