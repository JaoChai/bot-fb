# Code Review Decision Trees & Checklists

Quick reference for code review decisions and comprehensive checklists.

---

## Pre-Review Checklist

Before starting detailed review:

- [ ] **Scope**: Changes match PR description?
- [ ] **Tests**: New tests for new functionality?
- [ ] **Files**: No unrelated changes included?
- [ ] **Size**: PR is reviewable (<400 lines)?

---

## Security Review Decision Tree

```
Is user input involved?
├─ Yes
│  ├─ Goes to database? → Check SQL injection (security-001)
│  ├─ Displayed in UI? → Check XSS (security-002)
│  ├─ Used in file path? → Check path traversal (security-003)
│  └─ Used in command? → Check command injection (security-004)
└─ No
   ├─ Authentication involved? → Check auth bypass (security-005)
   └─ Sensitive data handled? → Check exposure (security-006)
```

---

## Performance Review Decision Tree

```
Is there a database query?
├─ Yes
│  ├─ Inside loop? → N+1 problem (perf-001)
│  ├─ No index? → Missing index (perf-002)
│  ├─ SELECT *? → Over-fetching (perf-003)
│  └─ Missing pagination? → Memory issue (perf-004)
└─ No
   └─ Large computation in render? → Memo needed (perf-005)
```

---

## API Design Decision Tree

```
Is this a new endpoint?
├─ Yes
│  ├─ Resource-based URL? → Check naming (api-001)
│  ├─ Proper HTTP method? → Check verbs (api-002)
│  ├─ Input validated? → Check validation (api-003)
│  ├─ Standard response? → Check envelope (api-004)
│  └─ Error handling? → Check error format (api-005)
└─ No (modification)
   └─ Breaking change? → Version bump needed
```

---

## Laravel Code Quality Tree

```
Is it a Controller method?
├─ Yes
│  ├─ >20 lines? → Extract to service (backend-001)
│  ├─ Direct DB query? → Use repository/service (backend-002)
│  ├─ Manual validation? → Use FormRequest (backend-003)
│  └─ Array response? → Use API Resource (backend-004)
└─ No
   ├─ Is it a Service?
   │  ├─ Too many dependencies? → Split service (backend-005)
   │  └─ DB in constructor? → Lazy load (backend-006)
   └─ Is it a Model?
      ├─ Business logic? → Move to service (backend-007)
      └─ Missing relationship? → Add relation (backend-008)
```

---

## React Code Quality Tree

```
Is it a Component?
├─ Yes
│  ├─ >150 lines? → Split components (frontend-001)
│  ├─ Complex state? → Extract hook (frontend-002)
│  ├─ Prop drilling >2? → Use context/store (frontend-003)
│  └─ Missing key? → Add unique key (frontend-004)
└─ No
   ├─ Is it a Hook?
   │  ├─ Missing deps? → Fix dependency array (frontend-005)
   │  ├─ Object in deps? → Memoize (frontend-006)
   │  └─ Side effect cleanup? → Add cleanup (frontend-007)
   └─ Is it API call?
      └─ No loading/error? → Handle states (frontend-008)
```

---

## Quick Review Commands

### Security Checks
```bash
# SQL Injection
grep -rn "DB::raw\|whereRaw\|selectRaw" --include="*.php" app/

# XSS
grep -rn "dangerouslySetInnerHTML\|{!! \$" --include="*.tsx" --include="*.blade.php" .

# Hardcoded secrets
grep -rn "password\|secret\|api_key\|token" --include="*.php" --include="*.ts" . | grep -v "test"
```

### Performance Checks
```bash
# N+1 potential
grep -rn "foreach.*->.*->" --include="*.php" app/

# Missing eager loading
grep -rn "public function\|protected function" --include="*.php" app/Http/Controllers/ | head -20

# Large queries
grep -rn "::all()\|->get()" --include="*.php" app/
```

### Code Quality Checks
```bash
# Fat controllers
wc -l app/Http/Controllers/*/*.php | sort -n | tail -10

# Long functions
grep -n "public function\|private function" --include="*.php" app/ | head -20

# Console.log left in
grep -rn "console.log\|dd(\|dump(" --include="*.tsx" --include="*.php" .
```

---

## Review Response Templates

### Requesting Changes
```markdown
**Issue**: [Category] [Title]
**Location**: `file.php:123`
**Problem**: [Description]
**Suggestion**: [How to fix]
**Reference**: See rule [rule-id]
```

### Approving with Notes
```markdown
LGTM with minor suggestions:
- [ ] Consider: [optional improvement]
- [ ] Nice to have: [future enhancement]
```

---

## Rule Index by Priority

### CRITICAL (Block Merge)
| Rule | Title |
|------|-------|
| security-001 | SQL Injection Prevention |
| security-002 | XSS Prevention |
| security-003 | Path Traversal Prevention |
| security-004 | Command Injection Prevention |

### HIGH (Should Fix)
| Rule | Title |
|------|-------|
| security-005 | Authentication Bypass |
| security-006 | Sensitive Data Exposure |
| perf-001 | N+1 Query Detection |
| backend-001 | Thin Controller Pattern |
| api-001 | RESTful Naming |

### MEDIUM (Recommend)
| Rule | Title |
|------|-------|
| backend-002 through 008 | Laravel patterns |
| frontend-001 through 008 | React patterns |
| perf-002 through 005 | Performance patterns |
| api-002 through 005 | API patterns |
