#!/usr/bin/env php
<?php
/**
 * Find potential N+1 query patterns in Laravel codebase.
 * Usage: php find_n1_queries.php [directory]
 */

$directory = $argv[1] ?? 'app';

echo "🔍 Scanning for N+1 Query Patterns\n";
echo "==================================\n\n";

$patterns = [
    // Accessing relationship in loop
    '/foreach\s*\([^)]+\s+as\s+\$(\w+)\)[^}]+\$\1->(\w+)/' => 'Relationship access in foreach - potential N+1',

    // Model::all() without eager loading
    '/(\w+)::all\(\)/' => 'Using ::all() - consider eager loading if relationships used',

    // Missing with() before get/first
    '/->where[^;]+->get\(\)(?!\s*;[^;]*->with)/' => 'Query without eager loading - check if relationships accessed',

    // Lazy loading in blade
    '/@foreach[^@]+\$\w+->\w+[^@]*@endforeach/' => 'Relationship in blade foreach - ensure eager loaded',
];

$issues = [];

// Scan PHP files
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    $relativePath = str_replace(getcwd() . '/', '', $file->getPathname());

    foreach ($patterns as $pattern => $description) {
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $issues[] = [
                    'file' => $relativePath,
                    'line' => $line,
                    'pattern' => $description,
                    'code' => trim($match[0]),
                ];
            }
        }
    }
}

// Report findings
if (empty($issues)) {
    echo "✅ No obvious N+1 patterns found!\n";
    echo "Note: This is a static analysis - runtime profiling recommended.\n";
} else {
    echo "⚠️  Found " . count($issues) . " potential N+1 patterns:\n\n";

    $byFile = [];
    foreach ($issues as $issue) {
        $byFile[$issue['file']][] = $issue;
    }

    foreach ($byFile as $file => $fileIssues) {
        echo "📄 $file\n";
        foreach ($fileIssues as $issue) {
            echo "   Line {$issue['line']}: {$issue['pattern']}\n";
            echo "   Code: " . substr($issue['code'], 0, 60) . "...\n\n";
        }
    }
}

echo "\n💡 Tips:\n";
echo "- Use ->with(['relationship']) for eager loading\n";
echo "- Use ->withCount('relationship') instead of counting in loop\n";
echo "- Enable query logging to detect N+1 at runtime\n";
echo "- Use Laravel Debugbar in development\n";
