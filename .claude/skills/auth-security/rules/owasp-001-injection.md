---
id: owasp-001-injection
title: OWASP A03 - Injection Prevention
impact: CRITICAL
impactDescription: "Attackers can read, modify, or delete database data"
category: owasp
tags: [owasp, injection, sql, security]
relatedRules: [owasp-002-broken-auth]
---

## Why This Matters

Injection flaws occur when untrusted data is sent to an interpreter as part of a command or query. SQL injection can expose entire databases.

## Threat Model

**Attack Vector:** User input directly in SQL queries
**Impact:** Data theft, data destruction, admin access
**Likelihood:** High - easily automated and exploited

## Bad Example

```php
// Direct string concatenation
$users = DB::select("SELECT * FROM users WHERE email = '$email'");

// whereRaw without bindings
$bots = Bot::whereRaw("name = '$name'")->get();

// Order by user input
$bots = Bot::orderByRaw($request->sort)->get();

// Dynamic table name
$table = $request->table;
$data = DB::select("SELECT * FROM $table");
```

**Why it's vulnerable:**
- Input: `' OR '1'='1` returns all rows
- Input: `'; DROP TABLE users; --` deletes data
- No parameter escaping

## Good Example

```php
// Eloquent (auto-escaped)
$users = User::where('email', $email)->get();

// Query builder with bindings
$users = DB::select("SELECT * FROM users WHERE email = ?", [$email]);

// whereRaw with bindings
$bots = Bot::whereRaw("name = ?", [$name])->get();

// Whitelist for sort columns
$allowed = ['name', 'created_at', 'updated_at'];
$sort = in_array($request->sort, $allowed) ? $request->sort : 'created_at';
$bots = Bot::orderBy($sort)->get();

// Never use dynamic table names from user input
```

**Why it's secure:**
- Parameters escaped by database driver
- Query structure separate from data
- Whitelist for dynamic values

## Audit Command

```bash
# Find potential SQL injection
grep -rn "DB::raw\|whereRaw\|selectRaw\|orderByRaw\|havingRaw" --include="*.php" app/

# Check for string concatenation in queries
grep -rn '"\$\|'"'"'\$' --include="*.php" app/ | grep -i "select\|where\|from"
```

## Project-Specific Notes

**BotFacebook Safe Query Patterns:**

```php
// Vector search with bindings
$results = DB::select("
    SELECT id, content, 1 - (embedding <=> ?) as similarity
    FROM knowledge_documents
    WHERE bot_id = ?
    AND similarity > ?
    ORDER BY similarity DESC
    LIMIT ?
", [$embedding, $botId, $threshold, $limit]);

// Search with ILIKE (case-insensitive)
$documents = KnowledgeDocument::where('bot_id', $botId)
    ->where('content', 'ILIKE', "%{$search}%")
    ->get();
```
