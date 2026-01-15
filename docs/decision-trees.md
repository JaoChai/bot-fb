# Decision Trees

คู่มือการตัดสินใจเลือก Tools และ Skills

---

## Main Decision Flow

```mermaid
graph TD
    Start{งานประเภทไหน?} -->|Feature ใหม่| FeatureFlow
    Start -->|Bug Fix| BugFlow
    Start -->|Performance| PerfFlow
    Start -->|Debug| DebugFlow

    FeatureFlow{ขนาดงาน?}
    FeatureFlow -->|ใหญ่ >30min| Speckit[/speckit.specify]
    FeatureFlow -->|เล็ก <30min| QuickFix[แก้ตรงๆ]

    BugFlow[Search Memory First] --> Fix[แก้ตาม pattern]

    PerfFlow{ช้าตรงไหน?}
    PerfFlow -->|Frontend| FE[/frontend-dev + /performance]
    PerfFlow -->|Backend| BE[/backend-dev + /performance]
    PerfFlow -->|Database| DB[/database-ops + /performance]

    DebugFlow{Debug อะไร?}
    DebugFlow -->|Production| Deploy[/deployment]
    DebugFlow -->|WebSocket| WS[/webhook-debug]
    DebugFlow -->|Search| RAG[/rag-debug]
```

---

## 1. Feature Development

| Criteria | Action |
|----------|--------|
| แก้ 1 ไฟล์ + <15 นาที | แก้ตรงๆ |
| แก้ 2-3 ไฟล์ + <30 นาที | `/frontend-dev` หรือ `/backend-dev` |
| แก้ >3 ไฟล์ หรือ >30 นาที | `/speckit.specify` |

---

## 2. Bug Fixing

**Always search memory first:**
```
Bug พบ → Search memory → มี pattern? → ใช้ pattern
                       → ไม่มี? → ใช้ skill ที่เหมาะสม
```

| Bug Type | Skill |
|----------|-------|
| UI ไม่ render | `/frontend-dev` |
| API error | `/backend-dev` |
| Query ช้า | `/database-ops` + `/performance` |
| WebSocket ขาด | `/webhook-debug` |
| Search ไม่เจอ | `/rag-debug` |
| Deploy ล้ม | `/deployment` |

---

## 3. Which Skill?

| Task Keywords | Skill |
|--------------|-------|
| "สร้าง component", "ปรับ UI", "styling" | `/frontend-dev` |
| "สร้าง API", "controller", "service" | `/backend-dev` |
| "migration", "query ช้า", "เพิ่ม column" | `/database-ops` |
| "semantic search", "embedding", "ผลลัพธ์ไม่ตรง" | `/rag-debug` |
| "webhook", "message ไม่เข้า", "realtime" | `/webhook-debug` |
| "โหลดช้า", "optimize", "N+1" | `/performance` |
| "review code", "ก่อน commit", "security" | `/code-review` |
| "test", "unit test", "E2E" | `/testing` |
| "deploy", "production error", "Railway" | `/deployment` |
| "prompt", "AI response ไม่ดี" | `/prompt-eng` |

---

## 4. Testing

| After... | Use... |
|----------|--------|
| Frontend edit | `/testing` (UI tests) |
| Backend edit | `/testing` (PHPUnit) |
| Route change | `/code-review` + `/testing` |
| Migration | `/database-ops` |
| Before commit | `/code-review` |

---

## 5. Performance Issues

| Symptom | Skill |
|---------|-------|
| Page load ช้า | `/performance` (bundle analysis) |
| API response ช้า | `/performance` (N+1, queries) |
| Database ช้า | `/database-ops` + `/performance` |

---

## 6. Quick Reference

### "I need to..."

| Need | Action |
|------|--------|
| สร้าง feature ใหม่ | `/speckit.specify` |
| แก้ bug | Search memory → appropriate skill |
| ปรับ UI | `/frontend-dev` |
| แก้ API | `/backend-dev` |
| Query ช้า | `/database-ops` + `/performance` |
| Deploy ล้ม | `/deployment` |
| Review code | `/code-review` |

### "Something is broken..."

| Problem | Skill |
|---------|-------|
| UI ไม่แสดง | `/frontend-dev` |
| API error | `/backend-dev` + Sentry |
| Database error | `/database-ops` + Neon |
| WebSocket ไม่ทำงาน | `/webhook-debug` |
| Search ไม่เจอ | `/rag-debug` |
| Deploy failed | `/deployment` + Railway logs |

---

## Common Patterns

### New Feature
```
1. /speckit.specify "feature description"
2. /speckit.plan
3. /speckit.implement
4. /code-review
5. /commit-push-pr
```

### Bug Fix
```
1. Search memory first
2. Use appropriate skill
3. Fix
4. /code-review
5. /commit-push-pr
```

### Performance Issue
```
1. Identify bottleneck
2. /performance
3. Optimize
4. Measure
5. /commit-push-pr
```

---

## Tips

1. **Skills auto-trigger** - แค่บอกสิ่งที่ต้องการ Claude จะเลือก skill เอง
2. **Memory first for bugs** - เคยแก้แล้วอาจมี solution
3. **Speckit for big features** - >30 นาที = ควรใช้ Speckit
4. **Multiple skills OK** - เช่น: `/backend-dev` → `/testing` → `/code-review`
