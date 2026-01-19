---
id: api-004-pagination
title: API Pagination
impact: HIGH
impactDescription: "Prevents memory exhaustion and ensures consistent performance with large datasets"
category: api
tags: [api, pagination, performance, database]
relatedRules: [api-001-response-format, eloquent-001-eager-loading]
---

## Why This Matters

Returning all records without pagination causes memory exhaustion, slow responses, and database strain. Pagination keeps responses fast and memory-efficient regardless of dataset size.

## Bad Example

```php
// Problem: Returns ALL records
public function index()
{
    return Bot::all(); // 100,000 records = server crash
}

// Problem: Manual pagination
public function index(Request $request)
{
    $page = $request->get('page', 1);
    $perPage = $request->get('limit', 20);

    $bots = Bot::skip(($page - 1) * $perPage)
               ->take($perPage)
               ->get();

    return ['data' => $bots, 'page' => $page]; // No total, no links
}
```

**Why it's wrong:**
- Memory exhaustion with large datasets
- Slow response times
- Missing pagination metadata
- No navigation links
- Inefficient for large offsets

## Good Example

```php
// Solution: Laravel pagination with API Resources
public function index(Request $request)
{
    $bots = Bot::with('settings')
        ->when($request->platform, fn($q) => $q->where('platform', $request->platform))
        ->orderBy('created_at', 'desc')
        ->paginate($request->get('per_page', 20));

    return BotResource::collection($bots);
}

// Response includes metadata:
// {
//   "data": [...],
//   "links": {
//     "first": "...?page=1",
//     "last": "...?page=5",
//     "prev": null,
//     "next": "...?page=2"
//   },
//   "meta": {
//     "current_page": 1,
//     "from": 1,
//     "last_page": 5,
//     "per_page": 20,
//     "to": 20,
//     "total": 100
//   }
// }
```

**Why it's better:**
- Memory-efficient
- Consistent response times
- Full pagination metadata
- Navigation links
- Works with API Resources

## Project-Specific Notes

**BotFacebook Pagination Standards:**

```php
// Standard pagination
$items = Model::paginate(20);

// With custom per_page
$items = Model::paginate($request->get('per_page', 20));

// Cursor pagination (more efficient for large datasets)
$messages = Message::orderBy('id')
    ->cursorPaginate(50);

// Simple pagination (no total count - faster)
$items = Model::simplePaginate(20);
```

**When to Use What:**
| Method | Use Case |
|--------|----------|
| `paginate()` | Need total count, page numbers |
| `simplePaginate()` | Only need next/prev, faster |
| `cursorPaginate()` | Large datasets, real-time data |

**Query Parameters:**
```
GET /api/v1/bots?page=2&per_page=20
GET /api/v1/messages?cursor=eyJpZCI6MTB9
```

**Max Per Page Limit:**
```php
$perPage = min($request->get('per_page', 20), 100); // Max 100
```

## References

- [Laravel Pagination](https://laravel.com/docs/pagination)
- [Cursor Pagination](https://laravel.com/docs/pagination#cursor-pagination)
