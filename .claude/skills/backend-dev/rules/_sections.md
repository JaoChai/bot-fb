# Rule Sections & Decision Trees

## Section Organization

Rules are organized by priority within each section. CRITICAL rules should be checked first.

### Priority Order

1. **CRITICAL** - Must fix immediately, blocks deployment
2. **HIGH** - Should fix before PR merge
3. **MEDIUM** - Fix when touching related code
4. **LOW** - Nice to have, fix when convenient

---

## Decision Trees

### When to Create Service vs Keep in Controller

```
Do you have business logic?
├── Simple CRUD only?
│   └── Keep in controller (thin controller pattern)
│
├── Multiple steps or complex logic?
│   └── Extract to Service
│       ├── Use DB::transaction() for multiple writes
│       ├── Return model or DTO
│       └── Inject dependencies via constructor
│
├── Reused across multiple controllers?
│   └── Definitely use Service
│
└── Working with external APIs?
    └── Create dedicated Service (LINEService, TelegramService)
```

### When to Use Job vs Sync Processing

```
Should this run in the background?
├── Takes > 1 second?
│   └── Use Job (async)
│
├── Can fail and retry?
│   ├── YES → Use Job with $tries and $backoff
│   └── NO → Sync processing
│
├── User waiting for response?
│   ├── YES, needs immediate feedback → Sync
│   ├── YES, can show pending state → Job + WebSocket
│   └── NO → Job
│
└── Processing webhook/external event?
    └── Always use Job (respond quickly to webhook)
```

### API Response Design

```
What are you returning?
├── Single resource?
│   └── return new ResourceClass($model);
│
├── Collection/list?
│   └── return ResourceClass::collection($models)
│           ->additional(['meta' => ['timestamp' => now()]]);
│
├── Paginated list?
│   └── return ResourceClass::collection($models->paginate(20));
│
├── Empty success (DELETE)?
│   └── return response()->noContent(); // 204
│
├── Created resource?
│   └── return new ResourceClass($model); // 201 (automatic)
│
└── Error?
    ├── Validation → 422 (automatic from FormRequest)
    ├── Not found → 404 (automatic from findOrFail)
    ├── Unauthorized → 401/403
    └── Server error → 500
```

### Where to Put Validation Logic

```
What are you validating?
├── Request input?
│   └── FormRequest class
│       └── app/Http/Requests/{Resource}/{Action}Request.php
│
├── Business rules (not just input format)?
│   ├── Simple check → Service method
│   └── Complex rules → Validator in Service
│
├── Authorization?
│   ├── Resource access → Policy
│   └── Feature access → Gate
│
└── Database constraints?
    └── Migration (foreign keys, unique, not null)
```

### Eager Loading Decision

```
Are you accessing relationships?
├── In a loop or collection?
│   └── MUST use ->with() (prevent N+1)
│
├── Conditionally accessed?
│   └── Use ->when() with eager loading
│       $query->when($includeRelation, fn($q) => $q->with('relation'))
│
├── Counting only?
│   └── Use ->withCount('relation')
│
├── Nested relationships?
│   └── Use dot notation: ->with('relation.nested')
│
└── Large dataset?
    └── Consider:
        ├── ->select() to limit columns
        ├── ->cursorPaginate() for memory efficiency
        └── Chunking for batch processing
```

---

## Section Index

### Gotchas (gotcha-*)
| Rule | Impact | Description |
|------|--------|-------------|
| gotcha-001 | CRITICAL | config() null coalesce pattern |
| gotcha-002 | CRITICAL | N+1 query detection |
| gotcha-003 | CRITICAL | Race condition with DB locks |
| gotcha-004 | HIGH | Env vs Config usage |
| gotcha-005 | HIGH | Model mass assignment |

### Laravel Core (laravel-*)
| Rule | Impact | Description |
|------|--------|-------------|
| laravel-001 | HIGH | Thin controller pattern |
| laravel-002 | MEDIUM | Service provider registration |
| laravel-003 | CRITICAL | Service layer pattern |
| laravel-004 | HIGH | FormRequest validation |
| laravel-005 | HIGH | API Resource transformation |
| laravel-006 | MEDIUM | Config file organization |
| laravel-007 | MEDIUM | Route organization |
| laravel-008 | MEDIUM | Middleware usage |

### Eloquent (eloquent-*)
| Rule | Impact | Description |
|------|--------|-------------|
| eloquent-001 | CRITICAL | Eager loading with relationships |
| eloquent-002 | HIGH | Query scopes |
| eloquent-003 | HIGH | Model casts |
| eloquent-004 | MEDIUM | Soft deletes |
| eloquent-005 | MEDIUM | Accessors and mutators |
| eloquent-006 | MEDIUM | Model events |
| eloquent-007 | LOW | Factory patterns |

### API Design (api-*)
| Rule | Impact | Description |
|------|--------|-------------|
| api-001 | CRITICAL | Standard response format |
| api-002 | HIGH | RESTful naming conventions |
| api-003 | HIGH | HTTP status codes |
| api-004 | HIGH | Pagination |
| api-005 | MEDIUM | Filtering and sorting |
| api-006 | MEDIUM | API versioning |
| api-007 | MEDIUM | Rate limiting |
| api-008 | LOW | Documentation |

### Queue Jobs (job-*)
| Rule | Impact | Description |
|------|--------|-------------|
| job-001 | CRITICAL | Job retry configuration |
| job-002 | HIGH | Failed job handling |
| job-003 | HIGH | Job dispatching patterns |
| job-004 | MEDIUM | Job chaining |

### Events & Broadcasting (event-*)
| Rule | Impact | Description |
|------|--------|-------------|
| event-001 | HIGH | Event dispatching |
| event-002 | HIGH | Broadcasting with Reverb |
| event-003 | MEDIUM | Listener queuing |

### Authorization (policy-*)
| Rule | Impact | Description |
|------|--------|-------------|
| policy-001 | CRITICAL | Policy authorization |
| policy-002 | HIGH | Controller authorize calls |
| policy-003 | MEDIUM | Gate definitions |

### Security (security-*)
| Rule | Impact | Description |
|------|--------|-------------|
| security-001 | CRITICAL | Input validation |
| security-002 | CRITICAL | SQL injection prevention |
| security-003 | HIGH | Mass assignment protection |
| security-004 | HIGH | Sensitive data handling |
