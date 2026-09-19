<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The OpenRouter API key has one source (OPENROUTER_API_KEY) and one reader
 * (App\Services\OpenRouterCredentials). This test keeps it that way.
 */
class OpenRouterKeySingleSourceTest extends TestCase
{
    /** @return array<string, string> relative path => contents */
    private function appFiles(): array
    {
        $root = dirname(__DIR__, 2).'/app';
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    public function test_only_openrouter_credentials_reads_the_config_key(): void
    {
        $readers = array_keys(array_filter(
            $this->appFiles(),
            fn (string $code) => str_contains($code, 'services.openrouter.api_key'),
        ));

        // ConfigHelper.php only mentions the key inside a usage docblock.
        $readers = array_values(array_diff($readers, ['Helpers/ConfigHelper.php']));

        $this->assertSame(['Services/OpenRouterCredentials.php'], $readers);
    }

    public function test_no_per_user_or_threaded_key_remains(): void
    {
        $offenders = [];
        foreach ($this->appFiles() as $path => $code) {
            foreach (['getOpenRouterApiKey', 'apiKeyOverride', 'withApiKey(', 'getApiKeyForBot'] as $needle) {
                if (str_contains($code, $needle)) {
                    $offenders[] = "{$path}: {$needle}";
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
