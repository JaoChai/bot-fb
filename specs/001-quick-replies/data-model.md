# Data Model: Quick Replies

**Feature**: 001-quick-replies
**Date**: 2026-01-03

## Entities

### QuickReply

คำตอบสำเร็จรูปที่ Agent ใช้ส่งข้อความไปยังลูกค้าได้อย่างรวดเร็ว

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| id | bigint | PK, auto | Primary key |
| team_id | bigint | FK, required | Team ที่เป็นเจ้าของ |
| shortcut | string(50) | unique per team | คำย่อสำหรับ autocomplete (เช่น `hello`, `thanks`) |
| title | string(100) | required | ชื่อแสดงใน list |
| content | text | required, max 5000 bytes | เนื้อหาที่ส่งไปยังลูกค้า |
| category | string(50) | nullable | หมวดหมู่สำหรับจัดกลุ่ม |
| sort_order | integer | default 0 | ลำดับการแสดง |
| is_active | boolean | default true | สถานะเปิด/ปิด |
| created_by | bigint | FK, required | User ที่สร้าง |
| created_at | timestamp | auto | วันที่สร้าง |
| updated_at | timestamp | auto | วันที่แก้ไขล่าสุด |

### Relationships

```
Team (1) ──────< (N) QuickReply
User (1) ──────< (N) QuickReply (created_by)
```

### Indexes

| Index | Columns | Type | Purpose |
|-------|---------|------|---------|
| quick_replies_team_id_index | team_id | btree | Filter by team |
| quick_replies_team_shortcut_unique | team_id, shortcut | unique | Prevent duplicate shortcuts |
| quick_replies_team_active_sort | team_id, is_active, sort_order | btree | List active sorted |

## Validation Rules

### Shortcut
- Required
- 1-50 characters
- Pattern: `^[a-z0-9_-]+$` (lowercase alphanumeric, underscore, hyphen)
- Unique within team

### Title
- Required
- 1-100 characters

### Content
- Required
- Max 5000 bytes (LINE message limit)
- UTF-8 encoded

### Category
- Optional
- Max 50 characters

### Sort Order
- Integer >= 0
- Default 0

## State Transitions

```
┌─────────┐    toggle     ┌──────────┐
│ Active  │ ◄──────────► │ Inactive │
└─────────┘               └──────────┘
     │                         │
     └─────────┬───────────────┘
               │ delete
               ▼
         ┌──────────┐
         │ Deleted  │
         └──────────┘
```

## Migration SQL (Reference)

```sql
CREATE TABLE quick_replies (
    id BIGSERIAL PRIMARY KEY,
    team_id BIGINT NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    shortcut VARCHAR(50) NOT NULL,
    title VARCHAR(100) NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(50),
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT quick_replies_team_shortcut_unique
        UNIQUE (team_id, shortcut)
);

CREATE INDEX quick_replies_team_id_index
    ON quick_replies(team_id);

CREATE INDEX quick_replies_team_active_sort
    ON quick_replies(team_id, is_active, sort_order);
```
