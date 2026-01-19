---
id: security-001-sql-injection
title: SQL Injection Prevention
impact: CRITICAL
impactDescription: "Attackers can read/modify/delete database data"
category: security
tags: [security, sql, owasp, injection]
relatedRules: [backend-003-formrequest]
---

## Why This Matters

SQL injection allows attackers to manipulate database queries, potentially accessing sensitive data, modifying records, or deleting entire tables. It's #1 on OWASP Top 10 for a reason.

## Bad Example

```php
// Direct string concatenation
$users = DB::select("SELECT * FROM users WHERE email = '$email'");

// Raw queries with user input
$results = DB::raw("SELECT * FROM bots WHERE name LIKE '%$search%'");

// Unescaped whereRaw
$bots = Bot::whereRaw("name = '$name'")->get();
```

**Why it's wrong:**
- User input directly in SQL string
- No parameterization
- Attacker can inject: `' OR '1'='1`

## Good Example

```php
// Parameterized queries
$users = DB::select("SELECT * FROM users WHERE email = ?", [$email]);

// Eloquent (auto-escaped)
$results = Bot::where('name', 'LIKE', "%{$search}%")->get();

// Named bindings
$bots = DB::select(
    "SELECT * FROM bots WHERE user_id = :user_id",
    ['user_id' => $userId]
);

// whereRaw with bindings
$bots = Bot::whereRaw("name = ?", [$name])->get();
```

**Why it's better:**
- Parameters are escaped
- Query structure separate from data
- Database driver handles escaping

## Review Checklist

- [ ] No string concatenation in SQL queries
- [ ] All `DB::raw()` uses parameter binding
- [ ] All `whereRaw()`/`selectRaw()` uses `?` placeholders
- [ ] User input never directly in query string
- [ ] LIKE queries use bindings, not interpolation

## Detection

```bash
# Find potential SQL injection
grep -rn "DB::raw\|whereRaw\|selectRaw\|havingRaw\|orderByRaw" --include="*.php" app/

# Check for string interpolation in queries
grep -rn '"\$\|'"'"'\$' --include="*.php" app/ | grep -i "select\|where\|from"
```

## Project-Specific Notes

**BotFacebook Safe Patterns:**

```php
// SemanticSearchService - vector search with bindings
$results = DB::select("
    SELECT id, content, 1 - (embedding <=> ?) as similarity
    FROM knowledge_documents
    WHERE bot_id = ?
    ORDER BY embedding <=> ?
    LIMIT ?
", [$embedding, $botId, $embedding, $limit]);

// HybridSearchService - always use bindings
$keywords = collect($terms)->map(fn($t) => "%{$t}%");
$query->where(function ($q) use ($keywords) {
    foreach ($keywords as $keyword) {
        $q->orWhere('content', 'ILIKE', $keyword);
    }
});
```
