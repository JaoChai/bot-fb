<?php

namespace App\Services\Health;

/**
 * File-backed snapshot store for the realtime health check.
 *
 * Deliberately NOT Redis: the whole point of the snapshot is to survive a
 * dead Redis (the 24-25 Oct incident). The scheduler process (root in the
 * container) writes it; php-fpm (www-data) reads it for /api/health/realtime.
 * Writes are atomic (tmp file + rename) so a reader never sees a half file.
 */
class RealtimeHealthSnapshotStore
{
    public function __construct(
        private readonly ?string $path = null,
    ) {}

    public function path(): string
    {
        return $this->path ?? storage_path('framework/health/realtime-snapshot.json');
    }

    /**
     * @return array<string, mixed>|null null when no snapshot exists or the
     *                                   file is unreadable/corrupt
     */
    public function read(): ?array
    {
        try {
            if (! is_file($this->path())) {
                return null;
            }

            $decoded = json_decode((string) file_get_contents($this->path()), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function write(array $snapshot): void
    {
        $path = $this->path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $tmp = $dir.'/realtime-snapshot-'.uniqid().'.tmp';

        if (@file_put_contents($tmp, json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            return;
        }

        @chmod($tmp, 0666);
        @rename($tmp, $path);
    }
}
