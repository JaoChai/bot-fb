---
id: security-004-command-injection
title: Command Injection Prevention
impact: CRITICAL
impactDescription: "Attackers can execute arbitrary system commands"
category: security
tags: [security, command-injection, owasp, shell]
relatedRules: [backend-003-formrequest]
---

## Why This Matters

Command injection allows attackers to execute arbitrary system commands on the server, potentially gaining full system access, exfiltrating data, or compromising the entire infrastructure.

## Bad Example

```php
// Direct user input in shell command
$filename = $request->input('file');
exec("pdftotext $filename output.txt");

// String interpolation in command
$domain = $request->input('domain');
shell_exec("dig $domain");

// System call with user data
system("convert {$imagePath} thumbnail.jpg");
```

**Why it's wrong:**
- Input like `; rm -rf /` would execute
- Backticks allow command substitution
- Attacker controls command arguments

## Good Example

```php
// Use Process with argument array (no shell)
use Symfony\Component\Process\Process;

$process = new Process(['pdftotext', $filename, 'output.txt']);
$process->run();

// Escape shell arguments
$safeFilename = escapeshellarg($filename);
exec("pdftotext $safeFilename output.txt");

// Better: Avoid shell commands, use PHP functions
$content = file_get_contents($pdfPath);
// Or use PHP PDF library like TCPDF, FPDI

// Whitelist allowed values
$allowedDomains = ['example.com', 'test.com'];
if (!in_array($domain, $allowedDomains)) {
    abort(400, 'Invalid domain');
}
```

**Why it's better:**
- Process class doesn't invoke shell
- `escapeshellarg()` prevents injection
- Whitelisting limits valid inputs
- PHP functions safer than shell commands

## Review Checklist

- [ ] No `exec()`, `shell_exec()`, `system()`, `passthru()` with user input
- [ ] `escapeshellarg()` used on all shell arguments
- [ ] Process class used instead of shell functions
- [ ] Consider PHP alternatives to shell commands
- [ ] Whitelist valid values when possible

## Detection

```bash
# Shell execution functions
grep -rn "exec(\|shell_exec(\|system(\|passthru(\|popen(\|proc_open(" --include="*.php" app/

# Backtick execution
grep -rn '`\$' --include="*.php" app/
```

## Project-Specific Notes

**BotFacebook Command Safety:**

```php
// If shell commands needed, use Process
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

public function convertDocument(string $path): string
{
    // Validate path first
    if (!file_exists($path)) {
        throw new \InvalidArgumentException('File not found');
    }

    $outputPath = storage_path('converted/' . Str::uuid() . '.txt');

    $process = new Process([
        'pdftotext',
        '-layout',
        $path,
        $outputPath
    ]);

    $process->setTimeout(30);
    $process->run();

    if (!$process->isSuccessful()) {
        throw new ProcessFailedException($process);
    }

    return $outputPath;
}
```
