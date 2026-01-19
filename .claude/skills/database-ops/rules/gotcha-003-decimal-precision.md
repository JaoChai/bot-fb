---
id: gotcha-003-decimal-precision
title: Decimal Precision Loss with Float
impact: HIGH
impactDescription: "Float columns lose precision on money/exact values"
category: gotcha
tags: [gotcha, decimal, float, precision, money]
relatedRules: [migration-010-change-column-type]
---

## Why This Matters

Float/double types use binary representation and cannot exactly represent many decimal values. $19.99 might become $19.990000000001. For money, metrics, or any exact values, use decimal/numeric.

## Bad Example

```php
// Problem: Using float for money
Schema::create('transactions', function (Blueprint $table) {
    $table->float('amount'); // Loses precision!
});

// $transaction->amount = 19.99;
// Stored as: 19.990000000000001
// Sum errors compound!
```

**Why it's wrong:**
- Binary floating point is inexact
- Errors compound in calculations
- Audits fail due to penny differences

## Good Example

```php
// Use decimal for exact values
Schema::create('transactions', function (Blueprint $table) {
    $table->decimal('amount', 10, 2); // Exact to 2 decimal places
});

// For high precision (costs, tokens)
Schema::create('usage', function (Blueprint $table) {
    $table->decimal('cost', 19, 8); // 8 decimal places for tiny amounts
    $table->decimal('tokens', 15, 0); // Integer-like but allows large values
});
```

**Why it's better:**
- Exact decimal representation
- No floating point errors
- Reliable calculations

## Project-Specific Notes

**BotFacebook Decimal Usage:**

| Column | Type | Precision |
|--------|------|-----------|
| amount | decimal(10,2) | Money |
| cost_per_token | decimal(19,8) | Tiny costs |
| token_count | decimal(15,0) | Large integers |
| similarity_score | float | OK for approximations |

```php
// Model cast for decimal
protected $casts = [
    'amount' => 'decimal:2',
    'cost' => 'decimal:8',
];
```
