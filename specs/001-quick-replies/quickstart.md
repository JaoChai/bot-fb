# Quick Start: Quick Replies

**Feature**: 001-quick-replies
**Date**: 2026-01-03

## Overview

ฟีเจอร์ Quick Replies ช่วยให้ Agent ส่งคำตอบที่ใช้บ่อยได้อย่างรวดเร็ว โดยเลือกจาก list หรือพิมพ์ `/shortcut`

## Key Components

### Backend (Laravel)

| File | Purpose |
|------|---------|
| `app/Models/QuickReply.php` | Eloquent model |
| `app/Http/Controllers/Api/QuickReplyController.php` | CRUD API |
| `app/Policies/QuickReplyPolicy.php` | Owner-only authorization |
| `database/migrations/xxxx_create_quick_replies_table.php` | Database schema |

### Frontend (React)

| File | Purpose |
|------|---------|
| `src/hooks/useQuickReplies.ts` | React Query hooks |
| `src/components/chat/QuickReplyButton.tsx` | Button near message input |
| `src/components/chat/QuickReplyAutocomplete.tsx` | `/shortcut` autocomplete |
| `src/pages/settings/QuickRepliesPage.tsx` | Management UI |

## API Endpoints

```
GET    /api/quick-replies           # List all (filtered by active, category, search)
POST   /api/quick-replies           # Create (Owner only)
GET    /api/quick-replies/{id}      # Get one
PUT    /api/quick-replies/{id}      # Update (Owner only)
DELETE /api/quick-replies/{id}      # Delete (Owner only)
POST   /api/quick-replies/{id}/toggle   # Toggle active (Owner only)
POST   /api/quick-replies/reorder   # Reorder (Owner only)
GET    /api/quick-replies/search?q= # Autocomplete search
```

## Usage Flow

### Agent Using Quick Reply

```
1. Open chat conversation
2. Click Quick Reply button (⚡) near input
   OR type `/` to trigger autocomplete
3. Select Quick Reply from list
4. Message sent to customer immediately
```

### Owner Managing Quick Replies

```
1. Go to Settings > Quick Replies
2. Click "Add" to create new
3. Fill: shortcut, title, content, category
4. Save - available to all team members immediately
```

## Development Commands

```bash
# Backend
cd backend
php artisan migrate                    # Run migration
php artisan test --filter=QuickReply   # Run tests

# Frontend
cd frontend
npm run dev                            # Start dev server
npm run test -- QuickReply             # Run tests
```

## Testing Checklist

- [ ] Create Quick Reply as Owner
- [ ] Try to create as non-Owner (should fail)
- [ ] Use Quick Reply in chat
- [ ] Test `/shortcut` autocomplete
- [ ] Toggle active/inactive
- [ ] Delete Quick Reply
- [ ] Verify shortcut uniqueness validation
