---
id: api-005-filtering-sorting
title: Filtering and Sorting
impact: MEDIUM
impactDescription: "Enables flexible data retrieval without creating multiple endpoints"
category: api
tags: [api, filter, sort, query-params]
relatedRules: [api-004-pagination, eloquent-002-query-scopes]
---

## Why This Matters

Filtering and sorting via query parameters makes APIs flexible without creating separate endpoints for each use case. Clients can request exactly the data they need.

## Bad Example

```php
// Problem: Multiple endpoints for each filter combination
Route::get('/bots', [BotController::class, 'index']);
Route::get('/bots/active', [BotController::class, 'active']);
Route::get('/bots/line', [BotController::class, 'line']);
Route::get('/bots/active-line', [BotController::class, 'activeLine']); // Explosion!
```

**Why it's wrong:**
- Endpoint explosion
- Not flexible
- Hard to maintain
- Can't combine filters

## Good Example

```php
// Single endpoint with query params
// GET /api/v1/bots?platform=line&active=true&sort=-created_at

public function index(Request $request)
{
    $query = Bot::query()->forUser(auth()->user());

    // Platform filter
    if ($platform = $request->query('platform')) {
        $query->whereIn('platform', explode(',', $platform));
    }

    // Active filter
    if ($request->has('active')) {
        $query->where('is_active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
    }

    // Search
    if ($search = $request->query('search')) {
        $query->where('name', 'ilike', "%{$search}%");
    }

    // Date range
    if ($from = $request->query('created_from')) {
        $query->where('created_at', '>=', $from);
    }
    if ($to = $request->query('created_to')) {
        $query->where('created_at', '<=', $to);
    }

    // Sorting: sort=-created_at,name (- = desc)
    $sort = $request->query('sort', '-created_at');
    foreach (explode(',', $sort) as $field) {
        $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
        $field = ltrim($field, '-');

        // Whitelist allowed sort fields
        if (in_array($field, ['name', 'created_at', 'platform', 'is_active'])) {
            $query->orderBy($field, $direction);
        }
    }

    return BotResource::collection($query->paginate());
}
```

**Why it's better:**
- Single flexible endpoint
- Combinable filters
- Consistent interface
- Easy to extend

## Project-Specific Notes

**BotFacebook Filter Conventions:**

```
# Filter operators
?platform=line                 # Exact match
?platform=line,telegram        # IN clause
?search=keyword                # LIKE search
?created_from=2026-01-01       # Date range

# Sorting
?sort=name                     # Ascending
?sort=-created_at              # Descending
?sort=-created_at,name         # Multiple fields

# Combining
?platform=line&active=true&sort=-created_at&page=2
```

**Query Builder Service:**
```php
class QueryBuilder
{
    public static function apply(Builder $query, Request $request, array $filters): Builder
    {
        foreach ($filters as $param => $column) {
            if ($value = $request->query($param)) {
                $query->where($column, $value);
            }
        }
        return $query;
    }
}

// Usage
QueryBuilder::apply($query, $request, [
    'platform' => 'platform',
    'active' => 'is_active',
]);
```

## References

- [REST API Filtering](https://www.moesif.com/blog/technical/api-design/REST-API-Design-Filtering-Sorting-and-Pagination/)
