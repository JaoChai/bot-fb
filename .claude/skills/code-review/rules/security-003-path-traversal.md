---
id: security-003-path-traversal
title: Path Traversal Prevention
impact: CRITICAL
impactDescription: "Attackers can access files outside intended directory"
category: security
tags: [security, path-traversal, owasp, files]
relatedRules: [backend-003-formrequest]
---

## Why This Matters

Path traversal allows attackers to access files outside the intended directory using `../` sequences, potentially reading sensitive configuration, credentials, or system files.

## Bad Example

```php
// Direct user input in file path
$filename = $request->input('file');
$content = file_get_contents(storage_path("uploads/$filename"));

// Unvalidated download
Route::get('/download/{file}', function ($file) {
    return response()->download(storage_path("documents/$file"));
});
```

**Why it's wrong:**
- `../../../etc/passwd` would escape directory
- No path validation
- Attacker controls file path

## Good Example

```php
// Validate filename, strip path components
$filename = basename($request->input('file'));
$path = storage_path("uploads/$filename");

// Verify file is within allowed directory
$realPath = realpath($path);
$allowedDir = realpath(storage_path('uploads'));

if ($realPath && str_starts_with($realPath, $allowedDir)) {
    return response()->download($realPath);
}

abort(404);

// Better: Use database IDs instead of filenames
Route::get('/download/{document}', function (Document $document) {
    $this->authorize('download', $document);
    return response()->download($document->path);
});
```

**Why it's better:**
- `basename()` strips directory traversal
- `realpath()` resolves to actual path
- Verify path is within allowed directory
- IDs eliminate path manipulation

## Review Checklist

- [ ] User input not directly in file paths
- [ ] `basename()` used on user-provided filenames
- [ ] `realpath()` validates final path location
- [ ] File operations use database IDs when possible
- [ ] Symlinks considered (use `realpath()`)

## Detection

```bash
# File operations with variables
grep -rn "file_get_contents\|file_put_contents\|fopen\|include\|require" --include="*.php" app/ | grep '\$'

# Storage paths with user input
grep -rn "storage_path\|public_path" --include="*.php" app/ | grep '\$request'
```

## Project-Specific Notes

**BotFacebook File Handling:**

```php
// KnowledgeDocumentController - safe file handling
public function download(KnowledgeDocument $document)
{
    $this->authorize('view', $document->bot);

    // Path stored in database, not from user input
    $path = Storage::disk('private')->path($document->file_path);

    if (!file_exists($path)) {
        abort(404);
    }

    return response()->download($path, $document->original_name);
}

// File upload - generate safe filename
$filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
$path = $file->storeAs('documents', $filename, 'private');
```
