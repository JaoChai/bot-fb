---
id: security-002-sql-injection
title: SQL Injection Prevention
impact: CRITICAL
impactDescription: "Prevents attackers from executing arbitrary SQL and accessing/destroying data"
category: security
tags: [security, sql, injection, database]
relatedRules: [security-001-input-validation]
---

## Why This Matters

SQL injection allows attackers to execute arbitrary SQL queries, potentially reading all data, modifying records, or deleting the entire database. Always use parameterized queries - never concatenate user input into SQL strings.

## Bad Example

```php
// Problem: Direct string interpolation = SQL injection
$name = $request->input('name');
$users = DB::select("SELECT * FROM users WHERE name = '$name'");

// Attacker input: "' OR '1'='1"
// Resulting query: SELECT * FROM users WHERE name = '' OR '1'='1'
// Result: Returns ALL users!

// Problem: Using whereRaw without parameters
$search = $request->input('search');
$results = Bot::whereRaw("name LIKE '%$search%'")->get();

// Attacker input: "'; DROP TABLE bots; --"
// Could delete entire table!
```

**Why it's wrong:**
- User input directly in SQL string
- Attacker can inject malicious SQL
- Can read, modify, or delete any data
- Can bypass authentication
- Can escalate privileges

## Good Example

```php
// Solution 1: Query builder (automatic parameterization)
$bots = Bot::where('name', $request->input('name'))->get();

// Solution 2: Parameterized raw queries
$name = $request->input('name');
$users = DB::select("SELECT * FROM users WHERE name = ?", [$name]);

// Solution 3: Named parameters
$users = DB::select(
    "SELECT * FROM users WHERE name = :name AND status = :status",
    ['name' => $name, 'status' => 'active']
);

// Solution 4: WhereRaw with parameter binding
$search = $request->input('search');
$results = Bot::whereRaw("name ILIKE ?", ["%{$search}%"])->get();

// Solution 5: Using the LIKE helper
$results = Bot::where('name', 'like', "%{$search}%")->get();
```

**Why it's better:**
- Parameters are escaped automatically
- SQL structure fixed, only data changes
- Impossible to inject SQL commands
- Query plan can be cached (performance)

## Project-Specific Notes

**BotFacebook Audit Command:**
```bash
# Find potential SQL injection vulnerabilities
grep -rn "DB::raw\|whereRaw\|selectRaw" app/ --include="*.php"

# Look for string interpolation in queries
grep -rn '"\$' app/ --include="*.php" | grep -i "select\|where\|update\|delete"
```

**Safe Patterns in Use:**
```php
// SemanticSearchService - parameterized vector query
$results = DB::select("
    SELECT id, content,
           1 - (embedding <=> ?) as similarity
    FROM knowledge_chunks
    WHERE knowledge_base_id = ?
      AND 1 - (embedding <=> ?) > ?
    ORDER BY embedding <=> ?
    LIMIT ?
", [$embedding, $kbId, $embedding, $threshold, $embedding, $limit]);
```

**When to Review:**
- Any use of `DB::raw()`, `whereRaw()`, `selectRaw()`
- Any SQL string with variables
- Dynamic table or column names (use whitelist)

## References

- [OWASP SQL Injection](https://owasp.org/www-community/attacks/SQL_Injection)
- [Laravel Database Security](https://laravel.com/docs/database)
